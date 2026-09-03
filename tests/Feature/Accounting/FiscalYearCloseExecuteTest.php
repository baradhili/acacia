<?php

namespace Tests\Feature\Accounting;

use App\Models\Client;
use App\Models\FiscalPeriod;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Scopes\EntityScope;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearCloseExecuteTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected FiscalYearService $service;

    protected Account $bank;

    protected Account $revenue;

    protected Account $expense;

    protected Account $retainedEarnings;

    protected User $requester;

    protected User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(IFRSSeeder::class);

        $this->entity = Entity::first();
        $this->service = new FiscalYearService;
        $this->bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $this->revenue = Account::where('code', 4100)->where('entity_id', $this->entity->id)->first();
        $this->expense = Account::where('code', 5100)->where('entity_id', $this->entity->id)->first();
        $this->retainedEarnings = Account::where('code', 3200)->where('entity_id', $this->entity->id)->first();

        $this->requester = User::where('email', 'admin@example.com')->first();
        $this->approver = User::factory()->create();
        $this->approver->assignRole('accountant');
        $this->actingAs($this->requester);
    }

    protected function postJournal(string $date, Account $main, bool $credited, array $lines, ?string $reference = null): JournalEntry
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $je = new JournalEntry([
            'transaction_date' => Carbon::parse($date),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $this->entity->id,
            'narration' => 'Test entry',
            'reference' => $reference,
        ]);

        foreach ($lines as [$account, $amount]) {
            $je->addLineItem(LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $this->entity->id,
            ]));
        }

        $je->post();

        return $je;
    }

    protected function closableYear(): int
    {
        return $this->service->currentYear($this->entity) - 1;
    }

    /**
     * Cumulative as-at balance (epoch + opening balances), debit-positive —
     * the same convention FiscalYearService and the reports use.
     */
    protected function balance(Account $account): float
    {
        return OpeningBalances::balanceAt($account, $this->entity, now()->copy()->endOfDay());
    }

    protected function approvedRecord(int $year): FiscalYearClose
    {
        $this->service->submit($this->entity, $year, $this->requester->id);
        $this->service->approve($this->entity, $year, $this->approver);

        return $this->service->closeRecord($this->entity, $year);
    }

    public function test_close_posts_closing_entries_and_zeroes_pnl(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1).'-01-10', $this->bank, true, [[$this->expense, 4000]]);

        $record = $this->approvedRecord($year);
        $this->service->close($this->entity, $year);

        $record->refresh();
        $this->assertTrue($record->isClosed());
        $this->assertCount(2, $record->closing_transaction_ids);
        $this->assertEquals(6000.0, $record->trial_totals['net_to_retained_earnings']);

        // P&L accounts closed out; the profit lives in Retained Earnings
        // (credit balance = negative, debit-positive convention).
        $this->assertEquals(0.0, round($this->balance($this->revenue), 2));
        $this->assertEquals(0.0, round($this->balance($this->expense), 2));
        $this->assertEquals(-6000.0, round($this->balance($this->retainedEarnings), 2));

        // Period sealed, next FY ensured OPEN, app periods locked.
        $this->assertTrue($this->service->isClosed($this->entity, $year));
        $next = $this->service->reportingPeriod($this->entity, $year + 1);
        $this->assertEquals(ReportingPeriod::OPEN, $next->status);
        $this->assertTrue(FiscalPeriod::where('year', $year)->where('is_locked', false)->doesntExist());

        // Closing entries carry the FY-CLOSE reference.
        foreach ($record->closing_transaction_ids as $transactionId) {
            $this->assertEquals(
                'FY-CLOSE-'.$year,
                Transaction::withoutGlobalScope(EntityScope::class)->find($transactionId)->reference
            );
        }
    }

    public function test_close_without_approval_is_rejected_unless_forced(): void
    {
        $year = $this->closableYear();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no approved close request');

        $this->service->close($this->entity, $year);
    }

    public function test_force_closes_without_the_workflow(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 1000]]);

        $record = $this->service->close($this->entity, $year, force: true);

        $this->assertTrue($record->isClosed());
        $this->assertTrue($this->service->isClosed($this->entity, $year));
    }

    public function test_close_aborts_on_failed_blocking_checklist_item(): void
    {
        $year = $this->closableYear();

        $client = Client::factory()->create();
        Payment::create([
            'client_id' => $client->id,
            'amount' => 500,
            'payment_date' => $year.'-11-01',
            'payment_method' => 'bank_transfer',
        ]);

        $this->approvedRecord($year);

        try {
            $this->service->close($this->entity, $year);
            $this->fail('close() should have thrown for the unposted payment.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('blocking checklist items failed', $e->getMessage());
        }

        // Nothing was committed — the year is still open with no closing entries.
        $this->assertFalse($this->service->isClosed($this->entity, $year));
        $this->assertEquals(0.0, round($this->balance($this->retainedEarnings), 2));

        // Force overrides the failed checklist.
        $this->service->close($this->entity, $year, force: true);
        $this->assertTrue($this->service->isClosed($this->entity, $year));
    }

    public function test_close_is_idempotent_through_its_guards(): void
    {
        $year = $this->closableYear();
        $this->approvedRecord($year);
        $this->service->close($this->entity, $year);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already closed');

        $this->service->close($this->entity, $year);
    }

    public function test_close_rejects_current_year(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has not ended yet');

        $this->service->close($this->entity, $this->service->currentYear($this->entity), force: true);
    }

    public function test_close_with_no_pnl_activity_posts_no_entries(): void
    {
        $year = $this->closableYear();
        $this->approvedRecord($year);

        $record = $this->service->close($this->entity, $year);

        $this->assertTrue($record->isClosed());
        $this->assertEquals([], $record->closing_transaction_ids);
        $this->assertTrue($this->service->isClosed($this->entity, $year));
    }

    public function test_requester_cannot_approve_own_request(): void
    {
        $year = $this->closableYear();
        $this->service->submit($this->entity, $year, $this->requester->id);

        // The requester is also the admin — still not allowed while
        // another accountant/admin exists (setUp's approver).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot approve their own');

        $this->service->approve($this->entity, $year, $this->requester);
    }

    public function test_sole_accountant_admin_approves_own_request(): void
    {
        $year = $this->closableYear();

        // No other accountant/admin: the approval is routed back to the
        // requester instead of dead-ending the workflow.
        $this->approver->syncRoles([]);
        $this->service->submit($this->entity, $year, $this->requester->id);

        $record = $this->service->closeRecord($this->entity, $year);
        $this->assertTrue($this->service->approvalRoutedToRequester($record));

        $approved = $this->service->approve($this->entity, $year, $this->requester);

        $this->assertTrue($approved->canClose());
        $this->assertDatabaseHas('fiscal_year_closes', [
            'year' => $year,
            'status' => FiscalYearClose::STATUS_APPROVED,
            'requested_by' => $this->requester->id,
            'approved_by' => $this->requester->id,
        ]);
    }

    public function test_approve_requires_accountant_or_admin_role(): void
    {
        $year = $this->closableYear();
        $this->service->submit($this->entity, $year, $this->requester->id);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('accountant or an admin');

        $this->service->approve($this->entity, $year, $staff);
    }

    public function test_reopen_reverses_entries_and_restores_balances(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1).'-01-10', $this->bank, true, [[$this->expense, 4000]]);

        $record = $this->approvedRecord($year);
        $this->service->close($this->entity, $year);
        $closingIds = $record->refresh()->closing_transaction_ids;

        $this->service->reopen($this->entity, $year);

        $record->refresh();
        $this->assertEquals(FiscalYearClose::STATUS_REOPENED, $record->status);
        $this->assertNotNull($record->reopened_at);

        // The generated opening set for the next year is removed with it.
        $this->assertSame(0, Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('reference', 'FY-CLOSE-'.$year.'-OB')
            ->count());

        // Ledger back to pre-close state.
        $this->assertEquals(-10000.0, round($this->balance($this->revenue), 2));
        $this->assertEquals(4000.0, round($this->balance($this->expense), 2));
        $this->assertEquals(0.0, round($this->balance($this->retainedEarnings), 2));

        // Period reopened, app periods unlocked.
        $this->assertFalse($this->service->isClosed($this->entity, $year));
        $this->assertTrue(FiscalPeriod::where('year', $year)->where('is_locked', true)->doesntExist());

        // Each closing entry has a mirrored reversal on the same date.
        foreach ($closingIds as $closingId) {
            $original = Transaction::withoutGlobalScope(EntityScope::class)->find($closingId);
            $reversal = Transaction::withoutGlobalScope(EntityScope::class)
                ->where('reference', 'FY-CLOSE-'.$year.'-REV')
                ->where('transaction_date', $original->transaction_date)
                ->where('credited', ! $original->credited)
                ->first();
            $this->assertNotNull($reversal, "Reversal for closing transaction {$closingId} not found.");
        }
    }

    public function test_reopen_requires_an_executed_close(): void
    {
        $year = $this->closableYear();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no executed close to reopen');

        $this->service->reopen($this->entity, $year);
    }

    public function test_reopened_year_can_close_again(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 2500]]);

        $this->service->submit($this->entity, $year, $this->requester->id);
        $this->service->approve($this->entity, $year, $this->approver);
        $this->service->close($this->entity, $year);
        $this->service->reopen($this->entity, $year);

        // Re-submit the cycle: status 'reopened' can go around again.
        $record = $this->service->submit($this->entity, $year, $this->requester->id);
        $this->assertEquals(FiscalYearClose::STATUS_PENDING_APPROVAL, $record->status);

        $this->service->approve($this->entity, $year, $this->approver);
        $record = $this->service->close($this->entity, $year);

        $this->assertTrue($record->isClosed());
        $this->assertEquals(0.0, round($this->balance($this->revenue), 2));
        // Closing entries + first pair + reversals all net: RE holds the profit once.
        $this->assertEquals(-2500.0, round($this->balance($this->retainedEarnings), 2));
    }

    public function test_close_command_executes_the_full_flow(): void
    {
        $year = $this->closableYear();
        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 5000]]);

        $this->artisan('fiscal-year:close', ['year' => $year])
            ->expectsOutputToContain('no approved close request')
            ->assertFailed();

        $this->artisan('fiscal-year:close', ['year' => $year, '--force' => true])
            ->expectsOutputToContain("FY {$year} closed.")
            ->assertSuccessful();

        $this->assertTrue($this->service->isClosed($this->entity, $year));
    }

    public function test_reopen_command_reverses_the_close(): void
    {
        $year = $this->closableYear();
        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 5000]]);
        $this->service->close($this->entity, $year, force: true);

        $this->artisan('fiscal-year:reopen', ['year' => $year])
            ->expectsConfirmation("Reopen FY {$year}? The closing entries will be reversed and the year becomes editable again.", 'yes')
            ->expectsOutput("FY {$year} reopened — closing entries reversed, reporting period OPEN, app periods unlocked.")
            ->assertSuccessful();

        $this->assertFalse($this->service->isClosed($this->entity, $year));
        $this->assertEquals(-5000.0, round($this->balance($this->revenue), 2));
    }

    public function test_close_writes_next_year_opening_set_without_double_counting(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1).'-01-10', $this->bank, true, [[$this->expense, 4000]]);

        $bankBefore = round($this->balance($this->bank), 2);

        $this->approvedRecord($year);
        $this->service->close($this->entity, $year);

        // One generated row per non-zero balance-sheet account: dated the
        // year end (the eve of FY {year+1}), on the next year's period,
        // under the -OB reference. P&L accounts never carry opening rows.
        $nextPeriod = $this->service->reportingPeriod($this->entity, $year + 1);
        $set = Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('reference', 'FY-CLOSE-'.$year.'-OB')
            ->get();

        $bankRow = $set->firstWhere('account_id', $this->bank->id);
        $this->assertNotNull($bankRow);
        $this->assertSame('D', $bankRow->balance_type);
        // 10,000 receipt less the 4,000 January payment — both inside FY.
        $this->assertEquals(6000.0, (float) $bankRow->balance);
        $this->assertSame($nextPeriod->id, $bankRow->reporting_period_id);
        $this->assertSame(($year + 1).'-06-30 00:00:00', (string) $bankRow->transaction_date);
        $this->assertNull($set->firstWhere('account_id', $this->revenue->id));

        // Retained Earnings carries the closed result into the new year.
        $reRow = $set->firstWhere('account_id', $this->retainedEarnings->id);
        $this->assertNotNull($reRow);
        $this->assertSame('C', $reRow->balance_type);
        $this->assertEquals(6000.0, (float) $reRow->balance);

        // No double count: the snapshot-aware as-at balances are unchanged
        // by the close and the generated set (the profit lands in RE).
        $this->assertEquals($bankBefore, round($this->balance($this->bank), 2));
        $this->assertEquals(-6000.0, round($this->balance($this->retainedEarnings), 2));
    }

    public function test_close_supersedes_a_manual_migration_set_for_the_next_year(): void
    {
        $year = $this->closableYear();

        // A hand-entered migration set on FY {year+1}: bank 2,000 debit,
        // dated the eve of that year. Ledger activity before the eve (the
        // FY {year} receipt below) is superseded, not counted on top.
        $nextPeriod = $this->service->reportingPeriod($this->entity, $year + 1);
        (new Balance([
            'entity_id' => $this->entity->id,
            'account_id' => $this->bank->id,
            'reporting_period_id' => $nextPeriod->id,
            'currency_id' => $this->bank->currency_id,
            'transaction_type' => Transaction::JN,
            'transaction_date' => Carbon::create($year + 1, 6, 30),
            'balance_type' => Balance::DEBIT,
            'balance' => 2000,
        ]))->save();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 10000]]);

        // Pre-close: the migration snapshot wins and the earlier receipt
        // is excluded — 2,000, never 2,000 + 10,000.
        $this->assertEquals(2000.0, round($this->balance($this->bank), 2));

        $this->service->close($this->entity, $year, force: true);
        $record = $this->service->closeRecord($this->entity, $year);

        // Post-close: exactly one bank row on the period — the generated
        // one. It carries the snapshot the reports were showing at close
        // time (2,000), so the close never moves reported balances; the
        // manual row is preserved on the workflow record.
        $rows = Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('reporting_period_id', $nextPeriod->id)
            ->where('account_id', $this->bank->id)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('FY-CLOSE-'.$year.'-OB', $rows->first()->reference);
        $this->assertEquals(2000.0, (float) $rows->first()->balance);
        $this->assertEquals(2000.0, round($this->balance($this->bank), 2));

        $superseded = $record->refresh()->superseded_opening_balances;
        $this->assertIsArray($superseded);
        $this->assertSame($this->bank->id, (int) $superseded[0]['account_id']);
        $this->assertEquals(2000.0, (float) $superseded[0]['balance']);
        $this->assertNull($superseded[0]['reference']);

        // Reopen: the generated set goes, the manual set returns exactly
        // as it was, and the reports sit at their pre-close figures.
        $this->service->reopen($this->entity, $year);

        $restored = Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('reporting_period_id', $nextPeriod->id)
            ->where('account_id', $this->bank->id)
            ->get();
        $this->assertCount(1, $restored);
        $this->assertNull($restored->first()->reference);
        $this->assertEquals(2000.0, (float) $restored->first()->balance);
        $this->assertNull($record->refresh()->superseded_opening_balances);
        $this->assertEquals(2000.0, round($this->balance($this->bank), 2));
    }
}

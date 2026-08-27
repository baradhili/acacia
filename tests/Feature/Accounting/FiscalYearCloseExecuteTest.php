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
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
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
        $this->service = new FiscalYearService();
        $this->bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $this->revenue = Account::where('code', 4100)->where('entity_id', $this->entity->id)->first();
        $this->expense = Account::where('code', 5100)->where('entity_id', $this->entity->id)->first();
        $this->retainedEarnings = Account::where('code', 3200)->where('entity_id', $this->entity->id)->first();

        $this->requester = User::where('email', 'admin@example.com')->first();
        $this->approver = User::factory()->create();
        $this->approver->assignRole('accountant');
        $this->actingAs($this->requester);
    }

    protected function postJournal(string $date, Account $main, bool $credited, array $lines, string $reference = null): JournalEntry
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
        return (float) Ledger::balance(
            $account,
            Carbon::create(2000, 1, 1),
            now()->endOfDay(),
            $this->entity->currency_id
        )[$this->entity->currency_id] + OpeningBalances::effectiveOpening($account, $this->entity);
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

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1) . '-01-10', $this->bank, true, [[$this->expense, 4000]]);

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
                'FY-CLOSE-' . $year,
                Transaction::withoutGlobalScope(\IFRS\Scopes\EntityScope::class)->find($transactionId)->reference
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

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 1000]]);

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
            'payment_date' => $year . '-11-01',
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

        // The requester is also the admin — still not allowed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot approve their own');

        $this->service->approve($this->entity, $year, $this->requester);
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

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1) . '-01-10', $this->bank, true, [[$this->expense, 4000]]);

        $record = $this->approvedRecord($year);
        $this->service->close($this->entity, $year);
        $closingIds = $record->refresh()->closing_transaction_ids;

        $this->service->reopen($this->entity, $year);

        $record->refresh();
        $this->assertEquals(FiscalYearClose::STATUS_REOPENED, $record->status);
        $this->assertNotNull($record->reopened_at);

        // Ledger back to pre-close state.
        $this->assertEquals(-10000.0, round($this->balance($this->revenue), 2));
        $this->assertEquals(4000.0, round($this->balance($this->expense), 2));
        $this->assertEquals(0.0, round($this->balance($this->retainedEarnings), 2));

        // Period reopened, app periods unlocked.
        $this->assertFalse($this->service->isClosed($this->entity, $year));
        $this->assertTrue(FiscalPeriod::where('year', $year)->where('is_locked', true)->doesntExist());

        // Each closing entry has a mirrored reversal on the same date.
        foreach ($closingIds as $closingId) {
            $original = Transaction::withoutGlobalScope(\IFRS\Scopes\EntityScope::class)->find($closingId);
            $reversal = Transaction::withoutGlobalScope(\IFRS\Scopes\EntityScope::class)
                ->where('reference', 'FY-CLOSE-' . $year . '-REV')
                ->where('transaction_date', $original->transaction_date)
                ->where('credited', !$original->credited)
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

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 2500]]);

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
        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 5000]]);

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
        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 5000]]);
        $this->service->close($this->entity, $year, force: true);

        $this->artisan('fiscal-year:reopen', ['year' => $year])
            ->expectsConfirmation("Reopen FY {$year}? The closing entries will be reversed and the year becomes editable again.", 'yes')
            ->expectsOutput("FY {$year} reopened — closing entries reversed, reporting period OPEN, app periods unlocked.")
            ->assertSuccessful();

        $this->assertFalse($this->service->isClosed($this->entity, $year));
        $this->assertEquals(-5000.0, round($this->balance($this->revenue), 2));
    }
}

<?php

namespace Tests\Feature;

use App\Models\EntitySetting;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ledger:prune: closed financial years older than the retention
 * window lose their double-entry trail, but only after an
 * opening-balance snapshot is written at the boundary — so balances
 * after the boundary are unchanged. Business documents are kept.
 */
class LedgerPruneTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-10 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        $this->bank = Account::where('entity_id', $this->entity->id)->where('code', 320)->firstOrFail();

        // Ensure a reporting period exists for the recent posting.
        IfrsPosting::ensureReportingPeriod(now(), $this->entity);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    protected function postJournal(int $debitCode, int $creditCode, float $amount, string $date, string $reference): void
    {
        $date = Carbon::parse($date);
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($date, $this->entity),
            'account_id' => $this->account($debitCode)->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => "Prune fixture {$reference}",
            'reference' => $reference,
        ]);

        $line = LineItem::create([
            'account_id' => $this->account($creditCode)->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]);
        $journal->addLineItem($line);
        $journal->post();
    }

    /** A closed FY (Jul 2018 – Jun 2019) with movement, 7+ years ago. */
    protected function seedOldClosedYear(): void
    {
        $this->postJournal(320, 4100, 1000, '2019-01-15', 'OLD-2019');
        $this->postJournal(320, 4100, 500, '2026-09-01', 'RECENT');

        ReportingPeriod::withoutGlobalScopes()
            ->updateOrCreate(
                ['entity_id' => $this->entity->id, 'calendar_year' => 2019],
                ['period_count' => 1, 'status' => ReportingPeriod::CLOSED],
            );
    }

    public function test_prunes_closed_years_past_retention_and_preserves_balances(): void
    {
        $this->seedOldClosedYear();

        $before = OpeningBalances::balanceAt($this->bank, $this->entity, now());
        $this->assertEquals(1500.0, round($before, 2));

        Artisan::call('ledger:prune');
        $output = Artisan::output();
        $this->assertStringContainsString('Pruning 1 ledger transaction(s)', $output);

        // The old journal is gone; the recent one stays.
        $this->assertSame(0, Transaction::where('reference', 'OLD-2019')->count());
        $this->assertSame(1, Transaction::where('reference', 'RECENT')->count());

        // The boundary snapshot keeps every later as-at balance intact.
        $this->assertEquals(1500.0, round(OpeningBalances::balanceAt($this->bank, $this->entity, now()), 2));

        // Re-running is a no-op.
        Artisan::call('ledger:prune');
        $this->assertStringContainsString('nothing to prune', Artisan::output());
        $this->assertEquals(1500.0, round(OpeningBalances::balanceAt($this->bank, $this->entity, now()), 2));
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $this->seedOldClosedYear();

        Artisan::call('ledger:prune', ['--dry-run' => true]);
        $this->assertStringContainsString('Would prune 1 ledger transaction(s)', Artisan::output());

        $this->assertSame(1, Transaction::where('reference', 'OLD-2019')->count());
    }

    public function test_retention_setting_governs_the_window(): void
    {
        // FY2019 ends Jun 2019 — inside a 20-year window, outside a 5-year one.
        $this->seedOldClosedYear();

        Artisan::call('ledger:prune', ['--years' => 20]);
        $this->assertStringContainsString('nothing to prune', Artisan::output());

        Artisan::call('ledger:prune', ['--years' => 5]);
        $this->assertStringContainsString('Pruning 1 ledger transaction(s)', Artisan::output());
    }

    public function test_open_years_are_never_pruned(): void
    {
        $this->postJournal(320, 4100, 1000, '2019-01-15', 'OLD-OPEN');

        // The old year was never closed — no pruning candidate.
        Artisan::call('ledger:prune', ['--years' => 5]);
        $this->assertStringContainsString('nothing to prune', Artisan::output());
        $this->assertSame(1, Transaction::where('reference', 'OLD-OPEN')->count());
    }

    public function test_the_retention_setting_is_managed_on_the_administration_page(): void
    {
        $admin = tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');

        $this->actingAs($admin)
            ->put(route('administration.retention.update'), ['retention_years' => 10])
            ->assertRedirect(route('administration.index'))
            ->assertSessionHas('success');

        $this->assertSame(10, EntitySetting::retentionYears($this->entity));

        // Blank falls back to the default.
        $this->actingAs($admin)
            ->put(route('administration.retention.update'), ['retention_years' => null])
            ->assertSessionHas('success');

        $this->assertSame(7, EntitySetting::retentionYears($this->entity));
    }
}

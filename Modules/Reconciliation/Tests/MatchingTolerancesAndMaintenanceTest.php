<?php

namespace Modules\Reconciliation\Tests;

use App\Models\Client;
use App\Models\Payment;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * The reconciliation behaviours the drifted legacy files guarded,
 * recut against the current service: the strict auto-match pass's
 * reference/amount/date evidence and its tolerance boundaries, and
 * the ignore/restore lifecycle with its history trail. The
 * learned-pass matching and the unreconciled-movements view are
 * covered by the sibling test classes; the auto-create flows were
 * retired with their service methods (Sep 2026).
 */
class MatchingTolerancesAndMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixtures post into FY2026 and the strict pass reads the
        // open-FY-bounded movements panel — pin the clock inside that
        // year so the tests survive the real clock closing FY2026.
        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        $this->entity = $this->seedIfrs();
        $this->service = app(ReconciliationService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * The chart the posting paths need: bank (320), revenue (4100)
     * and GST Payable (2200 with the GST 10% Vat), plus the expense
     * accounts the sibling class posts supplier payments to. Mirrors
     * UnreconciledBankMovementsTest::seedIfrs().
     */
    protected function seedIfrs(): Entity
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 7,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);
        $entity->refresh();

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => 2026,
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        $accounts = [
            ['Operating Account', Account::BANK, 320],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
            ['GST Payable', Account::CONTROL, 2200],
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Meals & Entertainment', Account::OPERATING_EXPENSE, 5500],
            ['Office Expenses', Account::OPERATING_EXPENSE, 7400],
            ['Software Subscriptions', Account::OPERATING_EXPENSE, 7500],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ];
        foreach ($accounts as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => Account::where('code', 2200)->value('id'),
            'entity_id' => $entity->id,
        ]);

        return $entity;
    }

    protected function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Acme Corp',
            'email' => 'accounts@acme.example',
        ], $attributes));
    }

    protected function bankLine(array $attributes = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Test bank movement',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-09-10'),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    /**
     * A posted client payment: a real bank movement whose reference is
     * the payment number — what the strict pass matches bank lines to.
     */
    protected function postedPayment(Client $client, float $amount, string $date): Payment
    {
        $payment = new Payment;
        $payment->fill([
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $payment->client_id = $client->id;
        $payment->save();
        $this->assertNotNull($payment->postToIFRS());

        return $payment;
    }

    // =====================================================
    // Strict-pass evidence and tolerance boundaries
    // =====================================================

    public function test_the_strict_pass_matches_on_reference_amount_and_day(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        $line = $this->bankLine(['reference' => $receipt->payment_number]);

        $matchedId = $this->service->matchTransaction($line);

        $this->assertNotNull($matchedId);
        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $line->status);
        $this->assertSame('ledger', $line->matched_transaction_type);
        $this->assertNotNull($line->matched_at);
        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_AUTO_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_the_strict_pass_matches_within_the_three_day_tolerance(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        // Day 3 is the boundary — inside the window.
        $line = $this->bankLine([
            'reference' => $receipt->payment_number,
            'transaction_date' => Carbon::parse('2026-09-13'),
        ]);

        $this->assertNotNull($this->service->matchTransaction($line));
    }

    public function test_the_strict_pass_refuses_beyond_the_three_day_tolerance(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        // No payer name either: the learned pass has nothing to work
        // with, so the day-4 line must stay pending outright.
        $line = $this->bankLine([
            'reference' => $receipt->payment_number,
            'transaction_date' => Carbon::parse('2026-09-14'),
        ]);

        $this->assertNull($this->service->matchTransaction($line));
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->fresh()->status);
    }

    public function test_the_strict_pass_matches_a_one_cent_fee_difference(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        // The bank line nets of a small fee. One cent is the only
        // two-decimal difference inside the one-cent tolerance — the
        // inclusive boundary itself — and amounts are stored decimal:2,
        // so a sub-cent fixture would only round back to equality.
        $line = $this->bankLine([
            'reference' => $receipt->payment_number,
            'amount' => 1499.99,
        ]);

        $this->assertNotNull($this->service->matchTransaction($line));
    }

    public function test_the_strict_pass_refuses_an_amount_outside_tolerance(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        $line = $this->bankLine([
            'reference' => $receipt->payment_number,
            'amount' => 1490.00,
        ]);

        $this->assertNull($this->service->matchTransaction($line));
    }

    public function test_the_strict_pass_refuses_a_reference_it_does_not_know(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        // Same amount, same day, but the line names a reference the
        // ledger never wrote: an amount+date coincidence is not
        // evidence, and with no payer there is nothing to learn from.
        $line = $this->bankLine(['reference' => 'UNKNOWN-REF']);

        $this->assertNull($this->service->matchTransaction($line));

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_AUTO_MATCH,
            'status' => ReconciliationHistory::STATUS_FAILED,
        ]);
    }

    public function test_a_line_without_a_reference_never_strict_matches(): void
    {
        $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        $line = $this->bankLine(['reference' => null]);

        $this->assertNull($this->service->matchTransaction($line));
    }

    // =====================================================
    // Ignore / restore lifecycle
    // =====================================================

    public function test_ignoring_leaves_a_note_and_history(): void
    {
        $line = $this->bankLine();

        $this->assertTrue($this->service->ignoreTransaction($line, 'Personal expense'));

        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_IGNORED, $line->status);
        $this->assertStringContainsString('Personal expense', $line->notes);
        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_IGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_matched_and_already_ignored_lines_refuse_ignoring(): void
    {
        $matched = $this->bankLine();
        $matched->markAsMatched(123, 'payment');

        $this->assertFalse($this->service->ignoreTransaction($matched));
        $this->assertSame(BankTransaction::STATUS_MATCHED, $matched->fresh()->status);

        $ignored = $this->bankLine();
        $ignored->markAsIgnored('First reason');

        $this->assertFalse($this->service->ignoreTransaction($ignored, 'Second try'));
        $this->assertStringContainsString('First reason', $ignored->fresh()->notes);
    }

    public function test_ignored_lines_restore_to_pending(): void
    {
        $line = $this->bankLine();
        $this->service->ignoreTransaction($line);

        $this->assertTrue($this->service->restoreIgnoredTransaction($line));

        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->status);
        $this->assertStringContainsString('Restored', $line->notes);
        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_UNIGNORE,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);

        // Restoring is a one-way door out of IGNORED only.
        $this->assertFalse($this->service->restoreIgnoredTransaction($line));
    }

    public function test_batch_ignoring_reports_invalid_ids(): void
    {
        $kept = $this->bankLine();

        $results = $this->service->ignoreTransactions([$kept->id, 99999, 88888], 'Batch');

        $this->assertSame(1, $results['ignored']);
        $this->assertSame(2, count($results['errors']));
        $this->assertSame(BankTransaction::STATUS_IGNORED, $kept->fresh()->status);
    }

    public function test_bank_transaction_scopes_partition_by_status_and_source(): void
    {
        $this->bankLine(['source_id' => 'WISE-P']);
        $this->bankLine(['source_id' => 'WISE-M'])->markAsMatched(1, 'ledger');
        $this->bankLine(['source_id' => 'WISE-I'])->markAsIgnored('Not business');

        $this->assertSame(1, BankTransaction::pending()->count());
        $this->assertSame(1, BankTransaction::matched()->count());
        $this->assertSame(3, BankTransaction::fromSource(BankTransaction::SOURCE_WISE)->count());
    }

    // =====================================================
    // Auto-created receipts and purchases
    // =====================================================

}

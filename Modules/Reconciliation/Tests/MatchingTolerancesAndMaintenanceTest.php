<?php

namespace Modules\Reconciliation\Tests;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Supplier;
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
 * reference/amount/date evidence and its tolerance boundaries, the
 * ignore/restore lifecycle with its history trail, auto-created
 * receipts and purchases (guards, filters, paid-at-entry posting),
 * and the expense-account suggestions purchases are categorised to.
 * The learned-pass matching and the unreconciled-movements view are
 * covered by the sibling test classes.
 */
class MatchingTolerancesAndMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = $this->seedIfrs();
        $this->service = app(ReconciliationService::class);
    }

    /**
     * The chart the posting and suggestion paths need: bank (320),
     * revenue (4100), GST Payable (2200 + the GST 10% Vat) and the
     * expense accounts bill payments post to and suggestExpenseAccount
     * maps merchants onto (5300 travel, 5500 meals, 7400 office, 7500
     * subscriptions, 8900 the fallback). Mirrors
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

    protected function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'AWS',
            'email' => 'billing@aws.example',
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
        $payment = Payment::create([
            'client_id' => $client->id,
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);
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

    public function test_the_strict_pass_matches_a_fractional_amount_difference(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        // The bank line nets of a small fee: half a cent is well inside
        // the one-cent amount tolerance.
        $line = $this->bankLine([
            'reference' => $receipt->payment_number,
            'amount' => 1499.995,
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

    public function test_a_receipt_is_refused_for_debits_and_unknown_clients(): void
    {
        $debit = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'amount' => -100.00,
        ]);
        $this->assertNull($this->service->createCashReceiptFromBankTransaction($debit, $this->client()->id));
        $this->assertSame(BankTransaction::STATUS_PENDING, $debit->fresh()->status);

        $credit = $this->bankLine();
        $this->assertNull($this->service->createCashReceiptFromBankTransaction($credit, 99999));
        $this->assertSame(BankTransaction::STATUS_PENDING, $credit->fresh()->status);
    }

    public function test_auto_created_receipts_can_be_scoped_to_one_clients_lines(): void
    {
        $acme = $this->client(['name' => 'Acme Corp']);
        $other = $this->client(['name' => 'Other Corp']);

        // The client filter follows the bank line's own client tag —
        // untagged lines (whatever their payer) stay for the unscoped run.
        $this->bankLine(['client_id' => $acme->id, 'payer_name' => 'Acme Corp']);
        $this->bankLine(['client_id' => $other->id, 'payer_name' => 'Other Corp']);
        $untagged = $this->bankLine(['payer_name' => 'Acme Corp', 'source_id' => 'WISE-UNTAGGED']);

        $scoped = $this->service->autoCreateCashReceipts($acme->id);

        $this->assertSame(1, $scoped['count']);
        $this->assertSame($acme->id, $scoped['created'][0]->client_id);
        $this->assertSame(BankTransaction::STATUS_PENDING, $untagged->fresh()->status);

        $all = $this->service->autoCreateCashReceipts();
        $this->assertSame(2, $all['count']); // the other client's + the untagged Acme line
    }

    public function test_a_purchase_is_created_matched_and_paid_from_a_debit(): void
    {
        $aws = $this->supplier();

        $line = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'amount' => -250.00,
            'payee_name' => 'AWS',
            'merchant_name' => 'AWS',
            'reference' => 'SUB-2026-09',
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction($line, $aws->id);

        $this->assertNotNull($bill);
        $this->assertEqualsWithDelta(250.00, (float) $bill->fresh()->total, 0.001);
        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $line->status);
        $this->assertSame('bill', $line->matched_transaction_type);
        $this->assertEquals($bill->id, $line->matched_transaction_id);

        // Paid at entry: the supplier payment exists and, with the IFRS
        // chart seeded, actually posted to the ledger.
        $payment = BillPayment::where('supplier_id', $aws->id)->firstOrFail();
        $this->assertEqualsWithDelta(250.00, (float) $payment->amount, 0.001);
        $this->assertNotNull($payment->ifrs_payment_id);
        $this->assertSame(Bill::STATUS_PAID, $bill->fresh()->status);
    }

    public function test_a_purchase_can_be_left_unpaid_and_refuses_credits(): void
    {
        $aws = $this->supplier();

        $line = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'amount' => -250.00,
            'merchant_name' => 'AWS',
        ]);

        $bill = $this->service->createPurchaseFromBankTransaction($line, $aws->id, null, null, false);

        $this->assertSame(Bill::STATUS_DRAFT, $bill->fresh()->status);
        $this->assertSame(0, BillPayment::count());

        $credit = $this->bankLine();
        $this->assertNull($this->service->createPurchaseFromBankTransaction($credit, $aws->id));
        $this->assertSame(BankTransaction::STATUS_PENDING, $credit->fresh()->status);
    }

    public function test_merchants_are_suggested_to_seeded_expense_accounts(): void
    {
        $accountId = fn (int $code) => (int) Account::where('code', $code)->value('id');

        $this->assertSame($accountId(7500), $this->service->suggestExpenseAccount('Amazon AWS'));
        $this->assertSame($accountId(7500), $this->service->suggestExpenseAccount('Google Workspace'));
        $this->assertSame($accountId(5300), $this->service->suggestExpenseAccount('Uber Trip'));
        $this->assertSame($accountId(5500), $this->service->suggestExpenseAccount('Aroma Cafe'));
        $this->assertSame($accountId(7400), $this->service->suggestExpenseAccount('Officeworks'));
        $this->assertSame($accountId(8900), $this->service->suggestExpenseAccount('Completely Unknown Merchant'));
    }
}

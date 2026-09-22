<?php

namespace Modules\Reconciliation\Tests;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Payment;
use App\Models\ReimbursementPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\Employee;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * The book side of reconciliation: movements posted to a bank account
 * in the IFRS ledger appear on the reconciliation screen until a matched
 * bank line accounts for them — by linking to the payment that posted
 * the movement, or (for direct postings) to either leg of its ledger
 * transaction. Payments never posted to IFRS touched no bank account
 * and never show. A posting and its reversal share the transaction
 * reference, so once both are unreconciled they net to no bank impact
 * and drop out together; a reversal whose posting is already matched
 * stays listed (and matchable) until its own bank line clears it.
 */
class UnreconciledBankMovementsTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected Entity $entity;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = $this->seedIfrs();
        $this->service = app(ReconciliationService::class);
        $this->user = User::factory()->create(['entity_id' => $this->entity->id]);
    }

    /**
     * Minimum IFRS chart for the posting paths under test: entity +
     * period, bank (320), revenue (4100), GST Payable (2200 CONTROL with
     * the GST 10% Vat) and the expense accounts bill payments post to
     * (item expense 5300, fallback 8900). 2280 is created lazily by
     * BillPayment::ensureReimbursementAccount(). Mirrors
     * PostPaymentsToIfrsTest::seedIfrs().
     */
    protected function seedIfrs(): Entity
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
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

        $accountData = [
            ['Operating Account', Account::BANK, 320],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
            ['GST Payable', Account::CONTROL, 2200],
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ];
        foreach ($accountData as [$name, $type, $code]) {
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

    protected function employee(): Employee
    {
        return Employee::create([
            'entity_id' => $this->entity->id,
            'name' => 'Jane Smith',
            'employment_type' => 'employee',
            'payment_basis' => 'salary',
            'status' => Employee::STATUS_ACTIVE,
        ]);
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

    /**
     * A supplier payment that actually moved money: an itemised bill it
     * allocates to (posting refuses empty allocations) and a completed
     * IFRS posting.
     */
    protected function postedSupplierPayment(Supplier $supplier, float $amount, string $date): BillPayment
    {
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => $date,
            'due_date' => $date,
            'status' => Bill::STATUS_OPEN,
        ]);
        $bill->items()->create([
            'description' => 'Subscription',
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_rate' => 0,
            'expense_account_id' => Account::where('code', 5300)->value('id'),
            'sort_order' => 0,
        ]);
        $bill->recalculateTotals();

        $payment = BillPayment::create([
            'supplier_id' => $supplier->id,
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $payment->allocateToBill($bill, $amount);
        $this->assertNotNull($payment->postToIFRS());

        return $payment;
    }

    protected function postedReimbursement(Employee $employee, float $amount, string $date): ReimbursementPayment
    {
        $payment = ReimbursementPayment::create([
            'employee_id' => $employee->id,
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => 'bank_transfer',
            'status' => ReimbursementPayment::STATUS_COMPLETED,
        ]);
        $this->assertNotNull($payment->postToIFRS());

        return $payment;
    }

    /**
     * Mirror of the reversal journals the app writes (voiding,
     * reallocation): bank main account, opposite side, and — the
     * structural link the unreconciled view nets on — the same
     * transaction reference as the posting it reverses.
     */
    protected function reverseOnBank(string $paymentNumber, float $amount, string $date, bool $moneyBackIn): JournalEntry
    {
        $reversal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $this->entity),
            'account_id' => Account::where('code', 320)->value('id'),
            // The posting's flip: a debit on the bank (credited => false)
            // puts the money back; a credit takes it out again.
            'credited' => ! $moneyBackIn,
            'entity_id' => $this->entity->id,
            'narration' => "Reversal of supplier payment: {$paymentNumber}",
            'reference' => $paymentNumber,
        ]);
        $reversal->addLineItem(LineItem::create([
            'account_id' => Account::where('code', 5300)->value('id'),
            'amount' => $amount,
            'quantity' => 1,
            'vat_inclusive' => false,
            'entity_id' => $this->entity->id,
        ]));
        $reversal->post();

        return $reversal;
    }

    public function test_lists_posted_movements_the_statement_never_confirmed(): void
    {
        $acme = $this->client();
        $receipt = $this->postedPayment($acme, 1500.00, '2026-09-10');

        // A supplier payment reconciled by a matched bank line is gone.
        $aws = $this->supplier();
        $supplierPayment = $this->postedSupplierPayment($aws, 89.00, '2026-09-08');
        $line = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -89.00,
            'transaction_date' => Carbon::parse('2026-09-08'),
        ]);
        $this->assertTrue($this->service->manualOverrideLink($line, 'bill_payment', $supplierPayment->id));

        // An employee reimbursement posted but never confirmed by the bank.
        $reimbursement = $this->postedReimbursement($this->employee(), 110.00, '2026-09-12');

        // Recorded but never posted — no ledger legs, so no bank movement.
        Payment::create([
            'client_id' => $acme->id,
            'amount' => 60.00,
            'payment_date' => '2026-09-14',
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $movements = $this->service->getUnreconciledBankMovements();

        $this->assertCount(2, $movements);

        $receiptRow = $movements->firstWhere('reference', $receipt->payment_number);
        $this->assertNotNull($receiptRow);
        $this->assertSame('Client payment', $receiptRow['origin']);
        $this->assertSame('Acme Corp', $receiptRow['counterparty']);
        $this->assertSame(1500.0, $receiptRow['amount']);

        $reimbursementRow = $movements->firstWhere('reference', $reimbursement->payment_number);
        $this->assertNotNull($reimbursementRow);
        $this->assertSame('Employee reimbursement', $reimbursementRow['origin']);
        $this->assertSame('Jane Smith', $reimbursementRow['counterparty']);
        $this->assertSame(-110.0, $reimbursementRow['amount']);

        $this->assertNull($movements->firstWhere('reference', $supplierPayment->payment_number));
    }

    public function test_matching_the_bank_line_clears_the_movement(): void
    {
        $acme = $this->client();
        $receipt = $this->postedPayment($acme, 1500.00, '2026-09-10');
        $line = $this->bankLine(['payer_name' => 'Acme Corp']);

        $this->assertNotNull($this->service->getUnreconciledBankMovements()
            ->firstWhere('reference', $receipt->payment_number));

        $this->service->manualOverrideLink($line, 'payment', $receipt->id);

        $this->assertNull($this->service->getUnreconciledBankMovements()
            ->firstWhere('reference', $receipt->payment_number));

        // Unmatching returns it to the unreconciled list.
        $this->service->unlinkTransaction($line);

        $this->assertNotNull($this->service->getUnreconciledBankMovements()
            ->firstWhere('reference', $receipt->payment_number));
    }

    public function test_a_ledger_link_reconciles_a_movement_by_either_leg(): void
    {
        // A direct bank line → ledger row link (the manual "ledger" tier)
        // must clear the movement even though the link points at the
        // counter-account leg, not the bank leg.
        $acme = $this->client();
        $receipt = $this->postedPayment($acme, 1500.00, '2026-09-10');

        $revenueLeg = Ledger::where('transaction_id', $receipt->ifrs_receipt_id)
            ->where('post_account', '!=', Account::where('code', 320)->value('id'))
            ->value('id');
        $this->assertNotNull($revenueLeg);

        $line = $this->bankLine(['payer_name' => 'Acme Corp']);
        $this->assertTrue($this->service->manualOverrideLink($line, 'ledger', $revenueLeg));

        $this->assertNull($this->service->getUnreconciledBankMovements()
            ->firstWhere('reference', $receipt->payment_number));
    }

    public function test_a_full_reversal_nets_out_both_movements(): void
    {
        // A supplier payment whose posting was later fully reversed: the
        // pair has no net bank impact, so neither the payment leg nor the
        // reversal shows as waiting for a bank line.
        $supplierPayment = $this->postedSupplierPayment($this->supplier(), 89.00, '2026-09-08');
        $this->reverseOnBank($supplierPayment->payment_number, 89.00, '2026-09-08', true);

        $movements = $this->service->getUnreconciledBankMovements();

        $this->assertCount(0, $movements);
        $this->assertNull($movements->firstWhere('reference', $supplierPayment->payment_number));
        $this->assertNull($movements->firstWhere('origin', 'Reversal'));
    }

    public function test_a_reversal_of_a_matched_payment_still_awaits_its_bank_line(): void
    {
        // The payment's bank line is already matched; the reversal is a
        // real money-back movement that needs its own (debit) bank line,
        // so it alone stays listed.
        $acme = $this->client();
        $receipt = $this->postedPayment($acme, 1500.00, '2026-09-10');
        $this->service->manualOverrideLink(
            $this->bankLine(['payer_name' => 'Acme Corp']),
            'payment',
            $receipt->id
        );

        $this->reverseOnBank($receipt->payment_number, 1500.00, '2026-09-12', false);

        $movements = $this->service->getUnreconciledBankMovements();

        $this->assertCount(1, $movements);
        $this->assertSame('Reversal', $movements[0]['origin']);
        $this->assertSame(-1500.0, $movements[0]['amount']);
        $this->assertSame($receipt->payment_number, $movements[0]['reference']);
    }

    public function test_match_screen_offers_reversals_and_matching_clears_them(): void
    {
        // A refund bank line (debit) with no payment behind it: the only
        // candidate is the reversal movement itself, and matching it
        // clears the panel.
        $acme = $this->client();
        $receipt = $this->postedPayment($acme, 1500.00, '2026-09-10');
        $this->service->manualOverrideLink(
            $this->bankLine(['payer_name' => 'Acme Corp']),
            'payment',
            $receipt->id
        );
        $this->reverseOnBank($receipt->payment_number, 1500.00, '2026-09-12', false);

        $refundLine = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'Acme Corp',
            'amount' => -1500.00,
            'transaction_date' => Carbon::parse('2026-09-12'),
        ]);

        $candidates = $this->actingAs($this->user)
            ->get(route('reconciliation.match', $refundLine))
            ->assertOk()
            ->viewData('candidates');

        $reversalCandidate = $candidates->first(fn ($candidate) => $candidate['type'] === 'ledger');
        $this->assertNotNull($reversalCandidate);
        $this->assertEquals(1500.0, $reversalCandidate['amount']);
        $this->assertSame($receipt->payment_number, $reversalCandidate['reference']);

        $this->actingAs($this->user)
            ->post(route('reconciliation.match.store', $refundLine), [
                'type' => 'ledger',
                'target_id' => $reversalCandidate['id'],
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertCount(0, $this->service->getUnreconciledBankMovements());
    }

    public function test_the_strict_pass_auto_matches_a_bank_line_to_a_movement_by_reference(): void
    {
        // A reversal with no payment behind it (money back in), and a
        // bank credit line carrying its reference: the strict pass
        // pairs them without any manual step, and the panel clears.
        $supplierPayment = $this->postedSupplierPayment($this->supplier(), 89.00, '2026-09-08');
        $this->service->manualOverrideLink(
            $this->bankLine([
                'type' => BankTransaction::TYPE_DEBIT,
                'amount' => -89.00,
                'transaction_date' => Carbon::parse('2026-09-08'),
            ]),
            'bill_payment',
            $supplierPayment->id
        );
        $this->reverseOnBank($supplierPayment->payment_number, 89.00, '2026-09-09', true);

        $line = $this->bankLine([
            'reference' => $supplierPayment->payment_number,
            'amount' => 89.00,
            'transaction_date' => Carbon::parse('2026-09-09'),
        ]);

        $results = $this->service->autoMatchAll();

        $this->assertSame(1, $results['matched']);
        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $line->status);
        $this->assertSame('ledger', $line->matched_transaction_type);
        $this->assertCount(0, $this->service->getUnreconciledBankMovements());
    }

    public function test_index_screen_lists_the_unreconciled_movements(): void
    {
        $receipt = $this->postedPayment($this->client(), 1500.00, '2026-09-10');

        $response = $this->actingAs($this->user)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertSee('In the books, not on the bank statement')
            ->assertSee('In books, not on statement')
            ->assertSee($receipt->payment_number);

        $this->assertTrue($response->viewData('stats')['in_books'] === 1);

        // Once everything in the books is matched, the panel says so.
        $this->service->manualOverrideLink(
            $this->bankLine(['payer_name' => 'Acme Corp']),
            'payment',
            $receipt->id
        );

        $this->actingAs($this->user)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertSee('Every movement in the books is matched to a bank line.');
    }
}

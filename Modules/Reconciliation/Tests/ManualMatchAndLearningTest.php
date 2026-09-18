<?php

namespace Modules\Reconciliation\Tests;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationCounterpartyRule;
use Modules\Reconciliation\Models\ReconciliationHistory;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * Manual matching and the learning loop around it: the match screen
 * and its amount-close/reference-search payment candidates, matching
 * (by candidate or by id) with history, unmatching, and counterparty
 * rules — a manual match teaches the payer/payee → client/supplier
 * association, and the auto-matcher's learned pass uses it to pair
 * later bank lines with a fresh unconsumed client or supplier payment.
 */
class ManualMatchAndLearningTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReconciliationService::class);
        $this->user = User::factory()->create();
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

    protected function payment(Client $client, float $amount, string $date, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'client_id' => $client->id,
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ], $attributes));
    }

    protected function bill(Supplier $supplier, float $total, string $date): Bill
    {
        return Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => $date,
            'due_date' => $date,
            'subtotal' => $total,
            'total' => $total,
            'status' => Bill::STATUS_OPEN,
        ]);
    }

    protected function billPayment(Supplier $supplier, float $amount, string $date): BillPayment
    {
        return BillPayment::create([
            'supplier_id' => $supplier->id,
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
    }

    public function test_match_screen_lists_amount_close_candidates_and_matching_records_history(): void
    {
        $acme = $this->client();
        $payment = $this->payment($acme, 1500.00, '2026-09-10');
        $line = $this->bankLine(['payer_name' => 'Acme Corp']);

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertOk()
            ->assertSee('Candidates')
            ->assertSee($payment->payment_number);

        $this->actingAs($this->user)
            ->post(route('reconciliation.match.store', $line), [
                'type' => 'payment',
                'target_id' => $payment->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $line->status);
        $this->assertSame('payment', $line->matched_transaction_type);
        $this->assertEquals($payment->id, $line->matched_transaction_id);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_search_finds_candidates_the_amount_filter_misses(): void
    {
        // The bank line is net of fees, the payment is the gross figure —
        // amount-close fails, but the reference search finds it. Asserted
        // on the candidates themselves: the screen echoes the search term
        // in its empty state, so assertSee alone can false-positive.
        $acme = $this->client();
        $payment = $this->payment($acme, 1490.50, '2026-09-12');
        $line = $this->bankLine(['payer_name' => 'Acme Corp', 'reference' => 'ACME-42']);

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->where('id', $payment->id)->where('type', 'payment')->isEmpty());

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', ['transaction' => $line, 'q' => $payment->payment_number]))
            ->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->contains(fn ($candidate) => $candidate['type'] === 'payment' && $candidate['id'] === $payment->id));
    }

    public function test_debit_line_lists_only_supplier_payment_candidates(): void
    {
        // A bank line is a money movement, so candidates are payments
        // only: a debit pairs with supplier payments — the bill document
        // behind it and client payments of the same size stay out.
        $aws = $this->supplier();
        $bill = $this->bill($aws, 89.00, '2026-09-08');
        $supplierPayment = $this->billPayment($aws, 89.00, '2026-09-10');
        $acme = $this->client();
        $clientPayment = $this->payment($acme, 89.00, '2026-09-10');
        $line = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -89.00,
        ]);

        $candidates = $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertOk()
            ->viewData('candidates');

        $this->assertTrue($candidates->contains(fn ($c) => $c['type'] === 'bill_payment' && $c['id'] === $supplierPayment->id));
        $this->assertFalse($candidates->contains(fn ($c) => $c['type'] === 'bill' && $c['id'] === $bill->id));
        $this->assertFalse($candidates->contains(fn ($c) => $c['type'] === 'payment' && $c['id'] === $clientPayment->id));
    }

    public function test_supplier_payment_search_and_match_teaches_the_supplier_rule(): void
    {
        // The bank line is net of fees, the supplier payment is the
        // gross figure — amount-close fails, but searching by supplier
        // name finds it, and matching teaches the supplier rule.
        $aws = $this->supplier();
        $supplierPayment = $this->billPayment($aws, 98.50, '2026-09-10');
        $line = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -100.00,
        ]);

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', ['transaction' => $line, 'q' => 'AWS']))
            ->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->contains(fn ($c) => $c['type'] === 'bill_payment' && $c['id'] === $supplierPayment->id));

        $this->actingAs($this->user)
            ->post(route('reconciliation.match.store', $line), [
                'type' => 'bill_payment',
                'target_id' => $supplierPayment->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $line->status);
        $this->assertSame('bill_payment', $line->matched_transaction_type);
        $this->assertEquals($supplierPayment->id, $line->matched_transaction_id);

        $rule = ReconciliationCounterpartyRule::query()->firstOrFail();
        $this->assertSame('aws emea', $rule->match_key);
        $this->assertSame(BankTransaction::TYPE_DEBIT, $rule->direction);
        $this->assertEquals($aws->id, $rule->supplier_id);
    }

    public function test_consumed_payments_are_excluded_from_candidates_and_rejected_by_id(): void
    {
        // One payment reconciles one bank line — once matched, it is
        // consumed: no longer offered as a candidate for other lines
        // (amount-close or search) and rejected as a by-id target.
        $acme = $this->client();
        $payment = $this->payment($acme, 1500.00, '2026-09-10');
        $firstLine = $this->bankLine(['payer_name' => 'Acme Corp']);
        $this->service->manualOverrideLink($firstLine, 'payment', $payment->id);

        $secondLine = $this->bankLine(['payer_name' => 'Acme Corp', 'reference' => null]);

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $secondLine))
            ->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->where('type', 'payment')->where('id', $payment->id)->isEmpty());

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', ['transaction' => $secondLine, 'q' => 'acme']))
            ->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->where('type', 'payment')->where('id', $payment->id)->isEmpty());

        $this->assertFalse($this->service->manualOverrideLink($secondLine, 'payment', $payment->id));
        $this->assertSame(BankTransaction::STATUS_PENDING, $secondLine->fresh()->status);

        // Unlinking the first line releases the payment again.
        $this->service->unlinkTransaction($firstLine);
        $this->assertTrue($this->service->manualOverrideLink($secondLine, 'payment', $payment->id));
    }

    public function test_matching_by_id_and_unmatching(): void
    {
        $acme = $this->client();
        $payment = $this->payment($acme, 1500.00, '2026-09-10');
        $line = $this->bankLine();

        $this->actingAs($this->user)
            ->post(route('reconciliation.match.store', $line), [
                'type' => 'payment',
                'target_id' => $payment->id,
                'notes' => 'Fee-adjusted match',
            ])
            ->assertSessionHas('success');

        // Matched lines cannot re-enter the match screen.
        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('error');

        $this->actingAs($this->user)
            ->post(route('reconciliation.unmatch', $line))
            ->assertSessionHas('success');

        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->status);
        $this->assertNull($line->matched_transaction_id);
        $this->assertNull($line->matched_transaction_type);
    }

    public function test_manual_match_teaches_the_counterparty_rule(): void
    {
        $acme = $this->client();
        $payment = $this->payment($acme, 1500.00, '2026-09-10');
        $line = $this->bankLine(['payer_name' => 'Acme Corp']);

        $this->service->manualOverrideLink($line, 'payment', $payment->id);

        $rule = ReconciliationCounterpartyRule::query()->firstOrFail();
        $this->assertSame('acme corp', $rule->match_key);
        $this->assertSame(BankTransaction::TYPE_CREDIT, $rule->direction);
        $this->assertEquals($acme->id, $rule->client_id);
        $this->assertNull($rule->supplier_id);
        $this->assertSame(1, $rule->times_matched);

        // Re-matching (after an unmatch, say) strengthens, not duplicates.
        $this->service->unlinkTransaction($line);
        $this->service->manualOverrideLink($line, 'payment', $payment->id);

        $this->assertSame(1, ReconciliationCounterpartyRule::count());
        $this->assertSame(2, $rule->fresh()->times_matched);
    }

    public function test_automatcher_uses_the_learned_rule_for_new_lines_from_the_same_payer(): void
    {
        $acme = $this->client();
        $augustPayment = $this->payment($acme, 1500.00, '2026-08-10');
        $septemberPayment = $this->payment($acme, 1200.00, '2026-09-10', ['reference' => 'DIFFERENT-REF']);

        // August's line was matched by hand — teaching the rule.
        $augustLine = $this->bankLine([
            'payer_name' => 'Acme Corp',
            'reference' => 'INV-AUG',
            'transaction_date' => Carbon::parse('2026-08-10'),
        ]);
        $this->service->manualOverrideLink($augustLine, 'payment', $augustPayment->id);

        // September's line names no reference the ledger knows, so only
        // the learned pass can pair it with September's fresh payment.
        $septemberLine = $this->bankLine([
            'payer_name' => 'Acme Corp',
            'reference' => null,
            'amount' => 1200.00,
        ]);

        $results = $this->service->autoMatchAll();

        $this->assertSame(1, $results['matched']);
        $septemberLine->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $septemberLine->status);
        $this->assertSame('payment', $septemberLine->matched_transaction_type);
        $this->assertEquals($septemberPayment->id, $septemberLine->matched_transaction_id);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $septemberLine->id,
            'action' => ReconciliationHistory::ACTION_AUTO_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_learned_matches_never_reuse_a_consumed_target(): void
    {
        $acme = $this->client();
        $payment = $this->payment($acme, 1500.00, '2026-09-10');

        $firstLine = $this->bankLine(['payer_name' => 'Acme Corp']);
        $this->service->manualOverrideLink($firstLine, 'payment', $payment->id);

        // Same counterparty, same amount/date — but the only candidate
        // payment is already consumed by the first line's match.
        $secondLine = $this->bankLine(['payer_name' => 'Acme Corp', 'reference' => null]);

        $results = $this->service->autoMatchAll();

        $this->assertSame(0, $results['matched']);
        $this->assertSame(BankTransaction::STATUS_PENDING, $secondLine->fresh()->status);
    }

    public function test_debit_lines_learn_the_supplier_and_match_new_supplier_payments(): void
    {
        $aws = $this->supplier();
        $septemberBill = $this->bill($aws, 89.00, '2026-09-08');
        $septemberPayment = $this->billPayment($aws, 89.00, '2026-09-10');

        // August was reconciled by hand against the supplier payment —
        // teaching the payee → supplier rule.
        $augustPayment = $this->billPayment($aws, 89.00, '2026-08-05');
        $augustLine = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -89.00,
            'transaction_date' => Carbon::parse('2026-08-05'),
            'reference' => 'AUG-SUB',
        ]);
        $this->service->manualOverrideLink($augustLine, 'bill_payment', $augustPayment->id);

        $rule = ReconciliationCounterpartyRule::query()->firstOrFail();
        $this->assertSame('aws emea', $rule->match_key);
        $this->assertEquals($aws->id, $rule->supplier_id);

        // September's line names no reference the ledger knows; the
        // learned pass must pair it with the fresh supplier payment —
        // the actual money movement — not the bill document.
        $septemberLine = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -89.00,
            'reference' => null,
        ]);

        $this->service->autoMatchAll();

        $septemberLine->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $septemberLine->status);
        $this->assertSame('bill_payment', $septemberLine->matched_transaction_type);
        $this->assertEquals($septemberPayment->id, $septemberLine->matched_transaction_id);
    }

    public function test_learned_rule_resolves_the_client_for_auto_created_receipts(): void
    {
        // The payer's bank descriptor shares no words with the client
        // name — only the learned rule can resolve it.
        $acme = $this->client(['name' => 'Acme Corporation Pty Ltd']);
        $payment = $this->payment($acme, 1500.00, '2026-09-10');
        $line = $this->bankLine(['payer_name' => 'ACME PTY LD']);
        $this->service->manualOverrideLink($line, 'payment', $payment->id);

        $nextLine = $this->bankLine([
            'payer_name' => 'ACME PTY LD',
            'reference' => null,
            'source_id' => 'WISE-NEXT',
        ]);

        $results = $this->service->autoCreateCashReceipts();

        $this->assertSame(1, $results['count']);
        $this->assertSame($acme->id, $results['created'][0]->client_id);
    }
}

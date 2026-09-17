<?php

namespace Modules\Reconciliation\Tests;

use App\Models\Bill;
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
 * and its amount-close/reference-search candidates, matching (by
 * candidate or by id) with history, unmatching, and counterparty rules
 * — a manual match teaches the payer/payee → client/supplier
 * association, and the auto-matcher's learned pass uses it to pair
 * later bank lines with a fresh unconsumed payment or bill.
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
        // amount-close fails, but the reference search finds it.
        $acme = $this->client();
        $payment = $this->payment($acme, 1490.50, '2026-09-12');
        $line = $this->bankLine(['payer_name' => 'Acme Corp', 'reference' => 'ACME-42']);

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertOk()
            ->assertDontSee($payment->payment_number);

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', ['transaction' => $line, 'q' => $payment->payment_number]))
            ->assertOk()
            ->assertSee($payment->payment_number);
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

    public function test_debit_lines_learn_the_supplier_and_match_new_bills(): void
    {
        $aws = $this->supplier();
        $augustBill = $this->bill($aws, 89.00, '2026-08-05');
        $septemberBill = $this->bill($aws, 89.00, '2026-09-08');

        $augustLine = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -89.00,
            'transaction_date' => Carbon::parse('2026-08-05'),
            'reference' => 'AUG-SUB',
        ]);
        $this->service->manualOverrideLink($augustLine, 'bill', $augustBill->id);

        $rule = ReconciliationCounterpartyRule::query()->firstOrFail();
        $this->assertSame('aws emea', $rule->match_key);
        $this->assertEquals($aws->id, $rule->supplier_id);

        $septemberLine = $this->bankLine([
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'AWS EMEA',
            'amount' => -89.00,
            'reference' => null,
        ]);

        $this->service->autoMatchAll();

        $septemberLine->refresh();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $septemberLine->status);
        $this->assertEquals($septemberBill->id, $septemberLine->matched_transaction_id);
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

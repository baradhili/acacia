<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAmendment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract document type: implied budgets (rate inc GST ×
 * business days × allocation% × 8) and numbered amendments, on the
 * shared purchase-order pipeline. Fixed-budget purchase orders keep
 * their existing draft-only behaviour.
 */
class PurchaseOrderContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
    }

    /**
     * A live contract over Mon 2026-10-05 → Fri 2026-10-09 (5 business
     * days) at $110/hr inc GST, 50% allocation: implied budget $2,200.
     */
    private function makeContract(array $overrides = []): PurchaseOrder
    {
        $contract = new PurchaseOrder;
        $contract->fill($overrides + [
            'type' => PurchaseOrder::TYPE_CONTRACT,
            'title' => 'Consulting services',
            'rate' => 110,
            'allocation' => 50,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'status' => PurchaseOrder::STATUS_OPEN,
        ]);
        $contract->client_id = $this->client->id;
        $contract->save();

        return $contract;
    }

    private function makePo(string $status = PurchaseOrder::STATUS_OPEN): PurchaseOrder
    {
        $po = new PurchaseOrder;
        $po->fill([
            'title' => 'Fixed budget work',
            'budgeted_amount' => 5000,
            'status' => $status,
        ]);
        $po->client_id = $this->client->id;
        $po->save();

        return $po;
    }

    public function test_business_days_counts_weekdays_inclusive_of_both_ends(): void
    {
        $this->assertSame(1, PurchaseOrder::businessDaysInclusive(
            Carbon::parse('2026-10-07'), Carbon::parse('2026-10-07'))); // Wed, same day

        $this->assertSame(6, PurchaseOrder::businessDaysInclusive(
            Carbon::parse('2026-10-05'), Carbon::parse('2026-10-12'))); // Mon → next Mon

        $this->assertSame(2, PurchaseOrder::businessDaysInclusive(
            Carbon::parse('2026-10-09'), Carbon::parse('2026-10-12'))); // Fri → Mon

        $this->assertSame(0, PurchaseOrder::businessDaysInclusive(
            Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11'))); // Sat → Sun

        $this->assertSame(22, PurchaseOrder::businessDaysInclusive(
            Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))); // Oct 2026

        $this->assertSame(0, PurchaseOrder::businessDaysInclusive(
            Carbon::parse('2026-10-09'), Carbon::parse('2026-10-05'))); // reversed
    }

    public function test_contract_budget_is_implied_from_its_terms(): void
    {
        $contract = $this->makeContract();

        $this->assertSame(5, $contract->business_days);
        $this->assertEquals(2200.00, (float) $contract->budgeted_amount);
    }

    public function test_creating_a_contract_over_http_computes_the_budget_and_numbers_it_ct(): void
    {
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'type' => 'contract',
            'client_id' => $this->client->id,
            'title' => 'Consulting services',
            'rate' => 110,
            'allocation' => 50,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ])->assertRedirect(route('purchase-orders.show', PurchaseOrder::first()));

        $contract = PurchaseOrder::first();
        $this->assertTrue($contract->isContract());
        $this->assertSame('CT-'.date('Y').'-0001', $contract->po_number);
        $this->assertEquals(2200.00, (float) $contract->budgeted_amount);
    }

    public function test_a_payload_without_a_type_is_a_purchase_order(): void
    {
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'client_id' => $this->client->id,
            'title' => 'Legacy payload',
            'budgeted_amount' => 5000,
        ])->assertRedirect();

        $this->assertFalse(PurchaseOrder::first()->isContract());
    }

    public function test_numbering_sequences_per_type(): void
    {
        $this->makeContract();

        $po = $this->makePo();
        $this->assertSame('PO-'.date('Y').'-0001', $po->po_number);

        $this->makeContract();
        $this->assertSame(
            'CT-'.date('Y').'-0002',
            PurchaseOrder::where('type', PurchaseOrder::TYPE_CONTRACT)->orderByDesc('id')->first()->po_number
        );
    }

    public function test_contract_validation_requires_rate_allocation_and_period(): void
    {
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'type' => 'contract',
            'client_id' => $this->client->id,
            'title' => 'No terms',
        ])->assertSessionHasErrors(['rate', 'allocation', 'start_date', 'end_date']);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_allocation_cannot_exceed_100_percent(): void
    {
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'type' => 'contract',
            'client_id' => $this->client->id,
            'title' => 'Over-allocated',
            'rate' => 110,
            'allocation' => 150,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ])->assertSessionHasErrors('allocation');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_purchase_orders_still_require_a_fixed_budget(): void
    {
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'type' => 'purchase_order',
            'client_id' => $this->client->id,
            'title' => 'No budget',
        ])->assertSessionHasErrors('budgeted_amount');
    }

    public function test_a_contract_never_takes_an_entered_budget(): void
    {
        // Even with budgeted_amount posted (the form disables the
        // field), the saving hook recomputes from the terms.
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'type' => 'contract',
            'client_id' => $this->client->id,
            'title' => 'Consulting services',
            'budgeted_amount' => 999999,
            'rate' => 110,
            'allocation' => 50,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ]);

        $this->assertEquals(2200.00, (float) PurchaseOrder::first()->budgeted_amount);
    }

    public function test_draft_contract_edits_in_place_without_creating_an_amendment(): void
    {
        $contract = $this->makeContract(['status' => PurchaseOrder::STATUS_DRAFT]);

        $this->actingAs($this->user)->post(route('purchase-orders.update', $contract), [
            '_method' => 'PUT',
            'client_id' => $this->client->id,
            'title' => 'Consulting services',
            'rate' => 220,
            'allocation' => 50,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ])->assertRedirect(route('purchase-orders.show', $contract));

        $contract->refresh();
        $this->assertEquals(4400.00, (float) $contract->budgeted_amount);
        $this->assertSame(0, $contract->amendments()->count());
    }

    public function test_amending_a_live_contract_snapshots_terms_and_recomputes_the_budget(): void
    {
        $contract = $this->makeContract();

        $this->actingAs($this->user)->post(route('purchase-orders.amend.store', $contract), [
            'rate' => 132,
            'allocation' => 75,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-16',
            'reason' => 'Client variation 001',
        ])->assertRedirect(route('purchase-orders.show', $contract));

        // 132 × 10 business days × 75% × 8h
        $contract->refresh();
        $this->assertEquals(7920.00, (float) $contract->budgeted_amount);

        $amendment = $contract->amendments()->first();
        $this->assertSame($contract->po_number.'-A1', $amendment->amendment_number);
        $this->assertEquals(110.00, (float) $amendment->previous_rate);
        $this->assertEquals(132.00, (float) $amendment->new_rate);
        $this->assertEquals(50.00, (float) $amendment->previous_allocation);
        $this->assertEquals(75.00, (float) $amendment->new_allocation);
        $this->assertEquals(2200.00, (float) $amendment->previous_budgeted_amount);
        $this->assertEquals(7920.00, (float) $amendment->new_budgeted_amount);
        $this->assertSame('Client variation 001', $amendment->reason);
        $this->assertSame($this->user->id, $amendment->user_id);

        // A second amendment numbers A2 against the new baseline.
        $this->actingAs($this->user)->post(route('purchase-orders.amend.store', $contract), [
            'rate' => 132,
            'allocation' => 75,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-23',
        ])->assertRedirect(route('purchase-orders.show', $contract));

        $this->assertSame(
            $contract->po_number.'-A2',
            PurchaseOrderAmendment::orderByDesc('id')->first()->amendment_number
        );
    }

    public function test_amendment_re_evaluates_status_against_the_new_budget(): void
    {
        $contract = $this->makeContract(); // $2,200 budget
        $contract->update(['used_amount' => 1100]); // 50% used

        // Shrink the budget below what is already consumed.
        $this->actingAs($this->user)->post(route('purchase-orders.amend.store', $contract), [
            'rate' => 55,
            'allocation' => 25,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ]);

        // 55 × 5 × 25% × 8 = $550 < $1,100 used → fully consumed
        $contract->refresh();
        $this->assertEquals(550.00, (float) $contract->budgeted_amount);
        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $contract->status);
    }

    public function test_purchase_orders_cannot_be_amended(): void
    {
        $po = $this->makePo();

        $this->actingAs($this->user)
            ->get(route('purchase-orders.amend', $po))
            ->assertRedirect(route('purchase-orders.show', $po));

        $this->actingAs($this->user)
            ->post(route('purchase-orders.amend.store', $po), [
                'rate' => 1,
                'allocation' => 100,
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-09',
            ])->assertRedirect(route('purchase-orders.show', $po));

        $this->assertSame(0, PurchaseOrderAmendment::count());
        $this->assertEquals(5000.00, (float) $po->refresh()->budgeted_amount);
    }

    public function test_draft_and_finished_contracts_are_not_amendable(): void
    {
        $draft = $this->makeContract(['status' => PurchaseOrder::STATUS_DRAFT]);
        $this->assertFalse($draft->canBeAmended());

        $completed = $this->makeContract(['status' => PurchaseOrder::STATUS_COMPLETED]);
        $this->assertFalse($completed->canBeAmended());

        $cancelled = $this->makeContract(['status' => PurchaseOrder::STATUS_CANCELLED]);
        $this->assertFalse($cancelled->canBeAmended());
    }

    public function test_non_draft_purchase_orders_still_cannot_be_edited(): void
    {
        $po = $this->makePo();

        $this->actingAs($this->user)->post(route('purchase-orders.update', $po), [
            '_method' => 'PUT',
            'client_id' => $this->client->id,
            'title' => 'Retitled',
            'budgeted_amount' => 1,
        ])->assertRedirect(route('purchase-orders.show', $po));

        $this->assertSame('Fixed budget work', $po->refresh()->title);
    }

    public function test_the_show_view_offers_amendment_only_for_live_contracts(): void
    {
        $contract = $this->makeContract();

        $response = $this->actingAs($this->user)->get(route('purchase-orders.show', $contract));
        $response->assertOk();
        $response->assertSee(__('purchase_orders.amend_contract'));
        $response->assertSee(__('purchase_orders.implied_budget_formula', [
            'days' => 5,
            'rate' => '$110.00',
            'allocation' => '50%',
        ]));

        // Purchase orders never show it.
        $po = $this->makePo();
        $this->actingAs($this->user)
            ->get(route('purchase-orders.show', $po))
            ->assertDontSee(__('purchase_orders.amend_contract'));

        // Nor finished contracts.
        $contract->update(['status' => PurchaseOrder::STATUS_COMPLETED]);
        $this->actingAs($this->user)
            ->get(route('purchase-orders.show', $contract))
            ->assertDontSee(__('purchase_orders.amend_contract'));
    }

    public function test_invoices_draw_down_a_contract_budget_like_a_purchase_order(): void
    {
        $contract = $this->makeContract();

        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->purchase_order_id = $contract->id;
        $invoice->save();
        $invoice->items()->create([
            'description' => 'Services',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->recalculateTotals();
        $invoice->update(['status' => Invoice::STATUS_SENT]);

        // $100 + GST = $110 inc GST counts against the inc-GST budget.
        $contract->refresh();
        $this->assertEquals(110.00, (float) $contract->used_amount);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_USED, $contract->status);
    }

    public function test_the_create_view_shows_the_document_type_selector(): void
    {
        $this->actingAs($this->user)
            ->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertSee(__('purchase_orders.document_type'))
            ->assertSee(__('purchase_orders.purchase_order'))
            ->assertSee(__('purchase_orders.contract'));
    }

    public function test_a_non_string_type_is_rejected_by_validation_not_a_500(): void
    {
        $this->actingAs($this->user)->post(route('purchase-orders.store'), [
            'type' => ['purchase_order'],
            'client_id' => $this->client->id,
            'title' => 'Malformed payload',
            'budgeted_amount' => 100,
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_amendment_numbers_are_unique_per_contract(): void
    {
        $contract = $this->makeContract();
        $snapshot = [
            'amendment_number' => $contract->po_number.'-A1',
            'previous_rate' => 110,
            'previous_allocation' => 50,
            'previous_start_date' => '2026-10-05',
            'previous_end_date' => '2026-10-09',
            'previous_budgeted_amount' => 2200,
            'new_rate' => 132,
            'new_allocation' => 75,
            'new_start_date' => '2026-10-05',
            'new_end_date' => '2026-10-16',
            'new_budgeted_amount' => 7920,
        ];
        $contract->amendments()->create($snapshot);

        $this->expectException(QueryException::class);
        $contract->amendments()->create($snapshot);
    }
}

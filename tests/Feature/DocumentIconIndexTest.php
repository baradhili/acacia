<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Proposals\Models\Estimate;
use Tests\TestCase;

class DocumentIconIndexTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->supplier = Supplier::factory()->create();
    }

    /**
     * Create a row for $class with explicit FK ownership — the *_id keys
     * in $attributes are unfillable after the mass-assignment hardening.
     */
    protected function make(string $class, array $attributes): object
    {
        $model = new $class;
        $model->fill(collect($attributes)->except(['client_id', 'supplier_id', 'purchase_order_id'])->all());
        foreach (['client_id', 'supplier_id', 'purchase_order_id'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $model->{$key} = $attributes[$key];
            }
        }
        $model->save();

        return $model;
    }

    protected function attach(object $model, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            Document::factory()->create([
                // getMorphClass() is the type string Eloquent itself
                // would store — the class name, or the registered alias
                // (Proposals maps Estimate back onto the legacy
                // 'App\Models\Estimate' string).
                'documentable_type' => $model->getMorphClass(),
                'documentable_id' => $model->id,
            ]);
        }
    }

    /**
     * The index at $uri must show the paperclip marker for the one row
     * that has a document — and only that row.
     */
    protected function assertSingleIcon(string $uri): void
    {
        $response = $this->actingAs($this->user)->get($uri);

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('title="1 document attached"', $html);
        $this->assertSame(
            1,
            substr_count($html, 'title="1 document attached"'),
            "Expected exactly one document icon on [{$uri}]."
        );
    }

    public function test_invoices_index_shows_document_icon(): void
    {
        $attributes = [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ];
        $this->attach($this->make(Invoice::class, $attributes));
        $this->make(Invoice::class, $attributes);

        $this->assertSingleIcon('/invoices');
    }

    public function test_bills_index_shows_document_icon(): void
    {
        $this->attach(Bill::createWithUniqueNumber(['supplier_id' => $this->supplier->id]));
        Bill::createWithUniqueNumber(['supplier_id' => $this->supplier->id]);

        $this->assertSingleIcon('/bills');
    }

    public function test_payments_index_shows_document_icon(): void
    {
        $attributes = [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ];
        $this->attach($this->make(Payment::class, $attributes));
        $this->make(Payment::class, $attributes);

        $this->assertSingleIcon('/payments');
    }

    public function test_bill_payments_index_shows_document_icon(): void
    {
        $attributes = [
            'supplier_id' => $this->supplier->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ];
        $this->attach($this->make(BillPayment::class, $attributes));
        $this->make(BillPayment::class, $attributes);

        $this->assertSingleIcon('/bill-payments');
    }

    public function test_clients_index_shows_document_icon(): void
    {
        $this->attach(Client::factory()->create());
        Client::factory()->create();

        $this->assertSingleIcon('/clients');
    }

    public function test_suppliers_index_shows_document_icon(): void
    {
        $this->attach(Supplier::factory()->create());
        Supplier::factory()->create();

        $this->assertSingleIcon('/suppliers');
    }

    public function test_estimates_index_shows_document_icon(): void
    {
        $this->attach($this->make(Estimate::class, ['client_id' => $this->client->id]));
        $this->make(Estimate::class, ['client_id' => $this->client->id]);

        $this->assertSingleIcon('/estimates');
    }

    public function test_purchase_orders_index_shows_document_icon(): void
    {
        $attributes = [
            'client_id' => $this->client->id,
            'title' => 'Test PO',
            'budgeted_amount' => 100,
            'start_date' => now()->toDateString(),
        ];
        $this->attach($this->make(PurchaseOrder::class, $attributes));
        $this->make(PurchaseOrder::class, $attributes);

        $this->assertSingleIcon('/purchase-orders');
    }

    public function test_document_icon_pluralises_for_multiple_documents(): void
    {
        $attributes = [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ];
        $this->attach($this->make(Invoice::class, $attributes), 2);

        $response = $this->actingAs($this->user)->get('/invoices');

        $response->assertOk();
        $this->assertStringContainsString('title="2 documents attached"', $response->getContent());
    }
}

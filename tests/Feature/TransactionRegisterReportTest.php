<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransactionRegisterReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'currency_id' => 1,
            // July FY start, matching the production seeder.
            'year_start' => 7,
            'multi_currency' => false,
        ]);

        // The entity's currency_id must reference a real currency row
        // for account creation (FK) to work.
        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        ReportingPeriod::create([
            'entity_id' => $this->entity->id,
            'year' => Carbon::now()->year,
            'calendar_year' => Carbon::now()->year,
            'period' => Carbon::now()->month,
            'period_count' => 1,
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
            'status' => ReportingPeriod::OPEN,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->entity_id = $this->entity->id;
        $this->admin->save();
        $this->admin->assignRole('admin');
    }

    /**
     * The register's two accounts (bank + revenue), created once and
     * shareable across many posted journals.
     */
    protected function registerAccounts(): array
    {
        $bank = Account::create([
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'account_type' => Account::BANK,
            'code' => '1000',
            'name' => 'Test Bank',
        ]);
        $revenue = Account::create([
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'account_type' => Account::OPERATING_REVENUE,
            'code' => '4100',
            'name' => 'Test Revenue',
        ]);

        return [$bank, $revenue];
    }

    /**
     * A posted journal with the bank account as its main (debit) side
     * and one revenue line item — the minimal two-leg transaction the
     * register must list once per leg. Pass $accounts to reuse an
     * existing pair instead of creating fresh ones.
     */
    protected function postJournal(string $reference, float $amount, ?array $accounts = null): array
    {
        [$bank, $revenue] = $accounts ?? $this->registerAccounts();

        $journal = new JournalEntry([
            'transaction_date' => Carbon::now(),
            'account_id' => $bank->id,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
            'narration' => 'Register test journal',
            'reference' => $reference,
        ]);
        $journal->addLineItem(LineItem::create([
            'account_id' => $revenue->id,
            'amount' => $amount,
            'quantity' => 1,
            'vat_inclusive' => true,
            'entity_id' => $this->entity->id,
        ]));
        $journal->post();

        return [$bank, $revenue];
    }

    public function test_register_page_lists_every_ledger_leg(): void
    {
        [$bank, $revenue] = $this->postJournal('PAY-2026-9001', 100.0);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.transaction-register'));

        $response->assertStatus(200);
        // One row per leg: the reference appears on both legs, each
        // account it posted to is listed, and the debit/credit split
        // shows the movement.
        $response->assertSee('PAY-2026-9001');
        $response->assertSee($bank->code.' - '.$bank->name);
        $response->assertSee($revenue->code.' - '.$revenue->name);
        $response->assertSee('100.00');
    }

    public function test_register_links_documents_attached_to_the_source_record(): void
    {
        $this->postJournal('PAY-2026-9002', 250.0);

        $payment = new Payment([
            'payment_number' => 'PAY-2026-9002',
            'amount' => 250.0,
            'payment_date' => Carbon::now()->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        // Ownership FK — unfillable by design, assigned explicitly.
        $payment->client_id = Client::factory()->create()->id;
        $payment->save();

        $document = new Document([
            'documentable_type' => Payment::class,
            'name' => 'receipt-9002.pdf',
            'file_path' => 'uploads/'.now()->format('Y/m').'/receipt-9002.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploaded_by' => $this->admin->id,
        ]);
        // Ownership FK — unfillable by design, assigned explicitly.
        $document->documentable_id = $payment->id;
        $document->save();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.transaction-register'));

        $response->assertStatus(200);
        $response->assertSee('receipt-9002.pdf');
        $response->assertSee(route('documents.download', $document));
    }

    public function test_register_links_documents_on_invoices_the_payment_settled(): void
    {
        // The usual shape: the document hangs off the invoice, and the
        // ledger transaction carries the payment's reference — the
        // register follows the allocation to surface the document.
        $this->postJournal('PAY-2026-9005', 400.0);

        $invoice = new Invoice([
            'invoice_number' => 'INV-2026-9005',
            'status' => 'paid',
            'issue_date' => Carbon::now()->toDateString(),
            'subtotal' => 400.0,
            'tax_amount' => 0.0,
            'total' => 400.0,
        ]);
        $invoice->client_id = Client::factory()->create()->id;
        $invoice->save();

        $payment = new Payment([
            'payment_number' => 'PAY-2026-9005',
            'amount' => 400.0,
            'payment_date' => Carbon::now()->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $payment->client_id = $invoice->client_id;
        $payment->save();

        $allocation = new PaymentAllocation(['amount' => 400.0]);
        $allocation->payment_id = $payment->id;
        $allocation->invoice_id = $invoice->id;
        $allocation->save();

        $document = new Document([
            'documentable_type' => Invoice::class,
            'name' => 'invoice-9005.pdf',
            'file_path' => 'uploads/'.now()->format('Y/m').'/invoice-9005.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'uploaded_by' => $this->admin->id,
        ]);
        $document->documentable_id = $invoice->id;
        $document->save();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.transaction-register'));

        $response->assertStatus(200);
        $response->assertSee('invoice-9005.pdf');

        $csv = $this->actingAs($this->admin)
            ->get(route('reports.export.transaction-register.csv'))
            ->streamedContent();
        $this->assertStringContainsString('invoice-9005.pdf', $csv);
        $this->assertStringContainsString(route('documents.download', $document), $csv);
    }

    public function test_register_csv_export_puts_reference_first(): void
    {
        $this->postJournal('PAY-2026-9003', 100.0);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.export.transaction-register.csv'));

        $response->assertStatus(200);

        $csv = explode("\n", trim($response->streamedContent()));
        $this->assertSame('Reference', str_getcsv($csv[0])[0]);
        // Header + two ledger legs.
        $this->assertCount(3, $csv);

        $dataRows = array_map('str_getcsv', array_slice($csv, 1));
        $this->assertSame('PAY-2026-9003', $dataRows[0][0]);
        $this->assertContains('100.00', $dataRows[0]);
    }

    public function test_register_screen_is_paginated(): void
    {
        // 101 journals = 202 legs: two full pages of 100 plus a stub.
        $accounts = $this->registerAccounts();
        for ($i = 0; $i <= 100; $i++) {
            $this->postJournal(sprintf('PAY-2026-9%03d', $i), 1.0, $accounts);
        }

        $first = $this->actingAs($this->admin)
            ->get(route('reports.transaction-register'));

        $first->assertOk();
        $rows = $first->viewData('rows');
        $this->assertSame(100, $rows->count());
        $this->assertSame(202, $rows->total());
        $this->assertSame(3, $rows->lastPage());

        // The summary cards carry the whole filtered set's totals,
        // not just the visible page.
        $this->assertSame(101.0, $first->viewData('totalDebit'));
        $this->assertSame(101.0, $first->viewData('totalCredit'));

        $last = $this->actingAs($this->admin)
            ->get(route('reports.transaction-register', ['page' => 3]));

        $last->assertOk();
        $this->assertSame(2, $last->viewData('rows')->count());
    }

    public function test_register_date_filters_scope_the_rows(): void
    {
        $this->postJournal('PAY-2026-9004', 100.0);

        // A window ending before the journal's date excludes it.
        $response = $this->actingAs($this->admin)
            ->get(route('reports.transaction-register', [
                'end_date' => Carbon::now()->subYear()->toDateString(),
            ]));

        $response->assertStatus(200);
        $response->assertDontSee('PAY-2026-9004');
        $response->assertSee(__('reports.transaction_register.no_rows'));
    }

    public function test_register_is_admin_only(): void
    {
        $accountant = User::factory()->create();
        $accountant->entity_id = $this->entity->id;
        $accountant->save();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->get(route('reports.transaction-register'))
            ->assertStatus(403);

        $this->actingAs($accountant)
            ->get(route('reports.export.transaction-register.csv'))
            ->assertStatus(403);
    }
}

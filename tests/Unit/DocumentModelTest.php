<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_table_has_morph_columns(): void
    {
        $columns = Schema::getColumnListing('documents');
        
        $this->assertContains('documentable_type', $columns);
        $this->assertContains('documentable_id', $columns);
    }

    public function test_documents_table_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('documents');
        
        $this->assertContains('name', $columns);
        $this->assertContains('file_path', $columns);
        $this->assertContains('mime_type', $columns);
        $this->assertContains('size', $columns);
    }

    public function test_documents_table_has_uploaded_by_column(): void
    {
        $columns = Schema::getColumnListing('documents');
        
        $this->assertContains('uploaded_by', $columns);
    }

    public function test_document_stores_original_filename(): void
    {
        $document = Document::factory()->create([
            'name' => 'original_receipt.pdf',
        ]);

        $this->assertEquals('original_receipt.pdf', $document->name);
    }

    public function test_document_stores_stored_filepath(): void
    {
        $document = Document::factory()->create([
            'file_path' => 'uploads/2024/06/test.pdf',
        ]);

        $this->assertEquals('uploads/2024/06/test.pdf', $document->file_path);
    }

    public function test_document_stores_mime_type(): void
    {
        $document = Document::factory()->create([
            'mime_type' => 'application/pdf',
        ]);

        $this->assertEquals('application/pdf', $document->mime_type);
    }

    public function test_document_stores_size(): void
    {
        $document = Document::factory()->create([
            'size' => 1024,
        ]);

        $this->assertEquals(1024, $document->size);
    }

    public function test_document_belongs_to_uploader(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create([
            'uploaded_by' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $document->uploadedBy);
        $this->assertEquals($user->id, $document->uploadedBy->id);
    }

    public function test_document_morph_to_parent_model(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        $document = Document::factory()->create([
            'documentable_type' => 'App\\Models\\Expense',
            'documentable_id' => $expense->id,
        ]);

        $this->assertInstanceOf(Expense::class, $document->documentable);
        $this->assertEquals($expense->id, $document->documentable->id);
    }

    public function test_expense_can_have_many_documents(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);

        Document::factory()->count(3)->create([
            'documentable_type' => 'App\\Models\\Expense',
            'documentable_id' => $expense->id,
        ]);

        $expense->refresh();
        $this->assertCount(3, $expense->documents);
    }

    public function test_invoice_can_have_many_documents(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-TEST',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        Document::factory()->count(2)->create([
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
        ]);

        $invoice->refresh();
        $this->assertCount(2, $invoice->documents);
    }

    public function test_payment_can_have_many_documents(): void
    {
        $client = Client::factory()->create();
        $payment = Payment::create([
            'client_id' => $client->id,
            'payment_number' => 'PAY-2024-TEST',
            'amount' => 500,
            'payment_date' => now(),
            'payment_method' => 'bank_transfer',
            'status' => 'completed',
        ]);

        Document::factory()->count(2)->create([
            'documentable_type' => 'App\\Models\\Payment',
            'documentable_id' => $payment->id,
        ]);

        $payment->refresh();
        $this->assertCount(2, $payment->documents);
    }

    public function test_purchase_order_can_have_many_documents(): void
    {
        $client = Client::factory()->create();
        $po = PurchaseOrder::create([
            'client_id' => $client->id,
            'po_number' => 'PO-2024-TEST',
            'title' => 'Test PO',
            'status' => 'draft',
            'budgeted_amount' => 5000,
            'used_amount' => 0,
        ]);

        Document::factory()->count(2)->create([
            'documentable_type' => 'App\\Models\\PurchaseOrder',
            'documentable_id' => $po->id,
        ]);

        $po->refresh();
        $this->assertCount(2, $po->documents);
    }

    public function test_documents_can_attach_to_different_model_types(): void
    {
        $supplier = Supplier::factory()->create();
        $client = Client::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-DIFF',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        Document::factory()->create([
            'documentable_type' => 'App\\Models\\Expense',
            'documentable_id' => $expense->id,
        ]);

        Document::factory()->create([
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
        ]);

        $this->assertEquals(1, Document::where('documentable_type', 'App\\Models\\Expense')->count());
        $this->assertEquals(1, Document::where('documentable_type', 'App\\Models\\Invoice')->count());
    }

    public function test_document_factory_creates_valid_instance(): void
    {
        $document = Document::factory()->create();
        
        $this->assertNotNull($document->name);
        $this->assertNotNull($document->file_path);
        $this->assertNotNull($document->mime_type);
        $this->assertNotNull($document->size);
    }
}

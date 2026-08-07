<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        
        Storage::fake('public');
    }

    public function test_document_index_requires_authentication(): void
    {
        $response = $this->get('/documents');
        $response->assertRedirect('/login');
    }

    public function test_can_upload_document_for_expense(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        
        $file = UploadedFile::fake()->create('receipt.pdf', 1024);
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Expense',
            'documentable_id' => $expense->id,
            'file' => $file,
        ]);
        
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'App\\Models\\Expense',
            'documentable_id' => $expense->id,
            'name' => 'receipt.pdf',
            'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_can_upload_document_for_invoice(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0001',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);
        
        $file = UploadedFile::fake()->create('invoice.pdf', 1024);
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Invoice',
            'documentable_id' => $invoice->id,
            'file' => $file,
        ]);
        
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
        ]);
    }

    public function test_document_upload_requires_file(): void
    {
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Expense',
            'documentable_id' => 1,
        ]);
        
        $response->assertStatus(422);
    }

    public function test_document_upload_validates_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.exe', 1024);
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Expense',
            'documentable_id' => 1,
            'file' => $file,
        ]);
        
        $response->assertStatus(422);
    }

    public function test_document_upload_validates_file_size(): void
    {
        $file = UploadedFile::fake()->create('large.pdf', 30000); // 30MB
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Expense',
            'documentable_id' => 1,
            'file' => $file,
        ]);
        
        $response->assertStatus(422);
    }

    public function test_can_get_documents_for_model(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        
        Document::factory()->count(3)->create([
            'documentable_type' => 'App\\Models\\Expense',
            'documentable_id' => $expense->id,
        ]);
        
        // Use the named route with short class name
        $response = $this->actingAs($this->user)->get(
            route('documents.for-model', [
                'type' => 'Expense',
                'id' => $expense->id
            ])
        );
        
        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    public function test_can_download_document(): void
    {
        $document = Document::factory()->create([
            'file_path' => 'uploads/test.pdf',
            'name' => 'test.pdf',
        ]);
        
        Storage::disk('public')->put('uploads/test.pdf', 'test content');
        
        $response = $this->actingAs($this->user)->get("/documents/{$document->id}/download");
        
        $response->assertStatus(200);
        $response->assertDownload('test.pdf');
    }

    public function test_download_returns_404_for_missing_file(): void
    {
        $document = Document::factory()->create([
            'file_path' => 'uploads/nonexistent.pdf',
        ]);
        
        $response = $this->actingAs($this->user)->get("/documents/{$document->id}/download");
        
        $response->assertStatus(404);
    }

    public function test_can_delete_document(): void
    {
        $document = Document::factory()->create([
            'file_path' => 'uploads/test.pdf',
        ]);
        
        Storage::disk('public')->put('uploads/test.pdf', 'test content');
        
        $response = $this->actingAs($this->user)->delete("/documents/{$document->id}");
        
        $response->assertStatus(200);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_delete_removes_file_from_storage(): void
    {
        Storage::disk('public')->put('uploads/test.pdf', 'test content');
        
        $document = Document::factory()->create([
            'file_path' => 'uploads/test.pdf',
        ]);
        
        $this->actingAs($this->user)->delete("/documents/{$document->id}");
        
        Storage::disk('public')->assertMissing('uploads/test.pdf');
    }

    public function test_document_model_relationships(): void
    {
        $document = Document::factory()->create([
            'uploaded_by' => $this->user->id,
        ]);
        
        $this->assertInstanceOf(User::class, $document->uploadedBy);
        $this->assertEquals($this->user->id, $document->uploadedBy->id);
    }

    public function test_document_polymorphic_relationship(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        
        $document = Document::factory()->create([
            'documentable_type' => 'App\Models\Expense',
            'documentable_id' => $expense->id,
        ]);
        
        $this->assertInstanceOf(Expense::class, $document->documentable);
        $this->assertEquals($expense->id, $document->documentable->id);
    }

    public function test_expense_has_documents_relationship(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        
        Document::factory()->count(2)->create([
            'documentable_type' => 'App\Models\Expense',
            'documentable_id' => $expense->id,
        ]);
        
        $expense->refresh();
        $this->assertCount(2, $expense->documents);
    }

    public function test_invoice_has_documents_relationship(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0002',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);
        
        Document::factory()->count(3)->create([
            'documentable_type' => 'App\Models\Invoice',
            'documentable_id' => $invoice->id,
        ]);
        
        $invoice->refresh();
        $this->assertCount(3, $invoice->documents);
    }

    public function test_can_upload_document_for_estimate(): void
    {
        $client = Client::factory()->create();
        $estimate = \App\Models\Estimate::create([
            'client_id' => $client->id,
            'estimate_number' => 'EST-2024-0001',
            'status' => 'draft',
            'issue_date' => now(),
            'valid_until' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'discount_amount' => 0,
            'total' => 110,
        ]);
        
        $file = UploadedFile::fake()->create('estimate.pdf', 1024);
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Estimate',
            'documentable_id' => $estimate->id,
            'file' => $file,
        ]);
        
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'App\Models\Estimate',
            'documentable_id' => $estimate->id,
        ]);
    }

    public function test_can_upload_document_for_purchase_order(): void
    {
        $client = Client::factory()->create();
        $po = \App\Models\PurchaseOrder::create([
            'client_id' => $client->id,
            'po_number' => 'PO-2024-0001',
            'title' => 'Test PO',
            'status' => 'draft',
            'budgeted_amount' => 5000,
            'used_amount' => 0,
        ]);
        
        $file = UploadedFile::fake()->create('po.pdf', 1024);
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'PurchaseOrder',
            'documentable_id' => $po->id,
            'file' => $file,
        ]);
        
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'App\Models\PurchaseOrder',
            'documentable_id' => $po->id,
        ]);
    }

    public function test_can_upload_document_for_payment(): void
    {
        $client = Client::factory()->create();
        $payment = \App\Models\Payment::create([
            'client_id' => $client->id,
            'payment_number' => 'PAY-2024-0001',
            'amount' => 500,
            'payment_date' => now(),
            'payment_method' => 'bank_transfer',
            'status' => 'completed',
        ]);
        
        $file = UploadedFile::fake()->create('receipt.pdf', 1024);
        
        $response = $this->actingAs($this->user)->post('/documents', [
            'documentable_type' => 'Payment',
            'documentable_id' => $payment->id,
            'file' => $file,
        ]);
        
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'App\Models\Payment',
            'documentable_id' => $payment->id,
        ]);
    }
}

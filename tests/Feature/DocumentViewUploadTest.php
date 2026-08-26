<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Client;
use App\Models\Document;
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

class DocumentViewUploadTest extends TestCase
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

    public function test_invoice_edit_view_displays_document_upload_form(): void
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

        $response = $this->actingAs($this->user)->get(route('invoices.edit', $invoice));

        $response->assertStatus(200);
        $response->assertSee('documentUploadArea');
        $response->assertSee('documentFile');
        $response->assertSee('documentable_type');
        $response->assertSee('documentable_id');
    }

    public function test_document_upload_input_accepts_multiple_files(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0008',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        $response = $this->actingAs($this->user)->get(route('invoices.edit', $invoice));

        $response->assertStatus(200);
        // A dropped multi-file selection must reach the upload handler,
        // so the input carries `multiple` and the JS passes the whole
        // FileList rather than files[0].
        $response->assertSee('id="documentFile" class="hidden" multiple', false);
        $response->assertSee('uploadFiles(e.dataTransfer.files)');
        $response->assertSee('uploadFiles(fileInput.files)');
        $response->assertDontSee('files[0]');
    }

    public function test_document_upload_component_renders_on_all_edit_pages(): void
    {
        // bills.edit loads expense accounts through the IFRS package's
        // EntityScope, which dereferences the authed user's entity.
        $entity = \IFRS\Models\Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);
        $this->user->update(['entity_id' => $entity->id]);

        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0009',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);
        $bill = Bill::create(['supplier_id' => Supplier::factory()->create()->id]);
        $payment = Payment::create([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'client_id' => $client->id,
            'po_number' => 'PO-2024-0001',
            'title' => 'Test PO',
            'status' => 'draft',
            'budgeted_amount' => 5000,
            'used_amount' => 0,
        ]);

        $pages = [
            [route('invoices.edit', $invoice), 'Invoice'],
            [route('bills.edit', $bill), 'Bill'],
            [route('payments.edit', $payment), 'Payment'],
            [route('clients.edit', $client), 'Client'],
            [route('suppliers.edit', Supplier::factory()->create()), 'Supplier'],
            [route('purchase-orders.edit', $purchaseOrder), 'PurchaseOrder'],
        ];

        foreach ($pages as [$url, $type]) {
            $response = $this->actingAs($this->user)->get($url);

            $response->assertStatus(200);
            $response->assertSee('documentUploadArea', false);
            $response->assertSee('uploadFiles(e.dataTransfer.files)');
            // The component derives the type from the model's class name.
            $response->assertSee('value="' . $type . '"', false);
        }
    }

    public function test_can_upload_document_for_invoice_via_view(): void
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

        // Use the real test PDF file
        $testPdfPath = base_path('tests/test upload doc.pdf');
        $this->assertFileExists($testPdfPath, 'Test PDF file should exist');

        $file = new UploadedFile(
            $testPdfPath,
            'test upload doc.pdf',
            'application/pdf',
            null,
            true // test mode
        );

        $response = $this->actingAs($this->user)->post(route('documents.store'), [
            'documentable_type' => 'Invoice',
            'documentable_id' => $invoice->id,
            'file' => $file,
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
            'name' => 'test upload doc.pdf',
            'uploaded_by' => $this->user->id,
        ]);

        // Verify the document can be retrieved
        $document = Document::where('documentable_type', 'App\\Models\\Invoice')
            ->where('documentable_id', $invoice->id)
            ->first();
        
        $this->assertNotNull($document);
        $this->assertEquals('test upload doc.pdf', $document->name);
    }

    public function test_invoice_view_shows_uploaded_documents(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0003',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        // Create a document for the invoice
        Document::factory()->create([
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
            'name' => 'Test Invoice Document.pdf',
            'uploaded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('invoices.show', $invoice));

        $response->assertStatus(200);
        $response->assertSee('Test Invoice Document.pdf');
        $response->assertSee('Download');
        // Delete is not shown on show view - only in edit view
        $response->assertDontSee('Delete');
    }

    public function test_can_delete_document_from_invoice_view(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0004',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        $document = Document::factory()->create([
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
            'name' => 'Delete Me.pdf',
            'uploaded_by' => $this->user->id,
        ]);

        Storage::disk('public')->put($document->file_path, 'test content');

        $response = $this->actingAs($this->user)->delete(
            route('documents.destroy', $document)
        );

        $response->assertStatus(200);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk('public')->assertMissing($document->file_path);
    }

    public function test_can_download_document_from_invoice_view(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0005',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        $document = Document::factory()->create([
            'documentable_type' => 'App\\Models\\Invoice',
            'documentable_id' => $invoice->id,
            'name' => 'Download Me.pdf',
            'file_path' => 'uploads/test.pdf',
            'uploaded_by' => $this->user->id,
        ]);

        Storage::disk('public')->put('uploads/test.pdf', 'test content');

        $response = $this->actingAs($this->user)->get(
            route('documents.download', $document)
        );

        $response->assertStatus(200);
        $response->assertDownload('Download Me.pdf');
    }

    public function test_invoice_edit_page_has_document_upload_scripts(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0006',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        $response = $this->actingAs($this->user)->get(route('invoices.edit', $invoice));

        $response->assertStatus(200);
        // Verify the page has proper script handling
        $response->assertSee('documentUploadArea');
        $response->assertSee('dragover');
        $response->assertSee('drop');
    }

    public function test_document_upload_with_real_pdf_file(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2024-0007',
            'status' => 'draft',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        // Use the exact test PDF file from /tests directory
        $testPdfPath = base_path('tests/test upload doc.pdf');
        
        $this->assertFileExists($testPdfPath, 'Test PDF file should exist at: ' . $testPdfPath);
        
        $originalContent = file_get_contents($testPdfPath);
        $this->assertStringStartsWith('%PDF', $originalContent, 'File should be a valid PDF');
        
        $file = new UploadedFile(
            $testPdfPath,
            'test upload doc.pdf',
            'application/pdf',
            null,
            true
        );

        $response = $this->actingAs($this->user)->post(route('documents.store'), [
            'documentable_type' => 'Invoice',
            'documentable_id' => $invoice->id,
            'file' => $file,
        ]);

        $response->assertStatus(201);

        $document = Document::where('documentable_type', 'App\\Models\\Invoice')
            ->where('documentable_id', $invoice->id)
            ->first();

        $this->assertNotNull($document);
        $this->assertEquals('test upload doc.pdf', $document->name);
        $this->assertEquals('application/pdf', $document->mime_type);
        
        // Verify the file was stored
        $this->assertTrue(Storage::disk('public')->exists($document->file_path));
    }
}

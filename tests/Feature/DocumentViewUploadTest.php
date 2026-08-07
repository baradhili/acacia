<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
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

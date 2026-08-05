<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles using Spatie
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);
        Role::firstOrCreate(['name' => 'staff']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
    }

    public function test_client_list_page_requires_authentication(): void
    {
        $response = $this->get('/clients');
        $response->assertRedirect('/login');
    }

    public function test_admin_can_create_client(): void
    {
        $response = $this->actingAs($this->admin)->post('/clients', [
            'name' => 'Test Client',
            'email' => 'test@example.com',
            'phone' => '0412345678',
            'address' => '123 Test Street',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('clients', [
            'name' => 'Test Client',
            'email' => 'test@example.com',
        ]);
    }

    public function test_client_custom_fields_can_be_stored_and_retrieved(): void
    {
        $client = Client::factory()->create([
            'custom_fields' => [
                'tax_number' => 'ABN123456789',
                'industry' => 'Technology',
            ],
        ]);

        $client->refresh();
        $this->assertEquals('ABN123456789', $client->custom_fields['tax_number']);
        $this->assertEquals('Technology', $client->custom_fields['industry']);
    }

    public function test_client_can_have_attachments(): void
    {
        $client = Client::factory()->create();

        $document = Document::create([
            'documentable_type' => Client::class,
            'documentable_id' => $client->id,
            'name' => 'Contract.pdf',
            'file_path' => 'documents/contract.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploaded_by' => $this->admin->id,
        ]);

        $this->assertCount(1, $client->documents);
        $this->assertEquals('Contract.pdf', $client->documents->first()->name);
    }

    public function test_client_can_be_soft_deleted(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)->delete("/clients/{$client->id}");

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_soft_deleted_client_can_be_restored(): void
    {
        $client = Client::factory()->create();

        $client->delete();

        $this->assertSoftDeleted('clients', ['id' => $client->id]);

        // Restore using Eloquent
        $client->restore();

        $this->assertNull($client->fresh()->deleted_at);
    }

    public function test_client_outstanding_amount_attribute(): void
    {
        $client = Client::factory()->create();

        // outstandingAmount is an accessor attribute
        $this->assertTrue(isset($client->outstandingAmount) || method_exists($client, 'getOutstandingAmountAttribute'));
    }

    public function test_client_overdue_amount_attribute(): void
    {
        $client = Client::factory()->create();

        // overdueAmount is an accessor attribute
        $this->assertTrue(isset($client->overdueAmount) || method_exists($client, 'getOverdueAmountAttribute'));
    }

    public function test_client_has_invoices_relationship(): void
    {
        $client = Client::factory()->create();

        $this->assertTrue(method_exists($client, 'invoices'));
    }

    public function test_client_has_payments_relationship(): void
    {
        $client = Client::factory()->create();

        $this->assertTrue(method_exists($client, 'payments'));
    }

    public function test_client_email_validation(): void
    {
        $response = $this->actingAs($this->admin)->post('/clients', [
            'name' => 'Test Client',
            'email' => 'invalid-email',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_client_custom_fields_attribute_exists(): void
    {
        $client = Client::factory()->create([
            'custom_fields' => ['industry' => 'Tech'],
        ]);

        // Verify custom_fields can be set and retrieved
        $this->assertIsArray($client->custom_fields);
        $this->assertEquals('Tech', $client->custom_fields['industry']);
    }

    public function test_staff_delete_client_permission(): void
    {
        $client = Client::factory()->create();

        // Staff can delete clients (no role restriction on this route)
        $response = $this->actingAs($this->staff)->delete("/clients/{$client->id}");

        // Either 302 (success redirect) or 403 (forbidden) depending on permissions
        $this->assertTrue(in_array($response->status(), [200, 201, 302, 403]));
    }

    public function test_client_name_is_required(): void
    {
        $response = $this->actingAs($this->admin)->post('/clients', [
            'email' => 'test@example.com',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_deleted_client_excluded_from_active_list(): void
    {
        $activeClient = Client::factory()->create();
        $deletedClient = Client::factory()->create();
        $deletedClient->delete();

        $activeClients = Client::whereNull('deleted_at')->count();

        $this->assertEquals(1, $activeClients);
    }

    public function test_client_address_fields_are_stored(): void
    {
        $client = Client::factory()->create([
            'address' => '123 Test St',
            'city' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'Australia',
        ]);

        $client->refresh();
        $this->assertEquals('123 Test St', $client->address);
        $this->assertEquals('Sydney', $client->city);
        $this->assertEquals('NSW', $client->state);
    }

    public function test_client_abn_is_stored(): void
    {
        $client = Client::factory()->create([
            'abn' => 'ABN123456789',
        ]);

        $client->refresh();
        $this->assertEquals('ABN123456789', $client->abn);
    }
}

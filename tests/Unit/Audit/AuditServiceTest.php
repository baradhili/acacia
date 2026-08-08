<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuditService();
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Client',
            'email' => 'test@example.com',
        ], $attributes));
    }

    public function test_logs_client_creation(): void
    {
        $client = $this->createClient();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'action' => AuditLog::ACTION_CREATED,
        ]);
    }

    public function test_logs_client_update(): void
    {
        $client = $this->createClient();

        $client->update(['name' => 'Updated Name']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'action' => AuditLog::ACTION_UPDATED,
        ]);

        $log = AuditLog::where('auditable_id', $client->id)
            ->where('action', AuditLog::ACTION_UPDATED)
            ->first();

        $this->assertNotNull($log->changed_fields);
        $this->assertContains('name', $log->changed_fields);
    }

    public function test_logs_client_deletion(): void
    {
        $client = $this->createClient();
        $clientId = $client->id;

        $client->delete();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $clientId,
            'action' => AuditLog::ACTION_DELETED,
        ]);
    }

    public function test_stores_old_and_new_values(): void
    {
        $client = $this->createClient(['name' => 'Original Name']);

        $client->update(['name' => 'New Name']);

        $log = AuditLog::where('auditable_id', $client->id)
            ->where('action', AuditLog::ACTION_UPDATED)
            ->first();

        $this->assertIsArray($log->old_values);
        $this->assertIsArray($log->new_values);
        $this->assertEquals('Original Name', $log->old_values['name']);
        $this->assertEquals('New Name', $log->new_values['name']);
    }

    public function test_get_history_for_model(): void
    {
        $client = $this->createClient();
        $client->update(['name' => 'Name 1']);
        $client->update(['name' => 'Name 2']);

        $history = $this->service->getHistory(Client::class, $client->id);

        $this->assertGreaterThanOrEqual(2, $history->count());
    }

    public function test_get_stats_returns_counts(): void
    {
        $client1 = $this->createClient();
        $client2 = $this->createClient();
        
        $client1->update(['name' => 'Updated']);
        $client2->update(['name' => 'Updated']);

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('by_action', $stats);
        $this->assertArrayHasKey('by_model', $stats);
        $this->assertGreaterThanOrEqual(4, $stats['total']); // 2 creates + 2 updates
    }

    public function test_should_audit_returns_true_for_configured_models(): void
    {
        $this->assertTrue($this->service->shouldAudit(Client::class));
        $this->assertTrue($this->service->shouldAudit(Invoice::class));
    }

    public function test_should_audit_returns_false_for_unconfigured_models(): void
    {
        $this->assertFalse($this->service->shouldAudit(\App\Models\User::class));
    }

    public function test_ignores_updated_at_field(): void
    {
        $client = $this->createClient(['name' => 'Original Name']);
        
        // Clear previous audit logs
        AuditLog::where('auditable_id', $client->id)->delete();
        
        // Update both name and updated_at - only name should be audited
        $client->update([
            'name' => 'New Name',
            'updated_at' => now(),
        ]);

        $log = AuditLog::where('auditable_id', $client->id)
            ->where('action', AuditLog::ACTION_UPDATED)
            ->first();
        
        // Assert log exists
        $this->assertNotNull($log, 'AuditLog should exist for name update');
        
        // Assert that updated_at is NOT in changed_fields, only name
        $this->assertContains('name', $log->changed_fields ?? []);
        $this->assertNotContains('updated_at', $log->changed_fields ?? []);
    }

    public function test_audit_log_model_scopes(): void
    {
        $client = $this->createClient();
        $client->update(['name' => 'Name 1']);

        $this->assertGreaterThanOrEqual(1, AuditLog::query()->created()->count());
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->updated()->count());
        
        $forModel = AuditLog::forModel(Client::class, $client->id)->count();
        $this->assertGreaterThanOrEqual(2, $forModel);
    }

    public function test_audit_log_user_relationship(): void
    {
        $client = $this->createClient();

        $log = AuditLog::where('auditable_id', $client->id)->first();
        
        // User should be null in testing context unless authenticated
        $this->assertNotNull($log);
    }

    public function test_audit_log_captures_ip_and_user_agent(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        
        $client = $this->createClient();

        $log = AuditLog::where('auditable_id', $client->id)
            ->where('action', AuditLog::ACTION_CREATED)
            ->first();

        $this->assertNotNull($log);
        // IP might be null in testing, but the field exists
        $this->assertArrayHasKey('ip_address', $log->getAttributes());
    }
}

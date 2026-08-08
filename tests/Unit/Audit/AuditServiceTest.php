<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\AuditService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
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

    public function test_logs_client_creation_to_syslog(): void
    {
        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'AUDIT'
                    && $context['action'] === AuditService::ACTION_CREATED
                    && $context['model']['type'] === Client::class
                    && isset($context['changes']['new_values']);
            });

        $this->createClient();
    }

    public function test_logs_client_update_to_syslog(): void
    {
        $client = $this->createClient();

        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) use ($client) {
                return $message === 'AUDIT'
                    && $context['action'] === AuditService::ACTION_UPDATED
                    && $context['model']['type'] === Client::class
                    && $context['model']['id'] === $client->id
                    && in_array('name', $context['changes']['changed_fields'] ?? []);
            });

        $client->update(['name' => 'Updated Name']);
    }

    public function test_logs_client_deletion_to_syslog(): void
    {
        $client = $this->createClient();
        $clientId = $client->id;

        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) use ($clientId) {
                return $message === 'AUDIT'
                    && $context['action'] === AuditService::ACTION_DELETED
                    && $context['model']['id'] === $clientId;
            });

        $client->delete();
    }

    public function test_audit_entry_contains_old_and_new_values(): void
    {
        $client = $this->createClient(['name' => 'Original Name']);

        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return isset($context['changes']['old_values']['name'])
                    && $context['changes']['old_values']['name'] === 'Original Name'
                    && isset($context['changes']['new_values']['name'])
                    && $context['changes']['new_values']['name'] === 'New Name';
            });

        $client->update(['name' => 'New Name']);
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

        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                // updated_at should NOT be in changed_fields
                $changedFields = $context['changes']['changed_fields'] ?? [];
                return in_array('name', $changedFields)
                    && !in_array('updated_at', $changedFields);
            });

        $client->update([
            'name' => 'New Name',
            'updated_at' => now(),
        ]);
    }

    public function test_audit_entry_captures_user_info(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) use ($user) {
                return $context['user']['id'] === $user->id
                    && $context['user']['name'] === $user->name;
            });

        $this->createClient();
    }

    public function test_audit_entry_contains_timestamp(): void
    {
        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return isset($context['timestamp'])
                    && strtotime($context['timestamp']) !== false;
            });

        $this->createClient();
    }

    public function test_audit_entry_contains_request_info(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());

        Log::shouldReceive('channel')
            ->with('syslog')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return isset($context['request'])
                    && array_key_exists('ip_address', $context['request'])
                    && array_key_exists('user_agent', $context['request']);
            });

        $this->createClient();
    }

    public function test_action_constants_are_defined(): void
    {
        $this->assertEquals('created', AuditService::ACTION_CREATED);
        $this->assertEquals('updated', AuditService::ACTION_UPDATED);
        $this->assertEquals('deleted', AuditService::ACTION_DELETED);
    }
}

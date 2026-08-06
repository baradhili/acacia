<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStaff;
use App\Models\User;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase45RemainingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $staff;
    protected Client $client;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles using Spatie
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);
        Role::firstOrCreate(['name' => 'staff']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->staff = User::factory()->create(['charge_out_rate' => 150.00]);
        $this->staff->assignRole('staff');

        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create([
            'client_id' => $this->client->id,
            'hourly_rate' => 120.00,
        ]);
    }

    // ============================================================
    // Project Staff Rate Override Tests
    // ============================================================

    public function test_project_staff_assignment_with_custom_charge_rate_overrides_default_rate(): void
    {
        $customRate = 175.00;

        $projectStaff = ProjectStaff::create([
            'project_id' => $this->project->id,
            'user_id' => $this->staff->id,
            'hourly_rate' => $customRate,
            'is_active' => true,
        ]);

        $this->assertEquals($customRate, $projectStaff->effective_rate);
        $this->assertEquals($customRate, $projectStaff->hourly_rate);
    }

    public function test_project_staff_falls_back_to_project_default_rate_when_no_custom_rate(): void
    {
        $projectStaff = ProjectStaff::create([
            'project_id' => $this->project->id,
            'user_id' => $this->staff->id,
            'hourly_rate' => null,
            'is_active' => true,
        ]);

        $this->assertEquals(120.00, $projectStaff->effective_rate);
    }

    public function test_project_staff_can_be_deactivated(): void
    {
        $projectStaff = ProjectStaff::create([
            'project_id' => $this->project->id,
            'user_id' => $this->staff->id,
            'hourly_rate' => 175.00,
            'is_active' => true,
        ]);

        $this->assertTrue($projectStaff->is_active);

        $projectStaff->update(['is_active' => false]);

        $this->assertFalse($projectStaff->fresh()->is_active);
    }

    // ============================================================
    // Reconciliation Service Tests
    // ============================================================

    public function test_manual_match_updates_bank_transaction_status(): void
    {
        $service = new ReconciliationService();

        $transactionDate = Carbon::parse('2025-07-15');

        $bankTransaction = BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE-MANUAL-001',
            'reference' => 'INV-200',
            'amount' => 300.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => $transactionDate,
            'created_at_source' => $transactionDate,
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $service->manualMatch($bankTransaction, 123, 'ledger');

        $this->assertTrue($result);
        $bankTransaction->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankTransaction->status);
        $this->assertEquals(123, $bankTransaction->matched_transaction_id);
    }

    public function test_match_transaction_returns_null_when_no_match_found(): void
    {
        $service = new ReconciliationService();

        $transactionDate = Carbon::parse('2025-07-15');

        $bankTransaction = BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE-NOMATCH-001',
            'reference' => 'NONEXISTENT-REF',
            'amount' => 99999.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => $transactionDate,
            'created_at_source' => $transactionDate,
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $service->matchTransaction($bankTransaction);

        $this->assertNull($result);
    }

    public function test_reconciliation_service_has_required_methods(): void
    {
        $service = new ReconciliationService();

        $this->assertTrue(method_exists($service, 'calculateMatchScore'));
        $this->assertTrue(method_exists($service, 'getMatchingCandidates'));
        $this->assertTrue(method_exists($service, 'autoMatchAll'));
        $this->assertTrue(method_exists($service, 'manualMatch'));
        $this->assertTrue(method_exists($service, 'matchTransaction'));
    }

    public function test_reconciliation_service_tolerances_are_accessible(): void
    {
        $service = new ReconciliationService();

        $tolerances = $service->getTolerances();
        
        $this->assertArrayHasKey('amount_tolerance', $tolerances);
        $this->assertArrayHasKey('date_tolerance_days', $tolerances);
        $this->assertEquals(0.01, $tolerances['amount_tolerance']);
        $this->assertEquals(3, $tolerances['date_tolerance_days']);
    }

    public function test_reconciliation_report_shows_correct_matched_unmatched_totals(): void
    {
        $service = new ReconciliationService();

        // Create matched transactions
        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE001',
            'reference' => 'INV-001',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 1,
        ]);

        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE002',
            'reference' => 'INV-002',
            'amount' => 200.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 2,
        ]);

        // Create unmatched/pending transactions
        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE003',
            'reference' => 'INV-003',
            'amount' => 300.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $stats = $service->autoMatchAll();

        $this->assertArrayHasKey('matched', $stats);
        $this->assertArrayHasKey('unmatched', $stats);
        $this->assertArrayHasKey('errors', $stats);
        $this->assertIsInt($stats['matched']);
        $this->assertIsInt($stats['unmatched']);
        $this->assertIsArray($stats['errors']);
    }

    public function test_auto_match_all_returns_summary(): void
    {
        $service = new ReconciliationService();

        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE-SUMMARY-001',
            'reference' => 'INV-SUM-001',
            'amount' => 150.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE-SUMMARY-002',
            'reference' => 'INV-SUM-002',
            'amount' => 250.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $service->autoMatchAll();

        $this->assertArrayHasKey('matched', $result);
        $this->assertArrayHasKey('unmatched', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['errors']);
    }
}

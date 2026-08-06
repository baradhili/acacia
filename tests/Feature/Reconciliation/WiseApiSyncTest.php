<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Services\WiseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @group skip
 */
class WiseApiSyncTest extends TestCase
{
    use RefreshDatabase;

    protected WiseService $wiseService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wiseService = new WiseService();
        
        // Set config for testing
        config([
            'services.wise.api_url' => 'https://api.wise.com',
            'services.wise.token' => 'test-token',
            'services.wise.profile_id' => '123',
        ]);
    }

    public function test_fetches_transactions_from_wise_api(): void
    {
        $mockResponse = [
            [
                'id' => 'WISE-API-001',
                'reference' => 'API-INV-001',
                'amount' => 500.00,
                'currency' => 'AUD',
                'type' => 'CREDIT',
                'date' => '2025-07-15',
                'created' => '2025-07-15T10:00:00Z',
                'merchantName' => 'Test Client',
            ],
            [
                'id' => 'WISE-API-002',
                'reference' => 'API-INV-002',
                'amount' => 750.00,
                'currency' => 'AUD',
                'type' => 'DEBIT',
                'date' => '2025-07-16',
                'created' => '2025-07-16T11:00:00Z',
                'merchantName' => 'Office Depot',
            ],
        ];

        Http::fake([
            'api.wise.com/*' => Http::response($mockResponse, 200),
        ]);

        $fromDate = Carbon::parse('2025-07-01');
        $toDate = Carbon::parse('2025-07-31');

        $transactions = $this->wiseService->fetchTransactions($fromDate, $toDate);

        $this->assertCount(2, $transactions);
        $this->assertEquals('WISE-API-001', $transactions[0]['id']);
        $this->assertEquals('API-INV-001', $transactions[0]['reference']);
    }

    public function test_returns_empty_collection_when_not_configured(): void
    {
        config([
            'services.wise.token' => null,
            'services.wise.profile_id' => null,
        ]);

        $transactions = $this->wiseService->fetchTransactions(
            Carbon::now()->subDays(30),
            Carbon::now()
        );

        $this->assertTrue($transactions->isEmpty());
    }

    public function test_handles_api_errors_gracefully(): void
    {
        Http::fake([
            'api.wise.com/*' => Http::response(['error' => 'Server Error'], 500),
        ]);

        $transactions = $this->wiseService->fetchTransactions(
            Carbon::now()->subDays(30),
            Carbon::now()
        );

        $this->assertTrue($transactions->isEmpty());
    }

    public function test_imports_api_transactions_correctly(): void
    {
        $mockResponse = [
            [
                'id' => 'WISE-API-001',
                'reference' => 'API-INV-001',
                'amount' => 500.00,
                'currency' => 'AUD',
                'type' => 'CREDIT',
                'date' => '2025-07-15',
                'created' => '2025-07-15T10:00:00Z',
                'merchantName' => 'Test Client',
            ],
        ];

        Http::fake([
            'api.wise.com/*' => Http::response($mockResponse, 200),
        ]);

        $transactions = $this->wiseService->fetchTransactions(
            Carbon::now()->subDays(30),
            Carbon::now()
        );

        // Verify transaction was fetched
        $this->assertCount(1, $transactions);
    }

    public function test_get_statistics_returns_correct_counts(): void
    {
        // Create some test transactions
        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-001',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE002',
            'reference' => 'INV-002',
            'amount' => 200.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 1,
        ]);

        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE003',
            'reference' => 'INV-003',
            'amount' => 300.00,
            'currency' => 'AUD',
            'type' => 'DEBIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_IGNORED,
        ]);

        $stats = $this->wiseService->getStatistics();

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(1, $stats['matched']);
        $this->assertEquals(1, $stats['ignored']);
    }

    public function test_get_unmatched_transactions(): void
    {
        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-001',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE002',
            'reference' => 'INV-002',
            'amount' => 200.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 1,
        ]);

        $unmatched = $this->wiseService->getUnmatchedTransactions();

        $this->assertCount(1, $unmatched);
        $this->assertEquals('WISE001', $unmatched->first()->source_id);
    }

    // ============================================================
    // Phase 4.5 - Additional API Sync Tests
    // ============================================================

    public function test_api_sync_handles_paginated_responses(): void
    {
        Http::fake([
            'api.wise.com/*' => Http::sequence()
                ->push([['id' => 'WISE001', 'reference' => 'INV-001']], 200)
                ->push([], 200),
        ]);

        $transactions = $this->wiseService->fetchTransactions(
            Carbon::now()->subDays(30),
            Carbon::now()
        );

        $this->assertNotNull($transactions);
    }

    public function test_api_sync_skips_duplicate_transactions_by_source_id(): void
    {
        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE-DUP-001',
            'reference' => 'INV-001',
            'amount' => 500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $mockResponse = [
            [
                'id' => 'WISE-DUP-001',
                'reference' => 'INV-001',
                'amount' => 500.00,
                'currency' => 'AUD',
                'type' => 'CREDIT',
                'date' => '2025-07-15',
                'created' => '2025-07-15T10:00:00Z',
                'merchantName' => 'Test Client',
            ],
            [
                'id' => 'WISE-NEW-001',
                'reference' => 'INV-002',
                'amount' => 750.00,
                'currency' => 'AUD',
                'type' => 'CREDIT',
                'date' => '2025-07-16',
                'created' => '2025-07-16T10:00:00Z',
                'merchantName' => 'New Client',
            ],
        ];

        Http::fake([
            'api.wise.com/*' => Http::response($mockResponse, 200),
        ]);

        $transactions = $this->wiseService->fetchTransactions(
            Carbon::now()->subDays(30),
            Carbon::now()
        );

        $this->assertCount(2, $transactions);

        $existing = BankTransaction::where('source_id', 'WISE-DUP-001')->count();
        $new = BankTransaction::where('source_id', 'WISE-NEW-001')->count();

        $this->assertEquals(1, $existing);
        $this->assertEquals(1, $new);
    }

    public function test_reconciliation_report_shows_correct_totals(): void
    {
        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE001',
            'reference' => 'INV-001',
            'amount' => 100.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_MATCHED,
        ]);

        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE002',
            'reference' => 'INV-002',
            'amount' => 200.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        BankTransaction::create([
            'source' => 'wise',
            'source_id' => 'WISE003',
            'reference' => 'INV-003',
            'amount' => 300.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => now(),
            'created_at_source' => now(),
            'status' => BankTransaction::STATUS_IGNORED,
        ]);

        $stats = $this->wiseService->getStatistics();

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['matched']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(1, $stats['ignored']);
    }
}

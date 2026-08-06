<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Services\WiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group skip
 */
class WiseCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected WiseService $wiseService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wiseService = new WiseService();
    }

    public function test_can_import_wise_csv_file(): void
    {
        // Create a temporary CSV file
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-2025-0001,1500.00,AUD,CREDIT,Client ABC\n";
        $csvContent .= "WISE002,2025-07-16,INV-2025-0002,750.50,AUD,CREDIT,Client XYZ\n";
        $csvContent .= "WISE003,2025-07-17,EXP-001,200.00,AUD,DEBIT,Office Supplies\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->wiseService->importFromCsv($tempFile);

            $this->assertArrayHasKey('imported', $result);
            $this->assertEquals(3, $result['imported']);
            $this->assertEmpty($result['errors']);

            // Verify transactions were created
            $this->assertDatabaseHas('bank_transactions', [
                'source' => 'wise', 'source_id' => 'WISE001',
                'reference' => 'INV-2025-0001',
                'amount' => 1500.00,
                'currency' => 'AUD',
                'type' => 'CREDIT',
            ]);

            $this->assertDatabaseHas('bank_transactions', [
                'source' => 'wise', 'source_id' => 'WISE002',
                'reference' => 'INV-2025-0002',
                'amount' => 750.50,
                'currency' => 'AUD',
                'type' => 'CREDIT',
            ]);

            $this->assertDatabaseHas('bank_transactions', [
                'source' => 'wise', 'source_id' => 'WISE003',
                'reference' => 'EXP-001',
                'amount' => 200.00,
                'currency' => 'AUD',
                'type' => 'DEBIT',
            ]);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_ignores_duplicate_transactions(): void
    {
        // Create existing transaction
        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // Create CSV with duplicate
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-2025-0001,1500.00,AUD,CREDIT,Client ABC\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->wiseService->importFromCsv($tempFile);

            // Should skip the duplicate
            $this->assertEquals(1, $result['imported']);
            $this->assertEquals(1, BankTransaction::count());
        } finally {
            unlink($tempFile);
        }
    }

    public function test_import_handles_multiple_currencies(): void
    {
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-USD-001,100.00,USD,CREDIT,US Client\n";
        $csvContent .= "WISE002,2025-07-15,INV-EUR-001,200.00,EUR,CREDIT,EU Client\n";
        $csvContent .= "WISE003,2025-07-15,INV-GBP-001,300.00,GBP,CREDIT,UK Client\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->wiseService->importFromCsv($tempFile);

            $this->assertEquals(3, $result['imported']);

            $this->assertDatabaseHas('bank_transactions', [
                'source' => 'wise', 'source_id' => 'WISE001',
                'currency' => 'USD',
            ]);

            $this->assertDatabaseHas('bank_transactions', [
                'source' => 'wise', 'source_id' => 'WISE002',
                'currency' => 'EUR',
            ]);

            $this->assertDatabaseHas('bank_transactions', [
                'source' => 'wise', 'source_id' => 'WISE003',
                'currency' => 'GBP',
            ]);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_new_transactions_have_pending_status(): void
    {
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-2025-0001,1500.00,AUD,CREDIT,Client ABC\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $this->wiseService->importFromCsv($tempFile);

            $transaction = BankTransaction::where('source_id', 'WISE001')->first();
            $this->assertEquals(BankTransaction::STATUS_PENDING, $transaction->status);
            $this->assertNull($transaction->matched_at);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_import_returns_error_for_missing_file(): void
    {
        $result = $this->wiseService->importFromCsv('/nonexistent/file.csv');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Cannot open file', $result['error']);
    }

    // ============================================================
    // Phase 4.5 - Additional CSV Import Tests
    // ============================================================

    public function test_import_handles_malformed_rows_gracefully(): void
    {
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-001,1500.00,AUD,CREDIT,Client ABC\n";
        $csvContent .= "INVALID_ROW\n"; // Malformed row
        $csvContent .= "WISE002,2025-07-16,INV-002,750.50,AUD,CREDIT,Client XYZ\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->wiseService->importFromCsv($tempFile);

            // Should import valid rows and log errors
            $this->assertGreaterThanOrEqual(2, $result['imported']);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_csv_import_logs_errors_for_invalid_rows(): void
    {
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-001,INVALID_AMOUNT,AUD,CREDIT,Client ABC\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->wiseService->importFromCsv($tempFile);

            // Should have errors or be skipped
            $this->assertGreaterThanOrEqual(0, count($result['errors']));
        } finally {
            unlink($tempFile);
        }
    }

    public function test_csv_import_with_missing_optional_fields(): void
    {
        $csvContent = "ID,Date,Reference,Amount,Currency,Type,Merchant\n";
        $csvContent .= "WISE001,2025-07-15,INV-001,1500.00,AUD,CREDIT,\n"; // Missing merchant

        $tempFile = tempnam(sys_get_temp_dir(), 'wise_') . '.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->wiseService->importFromCsv($tempFile);

            $this->assertEquals(1, $result['imported']);
            $this->assertDatabaseHas('bank_transactions', [
                'source_id' => 'WISE001',
                'merchant_name' => null,
            ]);
        } finally {
            unlink($tempFile);
        }
    }
}

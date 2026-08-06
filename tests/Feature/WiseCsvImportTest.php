<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Services\WiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WiseCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected WiseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WiseService();
    }

    public function test_imports_credit_transaction_from_csv(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $result = $this->service->importFromCsv($csvPath);

        $this->assertGreaterThan(0, $result['imported']);
        $this->assertEmpty($result['errors']);
        
        // Check first transaction is imported
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::SOURCE_WISE, $transaction->source);
        $this->assertEquals('CREDIT', $transaction->type);
        $this->assertEquals(4752, $transaction->amount);
        $this->assertEquals('AUD', $transaction->currency);
        $this->assertEquals('OMEGABANK AUSTR', $transaction->payer_name);
    }

    public function test_imports_multiple_transactions_from_csv(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $result = $this->service->importFromCsv($csvPath);

        $this->assertEquals(6, $result['imported']);
        $this->assertEquals(6, BankTransaction::count());
    }

    public function test_skips_duplicate_transactions(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        // Import once
        $this->service->importFromCsv($csvPath);
        
        // Import again - should skip duplicates
        $result = $this->service->importFromCsv($csvPath);

        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals(6, BankTransaction::count());
    }

    public function test_parses_date_format_dd_mm_yyyy(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $this->service->importFromCsv($csvPath);

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertEquals('2026-08-05', $transaction->transaction_date->format('Y-m-d'));
    }

    public function test_extracts_reference_from_description(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $this->service->importFromCsv($csvPath);

        // Transaction with reference in description: "500151142"
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertEquals('500151142', $transaction->reference);
    }

    public function test_imports_payer_name(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $this->service->importFromCsv($csvPath);

        // Transaction with payer: "OMEGABANK AUSTR"
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertEquals('OMEGABANK AUSTR', $transaction->payer_name);
    }

    public function test_handles_comma_in_description(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $this->service->importFromCsv($csvPath);

        // Transaction with comma in description
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2287008764')->first();
        $this->assertNotNull($transaction);
        $this->assertStringContainsString('Harris, Lucas', $transaction->description);
    }

    public function test_returns_error_for_missing_file(): void
    {
        $this->expectException(\ErrorException::class);
        $this->service->importFromCsv('/nonexistent/path.csv');
    }

    public function test_bank_transaction_factory(): void
    {
        $transaction = BankTransaction::factory()->create();
        
        $this->assertNotNull($transaction->source);
        $this->assertNotNull($transaction->source_id);
        $this->assertNotNull($transaction->amount);
        $this->assertNotNull($transaction->currency);
        $this->assertNotNull($transaction->type);
        $this->assertNotNull($transaction->transaction_date);
        $this->assertEquals(BankTransaction::STATUS_PENDING, $transaction->status);
    }

    public function test_bank_transaction_pending_scope(): void
    {
        BankTransaction::factory()->pending()->count(3)->create();
        BankTransaction::factory()->matched()->count(2)->create();

        $pending = BankTransaction::pending()->count();
        $this->assertEquals(3, $pending);
    }

    public function test_bank_transaction_matched_scope(): void
    {
        BankTransaction::factory()->pending()->count(3)->create();
        BankTransaction::factory()->matched()->count(2)->create();

        $matched = BankTransaction::matched()->count();
        $this->assertEquals(2, $matched);
    }

    public function test_mark_as_matched(): void
    {
        $transaction = BankTransaction::factory()->pending()->create();
        
        $transaction->markAsMatched(123, 'invoice');

        $this->assertEquals(BankTransaction::STATUS_MATCHED, $transaction->status);
        $this->assertEquals(123, $transaction->matched_transaction_id);
        $this->assertEquals('invoice', $transaction->matched_transaction_type);
        $this->assertNotNull($transaction->matched_at);
    }

    public function test_mark_as_ignored(): void
    {
        $transaction = BankTransaction::factory()->pending()->create();
        
        $transaction->markAsIgnored('Duplicate entry');

        $this->assertEquals(BankTransaction::STATUS_IGNORED, $transaction->status);
        $this->assertEquals('Duplicate entry', $transaction->notes);
    }

    public function test_is_matched(): void
    {
        $transaction = BankTransaction::factory()->matched()->create();
        
        $this->assertTrue($transaction->isMatched());
    }

    public function test_credit_transaction_has_positive_amount(): void
    {
        $transaction = BankTransaction::factory()->credit()->create();
        
        $this->assertGreaterThan(0, $transaction->amount);
        $this->assertEquals(BankTransaction::TYPE_CREDIT, $transaction->type);
    }

    public function test_debit_transaction_has_negative_amount(): void
    {
        $transaction = BankTransaction::factory()->debit()->create();
        
        $this->assertLessThan(0, $transaction->amount);
        $this->assertEquals(BankTransaction::TYPE_DEBIT, $transaction->type);
    }

    public function test_from_wise_source(): void
    {
        $transaction = BankTransaction::factory()->fromWise()->create();
        
        $this->assertEquals(BankTransaction::SOURCE_WISE, $transaction->source);
    }

    public function test_from_manual_source(): void
    {
        $transaction = BankTransaction::factory()->manual()->create();
        
        $this->assertEquals(BankTransaction::SOURCE_MANUAL, $transaction->source);
    }

    public function test_get_unmatched_transactions(): void
    {
        BankTransaction::factory()->pending()->count(3)->create();
        BankTransaction::factory()->matched()->count(2)->create();

        $unmatched = $this->service->getUnmatchedTransactions();
        
        $this->assertCount(3, $unmatched);
    }

    public function test_get_statistics(): void
    {
        BankTransaction::factory()->pending()->count(5)->create();
        BankTransaction::factory()->matched()->count(3)->create();
        BankTransaction::factory()->ignored()->count(2)->create();

        $stats = $this->service->getStatistics();
        
        $this->assertEquals(10, $stats['total']);
        $this->assertEquals(5, $stats['pending']);
        $this->assertEquals(3, $stats['matched']);
        $this->assertEquals(2, $stats['ignored']);
    }

    public function test_all_transactions_have_transaction_date(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $this->service->importFromCsv($csvPath);

        $transactions = BankTransaction::all();
        foreach ($transactions as $transaction) {
            $this->assertNotNull($transaction->transaction_date);
        }
    }

    public function test_all_transactions_have_status(): void
    {
        $csvPath = __DIR__ . '/../statement_test_AUD_2026-07-01_2026-08-06.csv';
        
        $this->service->importFromCsv($csvPath);

        $transactions = BankTransaction::all();
        foreach ($transactions as $transaction) {
            $this->assertContains($transaction->status, [
                BankTransaction::STATUS_PENDING,
                BankTransaction::STATUS_MATCHED,
                BankTransaction::STATUS_IGNORED,
            ]);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\User;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WiseCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReconciliationService::class);
    }

    private function historyFixture(): string
    {
        return __DIR__.'/../transaction_history_sample.csv';
    }

    public function test_imports_credit_transaction_from_csv(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

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
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        $result = $this->service->importFromCsv($csvPath);

        $this->assertEquals(6, $result['imported']);
        $this->assertEquals(6, BankTransaction::count());
    }

    public function test_skips_duplicate_transactions(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        // Import once
        $this->service->importFromCsv($csvPath);

        // Import again - duplicates are skipped, nothing new created
        $result = $this->service->importFromCsv($csvPath);

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(6, $result['skipped']);
        $this->assertEquals(6, BankTransaction::count());
    }

    public function test_parses_date_format_dd_mm_yyyy(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        $this->service->importFromCsv($csvPath);

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertEquals('2026-08-05', $transaction->transaction_date->format('Y-m-d'));
    }

    public function test_extracts_reference_from_description(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        $this->service->importFromCsv($csvPath);

        // Transaction with reference in description: "500151142"
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertEquals('500151142', $transaction->reference);
    }

    public function test_imports_payer_name(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        $this->service->importFromCsv($csvPath);

        // Transaction with payer: "OMEGABANK AUSTR"
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2292277869')->first();
        $this->assertEquals('OMEGABANK AUSTR', $transaction->payer_name);
    }

    public function test_handles_comma_in_description(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        $this->service->importFromCsv($csvPath);

        // Transaction with comma in description
        $transaction = BankTransaction::where('source_id', 'TRANSFER-2287008764')->first();
        $this->assertNotNull($transaction);
        $this->assertStringContainsString('Harris, Lucas', $transaction->description);
    }

    public function test_returns_error_for_missing_file(): void
    {
        $result = $this->service->importFromCsv('/nonexistent/path.csv');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Cannot open file', $result['error']);
    }

    public function test_returns_error_for_unrecognised_format(): void
    {
        $csv = "foo,bar\n1,2\n";
        $tempFile = tempnam(sys_get_temp_dir(), 'stmt_').'.csv';
        file_put_contents($tempFile, $csv);

        try {
            $result = $this->service->importFromCsv($tempFile);

            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('Unrecognised statement format', $result['error']);
        } finally {
            unlink($tempFile);
        }
    }

    // ============================================================
    // Current Wise download: transaction-history.csv
    // ============================================================

    public function test_imports_transaction_history_csv(): void
    {
        $result = $this->service->importFromCsv($this->historyFixture());

        // 22 rows: 20 COMPLETED movements, 2 REFUNDED zero-amount rows skipped
        $this->assertEquals(20, $result['imported']);
        $this->assertEquals(2, $result['skipped']);
        $this->assertEquals(20, BankTransaction::count());
    }

    public function test_transaction_history_credit_row(): void
    {
        $this->service->importFromCsv($this->historyFixture());

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2346767219')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::TYPE_CREDIT, $transaction->type);
        $this->assertEquals(2464.00, (float) $transaction->amount);
        $this->assertEquals('AUD', $transaction->currency);
        $this->assertEquals('PEOPLEBANK AUSTR', $transaction->payer_name);
        $this->assertEquals('500151142', $transaction->reference);
        $this->assertEquals('2026-09-02', $transaction->transaction_date->format('Y-m-d'));
        $this->assertEquals(BankTransaction::STATUS_PENDING, $transaction->status);
    }

    public function test_transaction_history_card_debit_row(): void
    {
        $this->service->importFromCsv($this->historyFixture());

        $transaction = BankTransaction::where('source_id', 'CARD_TRANSACTION-4269206592')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::TYPE_DEBIT, $transaction->type);
        $this->assertEquals(-14.98, (float) $transaction->amount);
        $this->assertEquals('AUD', $transaction->currency);
        $this->assertEquals('RSEA Safety', $transaction->payee_name);
        $this->assertEquals('RSEA Safety', $transaction->merchant_name);
        $this->assertNull($transaction->payer_name);
        $this->assertEquals('2026-09-01', $transaction->transaction_date->format('Y-m-d'));
    }

    public function test_transaction_history_multi_currency_card_spend_uses_source_amount(): void
    {
        $this->service->importFromCsv($this->historyFixture());

        // 213.95 AUD bought 151.20 USD — the AUD account movement is 213.95
        $transaction = BankTransaction::where('source_id', 'CARD_TRANSACTION-4181578920')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(-213.95, (float) $transaction->amount);
        $this->assertEquals('AUD', $transaction->currency);
        $this->assertEquals('Stripe-Z.ai', $transaction->payee_name);
    }

    public function test_transaction_history_refunded_rows_are_skipped(): void
    {
        $this->service->importFromCsv($this->historyFixture());

        $this->assertNull(BankTransaction::where('source_id', 'CARD_TRANSACTION-4181578199')->first());
        $this->assertNull(BankTransaction::where('source_id', 'CARD_TRANSACTION-4181578116')->first());
    }

    public function test_transaction_history_notprovided_reference_is_cleared(): void
    {
        $this->service->importFromCsv($this->historyFixture());

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2298482360')->first();
        $this->assertNotNull($transaction);
        $this->assertNull($transaction->reference);
    }

    public function test_transaction_history_reimport_skips_duplicates(): void
    {
        $this->service->importFromCsv($this->historyFixture());
        $result = $this->service->importFromCsv($this->historyFixture());

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(22, $result['skipped']);
        $this->assertEquals(20, BankTransaction::count());
    }

    // ============================================================
    // Upload through the reconciliation screen
    // ============================================================

    public function test_upload_imports_transaction_history_via_http(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reconciliation.process-import'), [
            'wise_csv' => UploadedFile::fake()->createWithContent(
                'transaction-history.csv',
                file_get_contents($this->historyFixture())
            ),
        ]);

        $response->assertRedirect(route('reconciliation.index'));
        $response->assertSessionHas('success');
        $this->assertEquals(20, BankTransaction::count());
        $this->assertNotNull(BankTransaction::where('source_id', 'TRANSFER-2346767219')->first());
    }

    public function test_upload_rejects_unrecognised_format(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reconciliation.process-import'), [
            'wise_csv' => UploadedFile::fake()->createWithContent('export.csv', "a,b\n1,2\n"),
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals(0, BankTransaction::count());
    }

    public function test_upload_requires_a_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reconciliation.process-import'), [])
            ->assertSessionHasErrors('wise_csv');
    }

    public function test_reconciliation_index_lists_pending_transactions(): void
    {
        $user = User::factory()->create();
        $this->service->importFromCsv($this->historyFixture());

        $response = $this->actingAs($user)->get(route('reconciliation.index'));

        $response->assertOk()
            ->assertSee('Money in from PEOPLEBANK AUSTR')
            ->assertSee('Pending transactions');
    }

    public function test_ignore_endpoint_marks_transaction_ignored(): void
    {
        $user = User::factory()->create();
        $transaction = BankTransaction::factory()->pending()->create();

        $this->actingAs($user)
            ->post(route('reconciliation.ignore', $transaction))
            ->assertSessionHas('success');

        $this->assertEquals(BankTransaction::STATUS_IGNORED, $transaction->fresh()->status);
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
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

        $this->service->importFromCsv($csvPath);

        $transactions = BankTransaction::all();
        foreach ($transactions as $transaction) {
            $this->assertNotNull($transaction->transaction_date);
        }
    }

    public function test_all_transactions_have_status(): void
    {
        $csvPath = __DIR__.'/../statement_test_AUD_2026-07-01_2026-08-06.csv';

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

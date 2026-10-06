<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Reconciliation\Models\BankStatement;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Services\StatementImportService;
use Tests\TestCase;

/**
 * Multi-format statement import: the same Wise account period as a CSV
 * export, an MT940 statement and a camt.053 XML report (the three
 * fixtures share every TRANSFER id). What is under test: detection,
 * per-format field mapping, and the cross-format dedupe the shared
 * (source, source_id) identity buys.
 */
class StatementImportFormatsTest extends TestCase
{
    use RefreshDatabase;

    private StatementImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StatementImportService::class);
    }

    private function fixture(string $extension): string
    {
        return __DIR__."/../statement_sample_AUD_2026-10-01_2026-10-05.{$extension}";
    }

    // ============================================================
    // MT940
    // ============================================================

    public function test_imports_mt940_statement(): void
    {
        $result = $this->service->import($this->fixture('mt940'));

        $this->assertEquals(5, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('MT940', $result['format']);
        $this->assertEquals(5, BankTransaction::count());
    }

    public function test_mt940_credit_row(): void
    {
        $this->service->import($this->fixture('mt940'));

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2210887642')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::SOURCE_WISE, $transaction->source);
        $this->assertEquals(BankTransaction::TYPE_CREDIT, $transaction->type);
        $this->assertEquals(41250.75, (float) $transaction->amount);
        $this->assertEquals('AUD', $transaction->currency);
        $this->assertEquals('2026-10-05', $transaction->transaction_date->format('Y-m-d'));
        $this->assertEquals(BankTransaction::STATUS_PENDING, $transaction->status);
    }

    public function test_mt940_debit_rows_are_negative(): void
    {
        $this->service->import($this->fixture('mt940'));

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2210063120')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::TYPE_DEBIT, $transaction->type);
        $this->assertEquals(-2894.10, (float) $transaction->amount);
        $this->assertEquals('2026-10-02', $transaction->transaction_date->format('Y-m-d'));
    }

    public function test_mt940_reimport_skips_duplicates(): void
    {
        $this->service->import($this->fixture('mt940'));
        $result = $this->service->import($this->fixture('mt940'));

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(5, $result['skipped']);
        $this->assertEquals(5, BankTransaction::count());
    }

    public function test_mt940_entry_without_reference_gets_deterministic_id(): void
    {
        // A minimal hand-built statement in the same shape as Wise's,
        // without the TRANSFER continuation lines.
        $content = "{1:F01TRWIGB2LAXXX0000000000}{2:I940TESTN}{4:\n"
            .":20:TEST/26/1\n:25:204351993\n:60F:C261001AUD1000,00\n"
            .":61:261005C10,50FTRFNONREF\n"
            .":62F:C261005AUD1010,50\n-}\n";
        $temp = tempnam(sys_get_temp_dir(), 'mt940_').'.txt';
        file_put_contents($temp, $content);

        try {
            $first = $this->service->import($temp);
            $second = $this->service->import($temp);

            $this->assertEquals(1, $first['imported']);
            $this->assertEquals(0, $second['imported']);
            $this->assertEquals(1, $second['skipped']);
        } finally {
            unlink($temp);
        }
    }

    public function test_mt940_entry_date_and_funds_code_are_accepted(): void
    {
        // Spec-legal :61: shape other banks emit — an interbank entry
        // date (MMDD) after the value date and a funds-code letter
        // after the debit/credit mark. The value date stays the
        // booking date; neither extra segment may break the parse.
        $content = "{1:F01TESTBYYAXXX0000000000}{2:I940TESTN}{4:\n"
            .":20:TEST/26/2\n:25:100200300\n:60F:C261001AUD1000,00\n"
            .":61:2610041005CS10,50NMSCNONREF\nTRANSFER-2210887642\n"
            .":62F:C261005AUD1010,50\n-}\n";
        $temp = tempnam(sys_get_temp_dir(), 'mt940_').'.txt';
        file_put_contents($temp, $content);

        try {
            $result = $this->service->import($temp);

            $this->assertEquals(1, $result['imported']);
            $transaction = BankTransaction::where('source_id', 'TRANSFER-2210887642')->first();
            $this->assertNotNull($transaction);
            $this->assertEquals(BankTransaction::TYPE_CREDIT, $transaction->type);
            $this->assertEquals(10.50, (float) $transaction->amount);
            $this->assertEquals('2026-10-04', $transaction->transaction_date->format('Y-m-d'));
        } finally {
            unlink($temp);
        }
    }

    public function test_mt940_non_transfer_continuation_is_not_an_identity(): void
    {
        // Only TRANSFER-<digits> is a source id; any other token after
        // the :61: line degrades to description text and the entry
        // falls to the deterministic fallback id, so a token two
        // statements share can never wrongly dedupe.
        $content = "{1:F01TESTBYYAXXX0000000000}{2:I940TESTN}{4:\n"
            .":20:TEST/26/3\n:25:100200300\n:60F:C261001AUD1000,00\n"
            .":61:261005C10,50FTRFNONREF\nNONREF\n"
            .":62F:C261005AUD1010,50\n-}\n";
        $temp = tempnam(sys_get_temp_dir(), 'mt940_').'.txt';
        file_put_contents($temp, $content);

        try {
            $result = $this->service->import($temp);

            $this->assertEquals(1, $result['imported']);
            $transaction = BankTransaction::first();
            $this->assertEquals('TEST/26/3-1', $transaction->source_id);
            $this->assertEquals('NONREF', $transaction->description);
        } finally {
            unlink($temp);
        }
    }

    // ============================================================
    // camt.053 XML
    // ============================================================

    public function test_imports_camt053_report(): void
    {
        $result = $this->service->import($this->fixture('xml'));

        $this->assertEquals(5, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('CAMT.053', $result['format']);
        $this->assertEquals(5, BankTransaction::count());
    }

    public function test_camt053_credit_row(): void
    {
        $this->service->import($this->fixture('xml'));

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2210887642')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::SOURCE_WISE, $transaction->source);
        $this->assertEquals(BankTransaction::TYPE_CREDIT, $transaction->type);
        $this->assertEquals(41250.75, (float) $transaction->amount);
        $this->assertEquals('AUD', $transaction->currency);
        $this->assertEquals('Final milestone', $transaction->reference);
        $this->assertEquals('Received money from Northwind Consulting with reference Final milestone', $transaction->description);
        // The credit entry carries no RltdPties — the payer is mined
        // from the entry information prose.
        $this->assertEquals('Northwind Consulting', $transaction->payer_name);
        $this->assertNull($transaction->payee_name);
        $this->assertEquals('2026-10-05', $transaction->transaction_date->format('Y-m-d'));
    }

    public function test_camt053_debit_row_maps_counterparty(): void
    {
        $this->service->import($this->fixture('xml'));

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2210063120')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::TYPE_DEBIT, $transaction->type);
        $this->assertEquals(-2894.10, (float) $transaction->amount);
        $this->assertEquals('BAS Q1', $transaction->reference);
        $this->assertEquals('AUSTRALIAN TAXATION OFFICE', $transaction->payee_name);
        $this->assertEquals('AUSTRALIAN TAXATION OFFICE', $transaction->merchant_name);
        $this->assertNull($transaction->payer_name);
        $this->assertEquals('2026-10-02', $transaction->transaction_date->format('Y-m-d'));
    }

    public function test_camt053_entry_without_ntry_ref_has_null_reference(): void
    {
        $this->service->import($this->fixture('xml'));

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2210035119')->first();
        $this->assertNotNull($transaction);
        $this->assertNull($transaction->reference);
        $this->assertEquals('SAMPLE SUPERANNUATION FUND', $transaction->payee_name);
        $this->assertEquals(-3120.45, (float) $transaction->amount);
    }

    public function test_camt053_reimport_skips_duplicates(): void
    {
        $this->service->import($this->fixture('xml'));
        $result = $this->service->import($this->fixture('xml'));

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(5, $result['skipped']);
        $this->assertEquals(5, BankTransaction::count());
    }

    // ============================================================
    // The Wise CSV layouts keep flowing through the same door
    // ============================================================

    public function test_imports_wise_statement_csv(): void
    {
        $result = $this->service->import($this->fixture('csv'));

        $this->assertEquals(5, $result['imported']);
        $this->assertEquals('CSV', $result['format']);

        $transaction = BankTransaction::where('source_id', 'TRANSFER-2210887642')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(BankTransaction::TYPE_CREDIT, $transaction->type);
        $this->assertEquals(41250.75, (float) $transaction->amount);
        $this->assertEquals('Northwind Consulting', $transaction->payer_name);
    }

    public function test_same_period_in_all_three_formats_imports_once(): void
    {
        $first = $this->service->import($this->fixture('csv'));
        $second = $this->service->import($this->fixture('mt940'));
        $third = $this->service->import($this->fixture('xml'));

        $this->assertEquals(5, $first['imported']);
        $this->assertEquals('CSV', $first['format']);
        $this->assertEquals(0, $second['imported']);
        $this->assertEquals(5, $second['skipped']);
        $this->assertEquals(0, $third['imported']);
        $this->assertEquals(5, $third['skipped']);
        $this->assertEquals(5, BankTransaction::count());
    }

    // ============================================================
    // Statement balances — the cash-check anchor
    // ============================================================

    public function test_mt940_import_stores_the_statement_balances(): void
    {
        $this->service->import($this->fixture('mt940'));

        $statement = BankStatement::first();
        $this->assertNotNull($statement);
        $this->assertEquals(BankTransaction::SOURCE_WISE, $statement->source);
        $this->assertEquals('MT940', $statement->format);
        $this->assertEquals('700254819/26/1', $statement->statement_id);
        $this->assertEquals('700254819', $statement->external_account);
        $this->assertEquals('AUD', $statement->currency);
        $this->assertEquals(45000.00, (float) $statement->opening_balance);
        $this->assertEquals('2026-09-30', $statement->opening_date->format('Y-m-d'));
        $this->assertEquals(60203.80, (float) $statement->closing_balance);
        $this->assertEquals('2026-10-05', $statement->closing_date->format('Y-m-d'));
    }

    public function test_camt053_import_stores_the_statement_balances(): void
    {
        $this->service->import($this->fixture('xml'));

        $statement = BankStatement::first();
        $this->assertNotNull($statement);
        $this->assertEquals('CAMT.053', $statement->format);
        $this->assertEquals('SAMPLE-700254819-20261005', $statement->statement_id);
        $this->assertEquals('700254819', $statement->external_account);
        $this->assertEquals(45000.00, (float) $statement->opening_balance);
        // camt.053 dates the balances at the period's date-time bounds
        // (01-10 opening, 06-10 00:00 closing — end of 05-10).
        $this->assertEquals('2026-10-01', $statement->opening_date->format('Y-m-d'));
        $this->assertEquals(60203.80, (float) $statement->closing_balance);
        $this->assertEquals('2026-10-06', $statement->closing_date->format('Y-m-d'));
    }

    public function test_reimporting_a_statement_does_not_duplicate_the_anchor(): void
    {
        $this->service->import($this->fixture('mt940'));
        $this->service->import($this->fixture('mt940'));

        $this->assertEquals(1, BankStatement::count());
    }

    public function test_the_same_period_in_another_format_adds_its_own_anchor(): void
    {
        $this->service->import($this->fixture('mt940'));
        $this->service->import($this->fixture('xml'));

        // Two statement ids, same balances — the anchor pick between
        // them is by closing date and lands on either harmlessly.
        $this->assertEquals(2, BankStatement::count());
        $this->assertEquals(1, BankStatement::distinct('closing_balance')->count('closing_balance'));
    }

    public function test_csv_imports_store_no_statement_balances(): void
    {
        $this->service->import($this->fixture('csv'));

        $this->assertEquals(0, BankStatement::count());
    }

    // ============================================================
    // Detection edge cases
    // ============================================================

    public function test_missing_file_returns_error(): void
    {
        $result = $this->service->import('/nonexistent/path.mt940');

        $this->assertEquals(['error' => StatementImportService::ERR_UNREADABLE], $result);
    }

    public function test_unrecognised_content_returns_error(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'stmt_').'.txt';
        file_put_contents($temp, "foo,bar\n1,2\n");

        try {
            // Not XML, no MT940 field, not a known CSV layout.
            $result = $this->service->import($temp);

            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('Unrecognised statement format', $result['error']);
        } finally {
            unlink($temp);
        }
    }

    public function test_non_camt_xml_returns_error(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'stmt_').'.xml';
        file_put_contents($temp, '<?xml version="1.0"?><foo><bar>1</bar></foo>');

        try {
            $result = $this->service->import($temp);

            $this->assertEquals(['error' => StatementImportService::ERR_UNRECOGNISED], $result);
        } finally {
            unlink($temp);
        }
    }

    // ============================================================
    // Upload through the reconciliation screen
    // ============================================================

    public function test_upload_imports_mt940_via_http(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reconciliation.process-import'), [
            'statement' => UploadedFile::fake()->createWithContent(
                'statement.mt940',
                file_get_contents($this->fixture('mt940')),
            ),
        ]);

        $response->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');
        $this->assertEquals(5, BankTransaction::count());
        $this->assertNotNull(BankTransaction::where('source_id', 'TRANSFER-2210887642')->first());
    }

    public function test_upload_imports_camt053_via_http(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reconciliation.process-import'), [
            'statement' => UploadedFile::fake()->createWithContent(
                'statement.xml',
                file_get_contents($this->fixture('xml')),
            ),
        ]);

        $response->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');
        $this->assertEquals(5, BankTransaction::count());
    }

    public function test_upload_rejects_disallowed_extension(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reconciliation.process-import'), [
            'statement' => UploadedFile::fake()->createWithContent('statement.json', '{"a":1}'),
        ])->assertSessionHasErrors('statement');

        $this->assertEquals(0, BankTransaction::count());
    }

    public function test_upload_requires_a_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reconciliation.process-import'), [])
            ->assertSessionHasErrors('statement');
    }

    public function test_upload_error_is_translated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reconciliation.process-import'), [
            'statement' => UploadedFile::fake()->createWithContent('statement.xml', '<?xml version="1.0"?><foo/>'),
        ]);

        $response->assertSessionHas('error', __('reconciliation.import.error_unrecognised'));
    }
}

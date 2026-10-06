<?php

namespace Modules\Reconciliation\Services;

use Illuminate\Support\Collection;
use Modules\Reconciliation\Models\BankStatement;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Services\StatementParsers\Camt053StatementParser;
use Modules\Reconciliation\Services\StatementParsers\Mt940StatementParser;

/**
 * The bank-statement import front door: detects which of the supported
 * statement formats a file is and hands it to the right parser —
 * Wise CSV layouts (via ReconciliationService, unchanged), MT940, or
 * camt.053 XML. Parsed rows persist through one shared path with the
 * CSV feed's invariants: source stays "wise" (every supported format is
 * a Wise export of the same movements, and the TRANSFER-… id names
 * them, so importing the same period in a second format skips as
 * duplicates instead of duplicating), debits store negative, and rows
 * without a usable date or with a zero amount are skipped as noise.
 *
 * Statements that carry balances (MT940, camt.053) also store their
 * header — opening/closing balance and dates — keyed on the
 * statement's own id, so re-importing refreshes the anchor instead of
 * duplicating it. The Wise CSV layouts carry no balances and store
 * nothing: the cash check's actual side stays the running sum until a
 * balanced format lands.
 */
class StatementImportService
{
    public const ERR_UNREADABLE = 'unreadable';

    public const ERR_UNRECOGNISED = 'unrecognised';

    public const FORMAT_CSV = 'CSV';

    public const FORMAT_MT940 = 'MT940';

    public const FORMAT_CAMT = 'CAMT.053';

    public function __construct(
        private ReconciliationService $reconciliation,
        private Mt940StatementParser $mt940,
        private Camt053StatementParser $camt053,
    ) {}

    /**
     * Import a statement file of any supported format, auto-detected
     * from its content: leading XML means camt.053, a :61: field means
     * MT940, anything else is tried as a Wise CSV layout.
     *
     * @return array{imported: int, skipped: int, errors: string[], format: string}|array{error: string}
     */
    public function import(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return ['error' => self::ERR_UNREADABLE];
        }

        $content = (string) file_get_contents($filePath);
        if ($content === '') {
            return ['error' => self::ERR_UNREADABLE];
        }

        if (str_starts_with(ltrim($content), '<')) {
            $parsed = $this->camt053->parse($content);
            if ($parsed === null) {
                return ['error' => self::ERR_UNRECOGNISED];
            }

            return $this->importRows($parsed['rows'], self::FORMAT_CAMT, $parsed['statement']);
        }

        if (str_contains($content, ':61:')) {
            $parsed = $this->mt940->parse($content);

            return $this->importRows($parsed['rows'], self::FORMAT_MT940, $parsed['statement']);
        }

        $result = $this->reconciliation->importFromCsv($filePath);
        if (isset($result['error'])) {
            return $result;
        }

        // The CSV path's message text is its own (tests assert it);
        // only the format tag is added here.
        return $result + ['format' => self::FORMAT_CSV];
    }

    /**
     * Persist parsed statement rows: skip ones already imported (same
     * source + source id), ones without a date (the column is
     * not-null — an undated row cannot be stored), and zero amounts
     * (not movements, the CSV feed's rule too).
     *
     * @param  Collection<int, array>|null  $rows
     * @param  array|null  $statement  the parsed statement header, stored when it carries balances
     * @return array{imported: int, skipped: int, errors: string[], format: string}|array{error: string}
     */
    private function importRows($rows, string $format, ?array $statement = null): array
    {
        if ($rows === null) {
            return ['error' => self::ERR_UNRECOGNISED];
        }

        $this->storeStatement($statement, $format);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $sourceId = (string) ($row['source_id'] ?? '');

            if ($row['transaction_date'] === null) {
                $skipped++;
                $errors[] = ($sourceId ?: 'entry').': skipped (no usable date)';

                continue;
            }

            if (abs((float) $row['amount']) < 0.005) {
                $skipped++;
                $errors[] = ($sourceId ?: 'entry').': skipped (zero amount)';

                continue;
            }

            $existing = BankTransaction::query()
                ->where('source', BankTransaction::SOURCE_WISE)
                ->where('source_id', $sourceId)
                ->first();
            if ($existing !== null) {
                $skipped++;

                continue;
            }

            BankTransaction::create([
                'source' => BankTransaction::SOURCE_WISE,
                'source_id' => $sourceId,
                'reference' => $row['reference'],
                'description' => $row['description'],
                'amount' => $row['amount'],
                'currency' => $row['currency'] ?? 'AUD',
                'type' => $row['type'],
                'transaction_date' => $row['transaction_date'],
                'created_at_source' => $row['created_at_source'] ?? null,
                'merchant_name' => $row['merchant_name'] ?? null,
                'payer_name' => $row['payer_name'] ?? null,
                'payee_name' => $row['payee_name'] ?? null,
                'status' => BankTransaction::STATUS_PENDING,
            ]);
            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'format' => $format,
        ];
    }

    /**
     * Store a parsed statement header as the cash-check anchor. Headers
     * without an id or without any balance carry nothing to anchor and
     * are dropped; keyed on (source, statement id) so re-importing
     * refreshes the balances instead of duplicating the row.
     */
    private function storeStatement(?array $statement, string $format): void
    {
        if ($statement === null || ($statement['statement_id'] ?? null) === null) {
            return;
        }

        if ($statement['opening_balance'] === null && $statement['closing_balance'] === null) {
            return;
        }

        BankStatement::updateOrCreate(
            [
                'source' => BankTransaction::SOURCE_WISE,
                'statement_id' => $statement['statement_id'],
            ],
            [
                'format' => $format,
                'external_account' => $statement['external_account'] ?? null,
                'currency' => $statement['currency'] ?? null,
                'opening_date' => $statement['opening_date'] ?? null,
                'closing_date' => $statement['closing_date'] ?? null,
                'opening_balance' => $statement['opening_balance'] ?? null,
                'closing_balance' => $statement['closing_balance'] ?? null,
            ],
        );
    }
}

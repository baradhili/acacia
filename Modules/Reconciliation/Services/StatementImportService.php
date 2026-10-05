<?php

namespace Modules\Reconciliation\Services;

use Illuminate\Support\Collection;
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
            return $this->importRows($this->camt053->parse($content), self::FORMAT_CAMT);
        }

        if (str_contains($content, ':61:')) {
            return $this->importRows($this->mt940->parse($content), self::FORMAT_MT940);
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
     * @return array{imported: int, skipped: int, errors: string[], format: string}|array{error: string}
     */
    private function importRows($rows, string $format): array
    {
        if ($rows === null) {
            return ['error' => self::ERR_UNRECOGNISED];
        }

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
}

<?php

namespace Modules\Reconciliation\Services\StatementParsers;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Parser for the MT940 (SWIFT FIN 940) customer statement, written for
 * the flavour Wise emits (:61: entries whose movement reference — the
 * TRANSFER-… id — rides on the line after the field, no :86:
 * information block, balances in :60F:/:62F:). Wise's MT940, CAMT.053
 * and CSV downloads describe the same movements, so the TRANSFER id is
 * kept as the source id: importing the same period in two formats
 * dedupes instead of duplicating.
 *
 * The parser is deliberately shallow: value date, debit/credit mark,
 * amount and reference are read; the spec-legal optional entry date
 * and funds code are accepted but ignored, as are bank transaction
 * codes and interbank details. The statement header (:20:/:25:) and
 * the opening/closing balance fields (:60F:/:62F:) are captured for
 * the balance store that anchors the cash check — without them the
 * actual side is only the running sum of imported lines. Everything
 * the parser cannot attribute stays out of the row rather than
 * guessed.
 */
class Mt940StatementParser
{
    /**
     * Parse an MT940 payload into normalised statement rows plus the
     * statement header with its balances.
     *
     * @return array{
     *     statement: array{
     *         statement_id: ?string, external_account: ?string, currency: ?string,
     *         opening_date: ?Carbon, opening_balance: ?float,
     *         closing_date: ?Carbon, closing_balance: ?float,
     *     },
     *     rows: Collection<int, array{
     *         source_id: ?string, reference: ?string, description: ?string,
     *         amount: float, currency: string, type: string,
     *         transaction_date: ?Carbon, created_at_source: ?Carbon,
     *         merchant_name: ?string, payer_name: ?string, payee_name: ?string,
     *     }>,
     * }
     */
    public function parse(string $content): array
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', $content))
            ->map(fn ($line) => rtrim((string) $line))
            ->filter(fn ($line) => $line !== '');

        // Wise states the account currency once, on the opening
        // balance field (:60F:/:60M:) — every entry inherits it.
        $currency = null;
        $opening = null;
        $closing = null;
        $statementId = null;
        $externalAccount = null;

        $lines->each(function ($line) use (&$currency, &$opening, &$closing, &$statementId, &$externalAccount) {
            if (preg_match('/^:20:(.*)$/', $line, $m)) {
                $statementId = trim($m[1]) ?: null;
            } elseif (preg_match('/^:25:([0-9A-Z]+)$/', $line, $m)) {
                $externalAccount = $m[1];
            } elseif (preg_match('/^:60[FM]:(C|D)(\d{6})([A-Z]{3})([0-9]+(?:[.,][0-9]+)?)$/', $line, $m)) {
                $currency = $m[3];
                $opening = [
                    'date' => $this->balanceDate($m[2]),
                    // C = in credit (the bank owes the balance), D = overdrawn.
                    'balance' => ($m[1] === 'C' ? 1 : -1) * (float) str_replace(',', '.', $m[4]),
                ];
            } elseif (preg_match('/^:62[FM]:(C|D)(\d{6})([A-Z]{3})([0-9]+(?:[.,][0-9]+)?)$/', $line, $m)) {
                $currency = $currency ?? $m[3];
                $closing = [
                    'date' => $this->balanceDate($m[2]),
                    'balance' => ($m[1] === 'C' ? 1 : -1) * (float) str_replace(',', '.', $m[4]),
                ];
            }
        });

        $currency ??= 'AUD';

        $rows = collect();
        $current = null;
        $description = [];
        $inDescription = false;

        $flush = function () use (&$current, &$description, &$inDescription, $currency, $rows): void {
            if ($current === null) {
                return;
            }

            $text = trim(implode("\n", $description));
            $rows->push([
                'source_id' => $current['source_id'],
                // Wise's MT940 carries no payment reference — the
                // continuation line's TRANSFER id is the identity, not
                // the counterparty's reference text.
                'reference' => null,
                'description' => $text !== '' ? $text : $current['continuation'],
                'amount' => $current['amount'],
                'currency' => $currency,
                'type' => $current['amount'] >= 0 ? 'CREDIT' : 'DEBIT',
                'transaction_date' => $current['date'],
                'created_at_source' => null,
                'merchant_name' => null,
                'payer_name' => null,
                'payee_name' => null,
            ]);
            $description = [];
            $inDescription = false;
        };

        foreach ($lines as $line) {
            if (preg_match('/^:61:(\d{6})(?:\d{4})?(C|D|RC|RD)[A-Z]?([0-9]+(?:[.,][0-9]+)?)(.*)$/', $line, $m)) {
                $flush();

                // A two-digit year with a pivot at 1970: statements in
                // living memory are all 20xx, a 69 would be the last
                // century's paper era. The optional entry date (the
                // interbank MMDD after the value date) and funds-code
                // letter are spec-legal but carry nothing this feed
                // uses — the value date stays the booking date.
                $year = (int) substr($m[1], 0, 2);
                $current = [
                    'date' => Carbon::createFromDate($year < 70 ? 2000 + $year : 1900 + $year, (int) substr($m[1], 2, 2), (int) substr($m[1], 4, 2))->startOfDay(),
                    // RC/RD are reversals: a reversed credit leaves the
                    // account, a reversed debit returns to it.
                    'amount' => in_array($m[2], ['C', 'RD'], true) ? (float) str_replace(',', '.', $m[3]) : -(float) str_replace(',', '.', $m[3]),
                    'source_id' => null,
                    'continuation' => null,
                ];

                continue;
            }

            if ($current !== null && str_starts_with($line, ':86:')) {
                $description[] = trim(substr($line, 4));
                $inDescription = true;

                continue;
            }

            // Outside any field header and the block trailer, a plain
            // line after a :61: is Wise's movement-reference
            // continuation. Only the TRANSFER-<digits> shape is an
            // identity — anything else (other banks' free tokens,
            // NONREF) degrades to description text and the entry falls
            // to the deterministic fallback id, because an arbitrary
            // token shared by two statements would wrongly dedupe.
            // Lines following an :86: block stay description text.
            if ($current !== null && ! str_starts_with($line, ':') && ! str_starts_with($line, '-') && ! str_starts_with($line, '{')) {
                $text = trim($line);
                if ($inDescription) {
                    $description[] = $text;
                } elseif ($current['source_id'] === null && preg_match('/^TRANSFER-\d+$/', $text)) {
                    $current['source_id'] = $text;
                } elseif ($current['continuation'] === null) {
                    $current['continuation'] = $text;
                }
            }
        }
        $flush();

        // Entries without an extracted reference still need a
        // deterministic id, so re-importing the same file skips them
        // rather than duplicating them.
        $rows = $rows->values()->map(function ($row, $index) use ($statementId) {
            $row['source_id'] ??= ($statementId ?? 'MT940').'-'.($index + 1);

            return $row;
        });

        return [
            'statement' => [
                'statement_id' => $statementId,
                'external_account' => $externalAccount,
                'currency' => $currency,
                'opening_date' => $opening['date'] ?? null,
                'opening_balance' => $opening['balance'] ?? null,
                'closing_date' => $closing['date'] ?? null,
                'closing_balance' => $closing['balance'] ?? null,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * A balance field's YYMMDD date, on the same century pivot the
     * :61: entries use.
     */
    protected function balanceDate(string $yymmdd): Carbon
    {
        $year = (int) substr($yymmdd, 0, 2);

        return Carbon::createFromDate($year < 70 ? 2000 + $year : 1900 + $year, (int) substr($yymmdd, 2, 2), (int) substr($yymmdd, 4, 2))->startOfDay();
    }
}

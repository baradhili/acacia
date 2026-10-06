<?php

namespace Modules\Reconciliation\Services\StatementParsers;

use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;

/**
 * Parser for ISO 20022 camt.053 bank-to-customer statement XML, written
 * for the flavour Wise emits (one BkToCstmrStmt, booked Ntry rows whose
 * BkTxCd/Prtry/Cd carries the TRANSFER-… id, the counterparty in
 * NtryDtls/TxDtls/RltdPties). Wise's CAMT, MT940 and CSV downloads
 * describe the same movements, so the TRANSFER id is kept as the source
 * id: importing the same period in two formats dedupes instead of
 * duplicating.
 *
 * XPath matches on local names, not the namespace — camt.053 revisions
 * (…001.02 through …001.10 and beyond) differ only in their namespace
 * URN, and the element walk this parser needs has been stable across
 * them. Only booked entries (Sts BOOK) import: pending entries can still
 * change, and the feed is not a live balance.
 */
class Camt053StatementParser
{
    /**
     * Parse a camt.053 payload into normalised statement rows plus the
     * statement header with its OPBD/CLBD balances (captured for the
     * balance store that anchors the cash check).
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
     * }|null null when the XML is not a camt.053 statement
     */
    public function parse(string $content): ?array
    {
        $document = new DOMDocument;
        // Uploaded XML is untrusted: no network entity resolution, and
        // a doctype (the XXE vector) refuses the file outright.
        if (! @$document->loadXML($content, LIBXML_NONET) || $document->doctype !== null) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $statements = $xpath->query("//*[local-name()='BkToCstmrStmt']");
        if ($statements === false || $statements->length === 0) {
            return null;
        }

        $entries = $xpath->query("//*[local-name()='BkToCstmrStmt']/*[local-name()='Stmt']/*[local-name()='Ntry']");
        if ($entries === false) {
            return null;
        }

        $header = [
            'statement_id' => null,
            'external_account' => null,
            'currency' => null,
            'opening_date' => null,
            'opening_balance' => null,
            'closing_date' => null,
            'closing_balance' => null,
        ];

        // The header anchors the cash check, so it may only be built
        // from a file that describes exactly one statement: a
        // multi-Stmt message (several accounts or periods) would mix
        // ids and balances from different statements into one anchor.
        // Its entries still import as rows — only the anchor declines.
        $stmtBlocks = $xpath->query("//*[local-name()='BkToCstmrStmt']/*[local-name()='Stmt']");
        if ($stmtBlocks !== false && $stmtBlocks->length === 1 && $stmtBlocks->item(0) instanceof DOMElement) {
            $stmt = $stmtBlocks->item(0);

            $header['statement_id'] = $this->text($xpath, "./*[local-name()='Id']", $stmt);
            $header['external_account'] = $this->text($xpath, "./*[local-name()='Acct']/*[local-name()='Id']/*[local-name()='Othr']/*[local-name()='Id']", $stmt);

            // The OPBD/CLBD balances: CRDT is a balance held (positive),
            // DBIT an overdrawn one (negative).
            $balances = $xpath->query("./*[local-name()='Bal']", $stmt);
            if ($balances !== false) {
                foreach ($balances as $balance) {
                    /** @var DOMElement $balance */
                    $code = $this->text($xpath, "./*[local-name()='Tp']/*[local-name()='CdOrPrtry']/*[local-name()='Cd']", $balance);
                    if (! in_array($code, ['OPBD', 'CLBD'], true)) {
                        continue;
                    }

                    $amountNode = $xpath->query("./*[local-name()='Amt']", $balance)?->item(0);
                    if (! $amountNode instanceof DOMElement) {
                        continue;
                    }

                    $dateText = $this->text($xpath, "./*[local-name()='Dt']/*[local-name()='DtTm']", $balance)
                        ?? $this->text($xpath, "./*[local-name()='Dt']/*[local-name()='Dt']", $balance);
                    $signed = $this->text($xpath, "./*[local-name()='CdtDbtInd']", $balance) !== 'DBIT'
                        ? (float) $amountNode->textContent
                        : -(float) $amountNode->textContent;

                    if ($code === 'OPBD') {
                        $header['opening_date'] = $dateText !== null ? $this->balanceDate($dateText) : null;
                        $header['opening_balance'] = $signed;
                    } else {
                        $header['closing_date'] = $dateText !== null ? $this->balanceDate($dateText) : null;
                        $header['closing_balance'] = $signed;
                    }

                    $header['currency'] = $header['currency'] ?? ($amountNode->getAttribute('Ccy') ?: null);
                }
            }
        }

        $rows = collect();
        $sequence = 0;

        foreach ($entries as $entry) {
            /** @var DOMElement $entry */
            $status = $this->text($xpath, "./*[local-name()='Sts']/*[local-name()='Cd']", $entry);
            if ($status !== null && $status !== 'BOOK') {
                continue;
            }

            $amountNode = $xpath->query("./*[local-name()='Amt']", $entry)?->item(0);
            if (! $amountNode instanceof DOMElement) {
                continue;
            }

            $sequence++;
            $amount = (float) $amountNode->textContent;
            $isCredit = $this->text($xpath, "./*[local-name()='CdtDbtInd']", $entry) !== 'DBIT';

            // The fallback prefix is the entry's OWN Stmt Id — a
            // multi-statement message must not fold its entries into
            // one prefix, and entries of different statements in
            // different files must never collide on the same
            // deterministic id.
            $entryStatementId = $this->text($xpath, "ancestor::*[local-name()='Stmt'][1]/*[local-name()='Id']", $entry);

            $sourceId = $this->text($xpath, "./*[local-name()='BkTxCd']/*[local-name()='Prtry']/*[local-name()='Cd']", $entry)
                ?? $this->text($xpath, ".//*[local-name()='TxId']", $entry)
                ?? ($entryStatementId ?? 'CAMT053').'-'.$sequence;

            $description = $this->text($xpath, "./*[local-name()='AddtlNtryInf']", $entry);

            // The counterparty sits inside the transaction details:
            // the debtor pays a credit in, the creditor receives a
            // debit out. Credits without details (Wise's do) fall back
            // to mining AddtlNtryInf, the same prose every format
            // carries.
            $cdtr = $this->text($xpath, ".//*[local-name()='RltdPties']/*[local-name()='Cdtr']/*[local-name()='Pty']/*[local-name()='Nm']", $entry);
            $dbtr = $this->text($xpath, ".//*[local-name()='RltdPties']/*[local-name()='Dbtr']/*[local-name()='Pty']/*[local-name()='Nm']", $entry);

            [$payer, $payee] = $isCredit
                ? [$dbtr ?? $this->payerFromProse($description), null]
                : [null, $cdtr ?? $this->payeeFromProse($description)];

            $date = null;
            $dateText = $this->text($xpath, "./*[local-name()='BookgDt']/*[local-name()='DtTm']", $entry)
                ?? $this->text($xpath, "./*[local-name()='BookgDt']/*[local-name()='Dt']", $entry)
                ?? $this->text($xpath, "./*[local-name()='ValDt']/*[local-name()='DtTm']", $entry)
                ?? $this->text($xpath, "./*[local-name()='ValDt']/*[local-name()='Dt']", $entry);
            if ($dateText !== null) {
                $date = Carbon::parse($dateText);
            }

            $rows->push([
                'source_id' => $sourceId,
                'reference' => $this->text($xpath, "./*[local-name()='NtryRef']", $entry),
                'description' => $description,
                'amount' => $isCredit ? abs($amount) : -abs($amount),
                'currency' => $amountNode->getAttribute('Ccy') ?: 'AUD',
                'type' => $isCredit ? 'CREDIT' : 'DEBIT',
                'transaction_date' => $date,
                'created_at_source' => $date,
                'merchant_name' => $payer ?? $payee,
                'payer_name' => $payer,
                'payee_name' => $payee,
            ]);
        }

        return [
            'statement' => $header,
            'rows' => $rows->values(),
        ];
    }

    /**
     * First text content of an XPath query, scoped to a context element
     * when given; null when the query finds nothing or the text is
     * blank.
     */
    private function text(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return $text !== '' ? $text : null;
    }

    /**
     * A balance date, normalised to the inclusive date the balance
     * covers. Wise stamps its OPBD/CLBD DtTm at midnight on the day
     * AFTER the covered period (the exclusive bound — closing
     * "2026-10-06T00:00" is end of 05 Oct), so a midnight date-time
     * steps back one second to the day it actually closes; a bare
     * date or a date-time with a real time of day is already the
     * inclusive date and stands.
     */
    protected function balanceDate(string $raw): Carbon
    {
        $date = Carbon::parse($raw);

        if (str_contains($raw, 'T') && $date->format('H:i:s') === '00:00:00') {
            return $date->subSecond();
        }

        return $date;
    }

    /**
     * Wise's credit prose — "Received money from X with reference Y" —
     * is the only payer identification a detail-less entry has.
     */
    private function payerFromProse(?string $description): ?string
    {
        if ($description !== null && preg_match('/^Received money from (.+?)(?: with reference .+)?$/', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * The debit twin: "Sent money to X".
     */
    private function payeeFromProse(?string $description): ?string
    {
        if ($description !== null && preg_match('/^Sent money to (.+)$/', $description, $m)) {
            return $m[1];
        }

        return null;
    }
}

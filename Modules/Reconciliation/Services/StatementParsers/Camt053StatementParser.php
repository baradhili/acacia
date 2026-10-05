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
     * Parse a camt.053 payload into normalised statement rows.
     *
     * @return Collection<int, array{
     *     source_id: ?string, reference: ?string, description: ?string,
     *     amount: float, currency: string, type: string,
     *     transaction_date: ?Carbon, created_at_source: ?Carbon,
     *     merchant_name: ?string, payer_name: ?string, payee_name: ?string,
     * }>|null null when the XML is not a camt.053 statement
     */
    public function parse(string $content): ?Collection
    {
        $document = new DOMDocument;
        if (! @$document->loadXML($content)) {
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

        $statementId = $this->text($xpath, "//*[local-name()='Stmt']/*[local-name()='Id']") ?? 'CAMT053';

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

            $sourceId = $this->text($xpath, "./*[local-name()='BkTxCd']/*[local-name()='Prtry']/*[local-name()='Cd']", $entry)
                ?? $this->text($xpath, ".//*[local-name()='TxId']", $entry)
                ?? $statementId.'-'.$sequence;

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

        return $rows->values();
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

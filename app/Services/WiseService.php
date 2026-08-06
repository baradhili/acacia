<?php

namespace App\Services;

use App\Models\BankTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WiseService
{
    private string $apiUrl;
    private ?string $token;
    private ?string $profileId;

    public function __construct()
    {
        $this->apiUrl = config('services.wise.api_url', 'https://api.wise.com');
        $this->token = config('services.wise.token');
        $this->profileId = config('services.wise.profile_id');
    }

    /**
     * Fetch transactions from Wise API
     */
    public function fetchTransactions(Carbon $fromDate, Carbon $toDate): Collection
    {
        if (!$this->token || !$this->profileId) {
            Log::warning('Wise API not configured. Set WISE_TOKEN and WISE_PROFILE_ID in .env');
            return collect();
        }

        try {
            $response = Http::withToken($this->token)
                ->get("{$this->apiUrl}/v1/profiles/{$this->profileId}/transfers", [
                    'limit' => 1000,
                ]);

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::error('Wise API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Wise API exception', ['message' => $e->getMessage()]);
        }

        return collect();
    }

    /**
     * Parse Wise CSV and import transactions
     * 
     * Expected CSV columns (Wise export format):
     * TransferWise ID, Date, Date Time, Amount, Currency, Description, 
     * Payment Reference, Running Balance, ...
     */
    public function importFromCsv(string $filePath): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['error' => 'Cannot open file'];
        }

        // Skip header row
        $headers = fgetcsv($handle);
        $headers = array_map('trim', $headers);
        
        // Normalize header names to indices
        $columnMap = $this->mapCsvColumns($headers);

        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            try {
                $this->importWiseRowFromMapped($row, $columnMap);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row {$imported}: " . $e->getMessage();
                $skipped++;
            }
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Map CSV header names to column indices
     */
    private function mapCsvColumns(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim($header));
            $map[$normalized] = $index;
        }
        return $map;
    }

    /**
     * Import a single Wise row using column map
     */
    private function importWiseRowFromMapped(array $row, array $columnMap): BankTransaction
    {
        $getColumn = function(string $name) use ($row, $columnMap): ?string {
            $normalized = strtolower(trim($name));
            $index = $columnMap[$normalized] ?? null;
            if ($index === null || !isset($row[$index])) {
                return null;
            }
            return trim($row[$index]);
        };

        $sourceId = $getColumn('TransferWise ID') ?: $getColumn('TransferWise ID ');
        $date = $getColumn('Date');
        $amount = $getColumn('Amount');
        $currency = $getColumn('Currency') ?: 'AUD';
        $description = $getColumn('Description') ?: '';
        $reference = $getColumn('Payment Reference') ?: '';
        $type = strtoupper($getColumn('Transaction Type') ?: 'DEBIT');
        $merchant = $getColumn('Payer Name') ?: $getColumn('Payee Name');
        $payerName = $getColumn('Payer Name');
        $payeeName = $getColumn('Payee Name');

        // Skip empty or invalid rows
        if (empty($sourceId) && empty($date)) {
            throw new \InvalidArgumentException('Empty row');
        }

        // Parse amount - handle negative values and convert to positive for storage
        $amountValue = floatval(str_replace(',', '', $amount ?? '0'));
        
        // If amount is negative in CSV (debit), store as negative; if positive (credit), store as positive
        // The type column indicates direction
        if ($type === 'DEBIT' && $amountValue > 0) {
            $amountValue = -$amountValue; // Outgoing money
        }

        // Parse date (format: DD-MM-YYYY)
        $transactionDate = null;
        if ($date) {
            try {
                $transactionDate = Carbon::createFromFormat('d-m-Y', trim($date));
            } catch (\Exception $e) {
                try {
                    $transactionDate = Carbon::parse(trim($date));
                } catch (\Exception $e2) {
                    throw new \InvalidArgumentException("Invalid date format: {$date}");
                }
            }
        }

        // Build reference from description if not provided
        if (empty($reference) && !empty($description)) {
            // Extract reference from description like "Received money from X with reference Y"
            if (preg_match('/reference\s+(\S+)/i', $description, $matches)) {
                $reference = $matches[1];
            }
        }

        // Check if already exists
        $sourceIdValue = trim($sourceId ?? '');
        if (!empty($sourceIdValue)) {
            $existing = BankTransaction::where('source', BankTransaction::SOURCE_WISE)
                ->where('source_id', $sourceIdValue)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => !empty($sourceIdValue) ? $sourceIdValue : 'CSV-' . uniqid(),
            'reference' => $reference,
            'description' => $description,
            'amount' => $amountValue,
            'currency' => $currency,
            'type' => $type,
            'transaction_date' => $transactionDate,
            'created_at_source' => $transactionDate,
            'merchant_name' => $merchant,
            'payer_name' => $payerName,
            'payee_name' => $payeeName,
            'status' => BankTransaction::STATUS_PENDING,
        ]);
    }

    /**
     * Legacy import method for backward compatibility
     * @deprecated Use importFromCsv() instead
     */
    private function importWiseRow(array $row): BankTransaction
    {
        $sourceId = $row[0] ?? null;
        $date = $row[1] ?? null;
        $reference = $row[2] ?? '';
        $amount = abs(floatval($row[3] ?? 0));
        $currency = $row[4] ?? 'AUD';
        $type = strtoupper($row[5] ?? 'DEBIT');
        $merchant = $row[6] ?? null;

        // Check if already exists
        $existing = BankTransaction::where('source', BankTransaction::SOURCE_WISE)
            ->where('source_id', $sourceId)
            ->first();
        if ($existing) {
            return $existing;
        }

        return BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => $sourceId ?? 'CSV-' . uniqid(),
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'type' => $type,
            'transaction_date' => Carbon::parse($date),
            'created_at_source' => Carbon::parse($date),
            'merchant_name' => $merchant,
            'status' => BankTransaction::STATUS_PENDING,
        ]);
    }

    /**
     * Get unmatched transactions
     */
    public function getUnmatchedTransactions(): Collection
    {
        return BankTransaction::pending()
            ->orderBy('transaction_date', 'desc')
            ->get();
    }

    /**
     * Get reconciliation statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => BankTransaction::count(),
            'pending' => BankTransaction::pending()->count(),
            'matched' => BankTransaction::matched()->count(),
            'ignored' => BankTransaction::where('status', 'IGNORED')->count(),
        ];
    }
}

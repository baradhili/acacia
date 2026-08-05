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
    private string $token;
    private string $profileId;

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
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            try {
                // Wise CSV format: Date, Reference, Amount, Currency, Type, Merchant
                $this->importWiseRow($row);
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
     * Import a single Wise row
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

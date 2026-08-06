<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Carbon\Carbon;

class AccountStatementExport implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $account;
    protected $startDate;
    protected $endDate;
    protected $openingBalance;
    protected $closingBalance;
    protected $totalDebit;
    protected $totalCredit;
    protected $transactions;

    public function __construct(
        $account,
        $startDate,
        $endDate,
        $openingBalance,
        $closingBalance,
        $totalDebit,
        $totalCredit,
        $transactions
    ) {
        $this->account = $account;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->openingBalance = $openingBalance;
        $this->closingBalance = $closingBalance;
        $this->totalDebit = $totalDebit;
        $this->totalCredit = $totalCredit;
        $this->transactions = $transactions;
    }

    public function collection()
    {
        $data = collect();

        // Header info
        $data->push(['Account Statement']);
        $data->push(["{$this->account->code} - {$this->account->name}"]);
        $data->push(["Period: {$this->startDate->format('d/m/Y')} to {$this->endDate->format('d/m/Y')}"]);
        $data->push([]);

        // Summary
        $data->push(['Summary']);
        $data->push(['Opening Balance', number_format($this->openingBalance, 2)]);
        $data->push(['Total Debit', number_format($this->totalDebit, 2)]);
        $data->push(['Total Credit', number_format($this->totalCredit, 2)]);
        $data->push(['Closing Balance', number_format($this->closingBalance, 2)]);
        $data->push([]);

        // Headers
        $data->push(['Date', 'Reference', 'Description', 'Debit', 'Credit', 'Balance']);

        // Opening balance row
        $data->push([
            $this->startDate->format('d/m/Y'),
            '',
            'Opening Balance',
            '',
            '',
            number_format($this->openingBalance, 2),
        ]);

        // Transactions
        foreach ($this->transactions as $txn) {
            $data->push([
                $txn['date']->format('d/m/Y'),
                $txn['reference'] ?? '',
                $txn['narration'] ?? '',
                $txn['debit'] ? number_format($txn['debit'], 2) : '',
                $txn['credit'] ? number_format($txn['credit'], 2) : '',
                number_format($txn['balance'], 2),
            ]);
        }

        // Totals row
        $data->push([
            '',
            '',
            'TOTALS',
            number_format($this->totalDebit, 2),
            number_format($this->totalCredit, 2),
            number_format($this->closingBalance, 2),
        ]);

        return $data;
    }

    public function headings(): array
    {
        return [];
    }

    public function title(): string
    {
        return "{$this->account->code} - {$this->account->name}";
    }
}

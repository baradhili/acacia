<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class TaxSummaryExport implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $startDate;
    protected $endDate;
    protected $salesByTaxRate;
    protected $purchasesByTaxRate;
    protected $totalSalesTax;
    protected $totalPurchaseTax;
    protected $netTaxPayable;

    public function __construct(
        $startDate,
        $endDate,
        $salesByTaxRate,
        $purchasesByTaxRate,
        $totalSalesTax,
        $totalPurchaseTax,
        $netTaxPayable
    ) {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->salesByTaxRate = $salesByTaxRate;
        $this->purchasesByTaxRate = $purchasesByTaxRate;
        $this->totalSalesTax = $totalSalesTax;
        $this->totalPurchaseTax = $totalPurchaseTax;
        $this->netTaxPayable = $netTaxPayable;
    }

    public function collection()
    {
        $data = collect();

        // Header
        $data->push(['Tax Summary Report']);
        $data->push(["Period: {$this->startDate->format('d/m/Y')} to {$this->endDate->format('d/m/Y')}"]);
        $data->push([]);

        // Summary
        $data->push(['Summary']);
        $data->push(['Output Tax (GST Collected)', number_format($this->totalSalesTax, 2)]);
        $data->push(['Input Tax (GST Paid)', number_format($this->totalPurchaseTax, 2)]);
        $data->push(['Net Tax Payable', number_format($this->netTaxPayable, 2)]);
        $data->push([]);

        // Sales section
        $data->push(['Sales by Tax Rate (Output Tax)']);
        $data->push(['Tax Rate', 'Net Amount', 'Tax Amount']);

        foreach ($this->salesByTaxRate as $row) {
            $data->push([
                "{$row['tax_rate']}%",
                number_format($row['net_amount'], 2),
                number_format($row['tax_amount'], 2),
            ]);
        }

        $data->push(['Total', number_format($this->salesByTaxRate->sum('net_amount'), 2), number_format($this->totalSalesTax, 2)]);
        $data->push([]);

        // Purchases section
        $data->push(['Purchases by Tax Rate (Input Tax)']);
        $data->push(['Tax Rate', 'Net Amount', 'Tax Amount']);

        foreach ($this->purchasesByTaxRate as $row) {
            $data->push([
                "{$row['tax_rate']}%",
                number_format($row['net_amount'], 2),
                number_format($row['tax_amount'], 2),
            ]);
        }

        $data->push(['Total', number_format($this->purchasesByTaxRate->sum('net_amount'), 2), number_format($this->totalPurchaseTax, 2)]);

        return $data;
    }

    public function headings(): array
    {
        return [];
    }

    public function title(): string
    {
        return "Tax Summary";
    }
}

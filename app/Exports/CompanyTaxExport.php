<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

/**
 * Company tax report export — one row per ATO Company Tax Return label
 * with the audit-trail columns defined in ATO_tax_report_spec.md §6.1.
 * Used for both the Excel (xlsx) and CSV downloads.
 */
class CompanyTaxExport implements FromCollection, WithTitle, ShouldAutoSize
{
    protected int $fyEnd;
    protected array $statement;

    public function __construct(int $fyEnd, array $statement)
    {
        $this->fyEnd = $fyEnd;
        $this->statement = $statement;
    }

    public function collection()
    {
        $entity = $this->statement['entity'];
        $overallStatus = collect($this->statement['validations'])
            ->every(fn ($v) => $v['status'] === 'PASS') ? 'PASS' : 'FAIL';

        $data = collect();

        $data->push(['Company Tax Return ' . $this->fyEnd . ' — ' . $entity['name']]);
        $data->push([
            'Income year: ' . $this->statement['fyStart']->format('d/m/Y') . ' to '
                . $this->statement['fyEnd']->format('d/m/Y') . ' (cash basis, GST-exclusive)',
        ]);
        $data->push([]);

        $data->push([
            'entity_abn',
            'entity_tfn',
            'income_year',
            'ato_item',
            'ato_label',
            'ato_field_name',
            'amount_aud',
            'source_transaction_ids',
            'source_line_item_ids',
            'adjustment_reference',
            'validation_status',
        ]);

        $rowFor = function (string $item, string $label, string $name, $amount) use ($entity, $overallStatus): array {
            return [
                $entity['abn'],
                $entity['tfn'],
                $this->fyEnd,
                $item,
                $label,
                $name,
                $amount === null ? '' : (int) round($amount),
                '',
                '',
                '',
                $overallStatus,
            ];
        };

        foreach ($this->statement['income'] as $row) {
            $line = $rowFor('6', $row['label'], $row['name'], $row['amount']);
            $line[7] = $row['transaction_ids'];
            $line[8] = $row['line_item_ids'];
            $data->push($line);
        }

        $data->push($rowFor('6', 'T', 'Total profit or loss', $this->statement['profitOrLoss']));

        foreach ($this->statement['expenses'] as $row) {
            $line = $rowFor('6', $row['label'], $row['name'], $row['amount']);
            $line[7] = $row['transaction_ids'];
            $line[8] = $row['line_item_ids'];
            $data->push($line);
        }

        foreach ($this->statement['reconciliation'] as $row) {
            $data->push($rowFor('7', $row['label'], $row['name'], $row['amount']));
        }

        foreach ($this->statement['financialInfo'] as $row) {
            $data->push($rowFor('8', $row['label'], $row['name'], $row['amount']));
        }

        $data->push($rowFor('10', 'A/B', 'SBE simplified depreciation — capital purchases reference', $this->statement['capitalPurchases']['total']));

        return $data;
    }

    public function title(): string
    {
        return 'Company Tax ' . $this->fyEnd;
    }
}

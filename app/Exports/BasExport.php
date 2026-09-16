<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class BasExport implements FromCollection, ShouldAutoSize, WithTitle
{
    protected $fyEnd;

    protected $statement;

    public function __construct(int $fyEnd, array $statement)
    {
        $this->fyEnd = $fyEnd;
        $this->statement = $statement;
    }

    public function collection()
    {
        $data = collect();

        $data->push(['BAS — Business Activity Statement (FY'.$this->fyEnd.')']);
        $data->push([
            'Period: '.$this->statement['fyStart']->format('d/m/Y').' to '.$this->statement['fyEnd']->format('d/m/Y'),
        ]);
        $data->push([]);

        $data->push([
            'Quarter',
            'Period',
            'G1 Total sales (incl GST)',
            'G10 Capital purchases (incl GST)',
            'G11 Non-capital purchases (incl GST)',
            'W1 Total salary/wages',
            'W2 Amounts withheld',
            '1A GST on sales',
            '1B GST on purchases',
            'Net GST',
        ]);

        foreach ($this->statement['quarters'] as $q) {
            $data->push([
                $q['label'],
                $q['start']->format('d/m/Y').' - '.$q['end']->format('d/m/Y'),
                number_format($q['g1'], 2),
                number_format($q['g10'], 2),
                number_format($q['g11'], 2),
                number_format($q['w1'], 2),
                number_format($q['w2'], 2),
                number_format($q['gst_sales'], 2),
                number_format($q['gst_purchases'], 2),
                number_format($q['net'], 2),
            ]);
        }

        $totals = $this->statement['totals'];
        $data->push([
            'FY'.$this->fyEnd.' Total',
            '',
            number_format($totals['g1'], 2),
            number_format($totals['g10'], 2),
            number_format($totals['g11'], 2),
            number_format($totals['w1'], 2),
            number_format($totals['w2'], 2),
            number_format($totals['gst_sales'], 2),
            number_format($totals['gst_purchases'], 2),
            number_format($totals['net'], 2),
        ]);

        return $data;
    }

    public function title(): string
    {
        return 'BAS FY'.$this->fyEnd;
    }
}

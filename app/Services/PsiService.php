<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EntitySetting;
use App\Models\Invoice;
use App\Models\Payslip;
use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;

/**
 * Personal services income assessment (the .zcode wages_and_psi
 * spec, modules C and E). Service-work income is invoiced time —
 * invoices backed by time entries are time-for-money by construction,
 * exactly what the ATO treats as PSI candidates. The 80% rule watches
 * how much of that income comes from one client; breaching it leaves
 * the PSB Results Test (specific result, own equipment, liable for
 * defects) as the only way out, and failing it locks PSI mode, where
 * deductions are restricted and the net PSI is attributed to the
 * individual who performed the work.
 */
class PsiService
{
    /**
     * The FY's service-work income by client: subtotal (ex-GST) of
     * non-draft/non-cancelled invoices issued in the FY whose lines
     * link time entries.
     *
     * @return array{fy: int, start: Carbon, end: Carbon, rows: list<array{client_id: ?int, client: ?string, amount: float, share: float}>, total: float, top_share: float, breaches_80: bool}
     */
    public function incomeByClient(Entity $entity, ?int $fy = null): array
    {
        $fy ??= ReportingPeriod::year(now(), $entity);
        ['start' => $start, 'end' => $end] = (new FiscalYearService)->bounds($entity, $fy);
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $amounts = Invoice::query()
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED])
            ->whereHas('items', fn ($q) => $q->whereNotNull('time_entry_id'))
            ->with('client:id,name')
            ->get()
            ->groupBy('client_id')
            ->map(fn ($invoices) => (float) $invoices->sum('subtotal'));

        $total = round($amounts->sum(), 2);

        $rows = $amounts
            ->map(fn ($amount, $clientId) => [
                'client_id' => $clientId,
                'client' => optional(Client::find($clientId))->name ?? 'No client',
                'amount' => $amount,
                'share' => $total > 0 ? round($amount / $total, 4) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $topShare = $rows[0]['share'] ?? 0.0;

        return [
            'fy' => $fy,
            'start' => $start,
            'end' => $end,
            'rows' => $rows,
            'total' => $total,
            'top_share' => $topShare,
            'breaches_80' => $total > 0 && $topShare >= 0.8,
        ];
    }

    /**
     * The PSI attribution flow (module E): PSI received less salary
     * and wages promptly paid to the individuals who performed the
     * work — the remainder is attributed to the individual's personal
     * return; the company is a conduit for it.
     *
     * @return array{psi_income: float, wages_paid: float, net_psi: float}
     */
    public function attribution(Entity $entity, ?int $fy = null): array
    {
        $income = $this->incomeByClient($entity, $fy);

        $fy ??= $income['fy'];
        ['start' => $start, 'end' => $end] = (new FiscalYearService)->bounds($entity, $fy);

        $wages = round((float) Payslip::query()
            ->whereHas('payRun', fn ($q) => $q->where('entity_id', $entity->id)
                ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()]))
            ->whereHas('employee', fn ($q) => $q->where('is_personal_services', true))
            ->sum('gross'), 2);

        return [
            'psi_income' => $income['total'],
            'wages_paid' => $wages,
            'net_psi' => round(max(0.0, $income['total'] - $wages), 2),
        ];
    }

    /**
     * Record the PSB Results Test answers and re-derive PSI mode:
     * passing all three (paid for a result, own equipment, liable for
     * defects) means a personal services business — the PSI rules
     * don't apply. Failing any locks PSI mode.
     */
    public function recordResultsTest(Entity $entity, array $answers): EntitySetting
    {
        $answers = [
            'specific_result' => (bool) ($answers['specific_result'] ?? false),
            'own_equipment' => (bool) ($answers['own_equipment'] ?? false),
            'liable_for_defects' => (bool) ($answers['liable_for_defects'] ?? false),
        ];

        $passes = $answers['specific_result'] && $answers['own_equipment'] && $answers['liable_for_defects'];

        return EntitySetting::updateOrCreate(
            ['entity_id' => $entity->id],
            [
                'psb_results' => $answers + ['passes' => $passes],
                'psi_assessed_at' => now(),
                'psi_mode' => ! $passes,
            ],
        );
    }
}

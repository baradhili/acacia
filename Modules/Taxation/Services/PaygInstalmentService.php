<?php

namespace Modules\Taxation\Services;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Scopes\EntityScope;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Taxation\Models\BasSettlement;
use Modules\Taxation\Models\PaygInstalmentAccrual;

/**
 * The quarterly PAYG instalment accrual: the estimate journal that
 * raises the income tax liability the payg_instalment BAS settlement
 * later nets — Dr income tax expense (8400) / Cr income tax payable
 * (2240), amount = instalment income for the quarter × the
 * ATO-notified instalment rate from the bas.installment_rate config
 * (BAS_INSTALLMENT_RATE). Until that rate is set the accrual refuses
 * — the config was a dead stub before this service existed.
 *
 * Instalment income is the quarter's assessable income on the cash
 * basis the ledger keeps — the same basis the ATO company tax report
 * uses for its Item 6 income: revenue-account ledger legs whose parent
 * transaction settled through a bank account, summed credits-minus-
 * debits per account, so the GST back-out legs — which debit revenue
 * for the GST portion of a GST-inclusive receipt — net back out. The
 * accrual itself never feeds back into the base (8400 is
 * an expense account, 2240 a liability, and the journal's main account
 * is not a bank), so quarters compound cleanly.
 *
 * Accruals are snapshots: revenue backdated after the accrual does not
 * rewrite it — reverse and re-accrue the quarter instead. Like BAS
 * settlements, the journal is dated the quarter end, so the same
 * locked-period / closed-year guards apply before anything posts.
 */
class PaygInstalmentService
{
    public function __construct(protected PeriodLockService $locks) {}

    /**
     * The configured instalment rate as a percent (e.g. 25), or null
     * while BAS_INSTALLMENT_RATE is unset — the accrual's off switch.
     * Rounded to the snapshot column's four decimals so the stored rate
     * always explains the posted amount (a 12.34567% rate would
     * otherwise journal at full precision while the row remembers only
     * 12.3457%).
     */
    public function rate(): ?float
    {
        $rate = config('australian.bas.installment_rate');

        if ($rate === null || $rate === '') {
            return null;
        }

        return round((float) $rate, 4);
    }

    /**
     * The BAS quarter a date belongs to, as the report arithmetic
     * defines quarters: consecutive three-month blocks of the FY from
     * entity.year_start. Only an exact quarter end matches (that is
     * what the accrual keys on); null otherwise.
     *
     * @return array{fy: int, quarter: int, start: Carbon, end: Carbon}|null
     */
    public function quarterFor(Entity $entity, Carbon $quarterEnd): ?array
    {
        $service = new FiscalYearService;
        $wanted = $quarterEnd->copy()->startOfDay()->toDateString();

        // The current FY and the one before — the same window the BAS
        // report and the settlement quick picks cover, wide enough for
        // any quarter an accrual could reasonably target.
        $current = ReportingPeriod::year(now(), $entity);

        foreach ([$current - 1, $current] as $fy) {
            ['start' => $start] = $service->bounds($entity, $fy);

            foreach ([0, 1, 2, 3] as $i) {
                $end = $start->copy()->addMonths($i * 3 + 2)->endOfMonth()->startOfDay();
                if ($end->toDateString() === $wanted) {
                    return [
                        'fy' => $fy,
                        'quarter' => $i + 1,
                        'start' => $start->copy()->addMonths($i * 3)->startOfDay(),
                        'end' => $end,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Instalment income for [$start, $end]: assessable income on the
     * cash basis, the company tax report's Item 6 basis scoped to the
     * quarter — revenue-account ledger legs from bank-settled
     * transactions (so non-cash journals never count), excluding
     * year-end closing entries, already net of GST.
     */
    public function instalmentIncome(Entity $entity, Carbon $start, Carbon $end): float
    {
        $income = FiscalYearService::excludeClosingEntries(
            DB::table('ifrs_ledgers as l')
                ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
                ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
                ->join('ifrs_accounts as bank', 'bank.id', '=', 't.account_id')
                ->where('a.entity_id', $entity->id)
                ->whereIn('a.account_type', [Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE])
                ->where('bank.account_type', Account::BANK)
                ->whereBetween('l.posting_date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->whereNull('l.deleted_at')
                ->whereNull('t.deleted_at')
        )->selectRaw(
            "COALESCE(SUM(CASE WHEN l.entry_type = '".Balance::CREDIT."' THEN l.amount ELSE -l.amount END), 0) as income"
        )->value('income');

        return round((float) $income, 2);
    }

    /**
     * What accruing $quarterEnd would post — the settlements screen's
     * accrual card. The rate is carried (null when unset) so the screen
     * can show its configuration hint instead of refusing; the quarter
     * must be a real BAS quarter end, and `accrued` says whether a
     * live accrual already covers it.
     *
     * @return array{rate: ?float, quarter: array{fy: int, quarter: int, start: Carbon, end: Carbon}, income: float, amount: ?float, accrued: bool, accrual: ?PaygInstalmentAccrual}
     */
    public function estimate(Entity $entity, Carbon $quarterEnd): array
    {
        $quarter = $this->quarterFor($entity, $quarterEnd)
            ?? throw new \InvalidArgumentException(
                $quarterEnd->format('d M Y').' is not a BAS quarter end.'
            );

        $rate = $this->rate();
        $income = $this->instalmentIncome($entity, $quarter['start'], $quarter['end']);

        return [
            'rate' => $rate,
            'quarter' => $quarter,
            'income' => $income,
            'amount' => $rate === null ? null : round($income * $rate / 100, 2),
            'accrued' => $this->existingAccrual($entity, $quarter['end']) !== null,
            'accrual' => $this->existingAccrual($entity, $quarter['end']),
        ];
    }

    /**
     * Record the quarter's accrual: compute instalment income × rate,
     * post Dr expense / Cr payable dated the quarter end, and persist
     * the snapshot row — in one transaction.
     *
     * @param  array{period_end: mixed, notes?: ?string}  $data
     */
    public function accrue(array $data): PaygInstalmentAccrual
    {
        $entity = IfrsPosting::resolveEntity();

        $rate = $this->rate();
        if ($rate === null) {
            throw new \InvalidArgumentException(
                'The PAYG instalment rate is not configured — set BAS_INSTALLMENT_RATE (australian.bas.installment_rate).'
            );
        }

        $periodEnd = Carbon::parse($data['period_end'])->startOfDay();
        $quarter = $this->quarterFor($entity, $periodEnd)
            ?? throw new \InvalidArgumentException($periodEnd->format('d M Y').' is not a BAS quarter end.');

        $label = sprintf('Q%d FY%d', $quarter['quarter'], $quarter['fy']);

        if ($quarter['end']->copy()->endOfDay()->greaterThan(now())) {
            throw new \InvalidArgumentException("{$label} has not ended yet — nothing to accrue.");
        }

        $this->assertDatePostable($quarter['end'], $entity, 'accrual date');

        [$expense, $payable] = $this->accounts($entity);

        return DB::transaction(function () use ($entity, $quarter, $label, $rate, $expense, $payable, $data) {
            // Serialize accrues per entity: the row lock is held until
            // commit, so two requests for the same quarter cannot both
            // pass the duplicate check and post (the check and the
            // posting are one transaction). A reversed accrual frees
            // the quarter — nothing here blocks re-accrual. (SQLite,
            // the test driver, ignores lock hints; single-connection
            // tests never race anyway.)
            Entity::withoutGlobalScope(EntityScope::class)
                ->whereKey($entity->id)
                ->lockForUpdate()
                ->first();

            if ($this->existingAccrual($entity, $quarter['end']) !== null) {
                throw new \InvalidArgumentException("{$label} is already accrued — reverse the accrual first.");
            }

            $income = $this->instalmentIncome($entity, $quarter['start'], $quarter['end']);
            if ($income <= 0.005) {
                throw new \InvalidArgumentException("There is no instalment income for {$label} — nothing to accrue.");
            }

            $amount = round($income * $rate / 100, 2);
            if ($amount < 0.005) {
                throw new \InvalidArgumentException(
                    "A {$rate}% rate on \${$income} of instalment income accrues nothing for {$label}."
                );
            }

            IfrsPosting::ensureReportingPeriod($quarter['end'], $entity);

            $journal = new JournalEntry([
                'transaction_date' => IfrsPosting::transactionDate($quarter['end'], $entity),
                'account_id' => $expense->id,
                'credited' => false, // main debited; the line takes the credit
                'entity_id' => $entity->id,
                'currency_id' => $entity->currency_id,
                'narration' => sprintf(
                    'PAYG instalment accrual — Q%d FY%d (%s%% of $%s) to %s',
                    $quarter['quarter'], $quarter['fy'],
                    rtrim(rtrim(number_format($rate, 2), '0'), '.'),
                    number_format($income, 2),
                    $quarter['end']->format('d M Y'),
                ),
                'reference' => 'PAYGI-ACCR-'.$quarter['end']->format('Ymd'),
            ]);

            // Persisted before addLineItem(): unsaved items share a null
            // id and the package silently drops all but the first.
            $journal->addLineItem(LineItem::create([
                'account_id' => $payable->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $entity->id,
            ]));

            $journal->post();

            $accrual = PaygInstalmentAccrual::create([
                'entity_id' => $entity->id,
                'period_start' => $quarter['start']->toDateString(),
                'period_end' => $quarter['end']->toDateString(),
                'fy' => $quarter['fy'],
                'quarter' => $quarter['quarter'],
                'instalment_income' => $income,
                'rate' => $rate,
                'amount' => $amount,
                'ifrs_transaction_id' => $journal->id,
                'notes' => $data['notes'] ?? null,
            ]);

            Log::info('PAYG instalment accrual posted', [
                'accrual_id' => $accrual->id,
                'period_end' => $quarter['end']->toDateString(),
                'income' => $income,
                'rate' => $rate,
                'amount' => $amount,
                'transaction_id' => $journal->id,
            ]);

            return $accrual;
        });
    }

    /**
     * Mirror an accrual's journal back out (a recorded mistake, or the
     * precursor to re-accruing a quarter after backdated revenue) and
     * mark the accrual reversed. Refuses while a settlement still
     * covers the accrual — checked under the entity row lock inside the
     * posting transaction, the same lock settle() takes, so a
     * settlement committing concurrently cannot slip past the check.
     * The reversal keeps the original transaction date, so the same
     * date/period guards as posting apply first, and the ledger
     * reversal and the accrual state commit or roll back together.
     */
    public function reverse(PaygInstalmentAccrual $accrual): PaygInstalmentAccrual
    {
        if ($accrual->isReversed()) {
            throw new \InvalidArgumentException('This accrual has already been reversed.');
        }

        if (! $accrual->ifrs_transaction_id) {
            throw new \InvalidArgumentException('This accrual has no posted journal to reverse.');
        }

        $this->assertDatePostable($accrual->period_end, $accrual->entity, 'accrual date');

        return DB::transaction(function () use ($accrual) {
            // The entity row lock accrue() and BasSettlementService::
            // settle() also take, held to commit. Checking coverage
            // under it closes the window where a settlement commits
            // between this check and the reversal's posting — the
            // settlement serialises behind (this sees it) or ahead of
            // (its position read excludes the reversal) this lock.
            Entity::withoutGlobalScope(EntityScope::class)
                ->whereKey($accrual->entity_id)
                ->lockForUpdate()
                ->first();

            // A settlement consumed this accrual only if it was recorded
            // after the accrual was posted (settlements net live
            // balances — one recorded earlier never saw this journal,
            // however its as-at date compares) AND its as-at read
            // covers the accrual date. Reversing only a consumed
            // accrual would leave that bank payment standing and 2240
            // debited: a fictitious overpayment the next settlement
            // would "refund". The settlement must be reversed first
            // (both income-tax types settle 2240, so either can be the
            // covering one). created_at has second precision, so a
            // same-second pair counts as covering — conservative, it
            // blocks a reversal rather than strands a payment.
            $covered = BasSettlement::query()
                ->where('entity_id', $accrual->entity_id)
                ->whereIn('type', BasSettlement::INCOME_TAX_TYPES)
                ->whereNull('reversed_at')
                ->whereDate('as_at', '>=', $accrual->period_end->toDateString())
                ->where('created_at', '>=', $accrual->created_at)
                ->exists();

            if ($covered) {
                throw new \InvalidArgumentException(
                    'A settlement has already covered this accrual — reverse that settlement first, then the accrual.'
                );
            }

            $reversalId = IfrsPosting::reverseTransaction(
                $accrual->ifrs_transaction_id,
                'Reversal of PAYG instalment accrual — '.$accrual->label(),
                'PAYGI-ACCR-'.$accrual->period_end->format('Ymd').'-REV',
                throw: true,
            );

            $accrual->forceFill([
                'reversal_transaction_id' => $reversalId,
                'reversed_at' => now(),
            ])->save();

            Log::info('PAYG instalment accrual reversed', [
                'accrual_id' => $accrual->id,
                'reversal_id' => $reversalId,
            ]);

            return $accrual;
        });
    }

    /**
     * The live (non-reversed) accrual covering a quarter end, if any.
     * whereDate, because the date cast stores Y-m-d H:i:s while the
     * quarter arithmetic deals in bare dates (BasSettlementService's
     * settlement queries make the same comparison).
     */
    protected function existingAccrual(Entity $entity, Carbon $periodEnd): ?PaygInstalmentAccrual
    {
        return PaygInstalmentAccrual::query()
            ->where('entity_id', $entity->id)
            ->whereDate('period_end', $periodEnd->toDateString())
            ->whereNull('reversed_at')
            ->first();
    }

    /**
     * @return array{0: Account, 1: Account} [income tax expense, income tax payable]
     */
    protected function accounts(Entity $entity): array
    {
        $expense = Account::where('entity_id', $entity->id)
            ->where('code', config('australian.bas.tax_expense_account_code', 8400))
            ->first();
        $payable = Account::where('entity_id', $entity->id)
            ->where('code', config('australian.bas.income_tax_account_code', 2240))
            ->first();

        if (! $expense || ! $payable) {
            throw new \InvalidArgumentException(
                'The income tax accounts are not configured — seed the chart of accounts (codes '
                .config('australian.bas.tax_expense_account_code', 8400).' and '
                .config('australian.bas.income_tax_account_code', 2240).').'
            );
        }

        return [$expense, $payable];
    }

    /**
     * Refuse posting into a closed IFRS year or a locked app period —
     * the same guards BasSettlementService applies.
     */
    protected function assertDatePostable($date, Entity $entity, string $label): void
    {
        $date = Carbon::parse($date);

        if ($this->locks->isDateLocked($date)) {
            throw new \InvalidArgumentException("The {$label} falls in a locked period ({$date->toDateString()}).");
        }

        if ($this->locks->isDateBlocked($date, $entity)) {
            throw new \InvalidArgumentException(
                $this->locks->dateBlockedMessage($date, $entity)
                ?? "The {$label} falls in a closed financial year ({$date->toDateString()})."
            );
        }
    }
}

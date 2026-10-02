<?php

namespace Modules\Payroll\Services;

use App\Models\EntitySetting;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayRun;
use Modules\Payroll\Models\Payslip;

/**
 * Australian payroll: PAYG withholding from the ATO's NAT 1004
 * Schedule 1 statement of formulas (weekly coefficients in
 * config/payroll.php, converted per pay frequency the way the ATO
 * specifies), super guarantee on ordinary earnings, and the
 * two-stage-style posting the dividend flow uses:
 *
 *   1. Dr Salaries & Wages (gross)      / Cr PAYG Withheld, Cr Wages Payable (net)
 *   2. Dr Superannuation Expense        / Cr Superannuation Payable
 *   3. Dr Wages Payable (net)           / Cr Bank
 *
 * PAYG withheld lands in the same 2210 liability the BAS settlement
 * screen nets, so settled PAYG flows through the existing BAS
 * workflow. Closely linked payees and personal services workers are
 * flagged on the employee, snapshotted onto their payslips, and shown
 * on the run — contractors (typically PSI workers) withhold nothing
 * and draw no super.
 *
 * A psi_residual payee (the conduit-company director) draws the PSI
 * attribution remainder as their gross on quarterly runs — see
 * psiResidualGross() for the gates; an explicit gross always wins at
 * entry, though processing still refuses a psi_residual payslip
 * above the remaining requirement.
 */
class PayrollService
{
    public function __construct(
        protected PeriodLockService $locks,
        protected PsiService $psi,
    ) {}

    /**
     * PAYG withholding for a gross amount in the pay frequency, per
     * NAT 1004 Schedule 1: x is the weekly equivalent of the period's
     * earnings with cents dropped and 99 cents added back (the ATO's
     * construction — the coefficients are calibrated against it, and
     * the "add 99 cents" is what keeps fractional weekly equivalents
     * from under-withholding at rounding boundaries), y = ax - b
     * rounded to the nearest dollar, then scaled back to the period —
     * fortnightly/quarterly exactly (whole weekly dollars), monthly
     * × 13 ÷ 3 rounded to the nearest dollar. The x construction was
     * missing until an STP-certified app cross-check on a quarterly
     * director payment caught it ($7,852 vs our $7,839 — one weekly
     * unit). A payment covering a quarter spreads across its 13
     * weeks: weekly equivalent ÷ 13, result × 13.
     */
    public function withholding(Employee $employee, float $gross, string $frequency): float
    {
        if ($gross <= 0 || ! $employee->withholdsPayg()) {
            return 0.0;
        }

        // No TFN provided → the ATO flat rate. Scale 4 ignores cents
        // on both sides: whole-dollar earnings, whole-dollar result.
        if (empty($employee->tfn)) {
            return (float) floor(floor($gross) * (float) config('payroll.no_tfn_rate', 0.47));
        }

        $scale = $employee->taxScale();

        $y = $this->applyScale($scale, $this->weeklyEarnings($gross, $frequency));
        if ($y <= 0) {
            return 0.0;
        }

        $perPeriod = match ($frequency) {
            'weekly' => $y,
            'fortnightly' => $y * 2,
            'monthly' => round($y * 13 / 3),
            'quarterly' => $y * 13,
        };

        return (float) $perPeriod;
    }

    /**
     * x for the formulas: the weekly equivalent of the period's
     * earnings, cents ignored, plus 99 cents — NAT 1004's stated
     * steps for weekly ("ignore cents, add 99 cents"), fortnightly
     * (÷ 2), monthly (× 3 ÷ 13, with the quirk that an amount ending
     * in exactly 33 cents gains a cent first) and quarterly (÷ 13).
     */
    protected function weeklyEarnings(float $gross, string $frequency): float
    {
        $weekly = match ($frequency) {
            'weekly' => $gross,
            'fortnightly' => $gross / 2,
            'monthly' => (((int) round($gross * 100)) % 100 === 33 ? $gross + 0.01 : $gross) * 3 / 13,
            'quarterly' => $gross / 13,
            default => throw new \InvalidArgumentException("Unknown pay frequency {$frequency}."),
        };

        return floor($weekly) + 0.99;
    }

    /**
     * y = ax - b (x = whole dollars of the weekly equivalent + 99c,
     * against the ATO coefficient bands), rounded to the nearest
     * dollar.
     */
    protected function applyScale(int $scale, float $x): float
    {
        $bands = config("payroll.scale_{$scale}");

        if (! is_array($bands)) {
            throw new \InvalidArgumentException("Unknown PAYG withholding scale {$scale}.");
        }

        foreach ($bands as [$lessThan, $a, $b]) {
            if ($lessThan === null || $x < $lessThan) {
                return round($x * $a - $b);
            }
        }

        return 0.0;
    }

    /**
     * Super contribution on ordinary earnings for a pay date: the
     * employee's own rate, else the super guarantee rate in force
     * (12% from 1 July 2026, 11.5% before).
     */
    public function superContribution(Employee $employee, float $gross, $payDate): float
    {
        if ($gross <= 0 || ! $employee->earnsSuper()) {
            return 0.0;
        }

        $rate = $employee->super_rate ?? $this->sgRate(Carbon::parse($payDate));

        return round($gross * (float) $rate, 2);
    }

    public function sgRate(Carbon $payDate): float
    {
        $rate = 0.095; // pre-2023 fallback

        foreach (config('payroll.sg_rates', []) as $fyStart => $fyRate) {
            if ($payDate->gte(Carbon::parse($fyStart))) {
                $rate = max($rate, (float) $fyRate);
            }
        }

        return $rate;
    }

    /**
     * The gross an employee would draw for a period: salary
     * apportioned per frequency (hourly staff need hours supplied).
     */
    public function defaultGross(Employee $employee, string $frequency): ?float
    {
        if ($employee->payment_basis === Employee::BASIS_SALARY && $employee->annual_salary !== null) {
            $periods = (int) config("payroll.periods_per_year.{$frequency}", 26);

            return round((float) $employee->annual_salary / $periods, 2);
        }

        return null;
    }

    /**
     * The gross a psi_residual payee draws on a run: the entity's PSI
     * attribution remainder for the run's financial year — PSI income
     * less wages already paid to PSI workers this FY (processed runs
     * only, so the draft being built never nets itself out), less
     * PSI-residual payslips already seeded on other DRAFT runs for
     * the same entity and FY — the reservation that keeps two
     * concurrent quarterly drafts from each seeding the full
     * remainder and overpaying when both process. Ordinary draft
     * wages reserve nothing: they are not PSI payments and a draft
     * may never be processed. Refused, with the reason, unless: the
     * run is quarterly (the cadence the conduit-company flow
     * assumes), PSI mode is on (a passed Results Test means the
     * rules — and any required amount — don't apply), exactly one
     * such payee is active (the remainder is entity-wide; two takers
     * would each draw it in full), and the remainder is above zero.
     * An explicit gross override skips all of this.
     */
    protected function psiResidualGross(Employee $employee, PayRun $run): float
    {
        if ($run->frequency !== 'quarterly') {
            throw new \InvalidArgumentException(
                'PSI-residual payslips belong on quarterly runs — pay this payee with an explicit gross instead.'
            );
        }

        $entity = IfrsPosting::resolveEntity();

        if (! EntitySetting::forEntity($entity)->psi_mode) {
            throw new \InvalidArgumentException(
                'PSI mode is off (the Results Test passes, or has not been recorded) — no attribution remainder is defined; pay an explicit gross.'
            );
        }

        $residualPayees = Employee::where('entity_id', $run->entity_id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->where('payment_basis', Employee::BASIS_PSI_RESIDUAL)
            ->count();

        if ($residualPayees > 1) {
            throw new \InvalidArgumentException(
                'More than one PSI-residual payee is active — the remainder is entity-wide; enter an explicit gross.'
            );
        }

        $residual = $this->psiRemainderExcludingRun($run, $entity);

        if ($residual <= 0) {
            throw new \InvalidArgumentException(
                'The PSI attribution remainder for this financial year is zero (or already reserved by another draft run) — nothing is required to pay.'
            );
        }

        return $residual;
    }

    /**
     * The numeric remainder behind psiResidualGross(): attribution
     * net PSI for the run's FY, less PSI-residual payslips seeded on
     * other draft runs for the same entity and FY. Callers that act
     * on the result (createRun's seeding, process()'s staleness
     * check) hold the entity-row lock so concurrent runs serialise
     * instead of both reading the same reservation state.
     */
    protected function psiRemainderExcludingRun(PayRun $run, Entity $entity): float
    {
        $fy = ReportingPeriod::year($run->payment_date, $entity);
        $attribution = $this->psi->attribution($entity, $fy);
        ['start' => $start, 'end' => $end] = (new FiscalYearService)->bounds($entity, $fy);

        $reserved = (float) Payslip::query()
            ->whereHas('payRun', fn ($q) => $q->where('entity_id', $run->entity_id)
                ->where('status', PayRun::STATUS_DRAFT)
                ->where('id', '!=', $run->id)
                ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()]))
            ->whereHas('employee', fn ($q) => $q->where('payment_basis', Employee::BASIS_PSI_RESIDUAL))
            ->sum('gross');

        return round($attribution['net_psi'] - $reserved, 2);
    }

    /**
     * Processing guard: a psi_residual payslip was computed when it
     * was seeded and the requirement may have moved since. Under the
     * entity lock (the one createRun's seeding holds), re-derive
     * today's remainder and refuse the run when its PSI-residual
     * payslips' COMBINED gross exceeds it — checked per run, not per
     * payslip, so two partial payslips can't each slip under the cap.
     * Reverse the run to draft and re-add the payslips to refresh.
     * Applies to explicit overrides as well: this basis pays what PSI
     * requires and no more, and posting is where that invariant is
     * enforced.
     */
    protected function assertPsiResidualsWithinRemainder(PayRun $run, Entity $entity): void
    {
        $psiPayslips = $run->payslips()
            ->whereHas('employee', fn ($q) => $q->where('payment_basis', Employee::BASIS_PSI_RESIDUAL))
            ->get();

        if ($psiPayslips->isEmpty()) {
            return;
        }

        DB::table('ifrs_entities')->where('id', $run->entity_id)->lockForUpdate()->first();

        $available = $this->psiRemainderExcludingRun($run, $entity);
        $psiGross = round((float) $psiPayslips->sum('gross'), 2);

        if ($psiGross > $available + 0.005) {
            throw new \InvalidArgumentException(sprintf(
                'The run\'s PSI-residual payslips ($%s combined) exceed the remaining PSI requirement ($%s) — reverse the run to draft and re-add the payslip to refresh the amount.',
                number_format($psiGross, 2),
                number_format($available, 2),
            ));
        }
    }

    /**
     * Compute (not persist) a payslip's figures for an employee:
     * gross from the PSI attribution remainder (psi_residual payees),
     * hours × rate, salary apportionment, or an explicit override —
     * in that order of precedence.
     *
     * @return array{hours: ?float, gross: float, payg_withheld: float, super: float, net_pay: float}
     */
    public function computePayslip(Employee $employee, PayRun $run, ?float $hours = null, ?float $grossOverride = null): array
    {
        $gross = $grossOverride ?? match (true) {
            $employee->payment_basis === Employee::BASIS_PSI_RESIDUAL => $this->psiResidualGross($employee, $run),
            $hours !== null && $employee->hourly_rate !== null => round($hours * (float) $employee->hourly_rate, 2),
            default => $this->defaultGross($employee, $run->frequency),
        };

        if ($gross === null || $gross <= 0) {
            throw new \InvalidArgumentException(
                'Cannot compute a payslip without a gross — provide hours, a rate, a salary or an override.'
            );
        }

        $payg = $this->withholding($employee, $gross, $run->frequency);
        $super = $this->superContribution($employee, $gross, $run->payment_date);

        return [
            'hours' => $hours,
            'gross' => round($gross, 2),
            'payg_withheld' => $payg,
            'super' => $super,
            'net_pay' => round($gross - $payg, 2),
        ];
    }

    public function createRun(array $data): PayRun
    {
        return DB::transaction(function () use ($data) {
            $run = PayRun::create([
                'entity_id' => IfrsPosting::resolveEntity()->id,
                'period_start' => Carbon::parse($data['period_start']),
                'period_end' => Carbon::parse($data['period_end']),
                'payment_date' => Carbon::parse($data['payment_date']),
                'frequency' => $data['frequency'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Serialise PSI-residual seeding on the entity row — the
            // same lock process() takes for its staleness check.
            // Without it, two concurrent runs read the same
            // reservation state before either payslip lands and both
            // seed the full remainder.
            DB::table('ifrs_entities')->where('id', $run->entity_id)->lockForUpdate()->first();

            // Seed a payslip for every active employee whose defaults
            // can be computed — salary staff anywhere, a psi_residual
            // director on a quarterly run; hourly staff get theirs as
            // hours are entered, and gated payees are skipped until
            // the gate opens.
            foreach (Employee::where('entity_id', $run->entity_id)->where('status', Employee::STATUS_ACTIVE)->get() as $employee) {
                try {
                    $this->addPayslip($run, $employee);
                } catch (\InvalidArgumentException) {
                    continue; // needs hours — skipped until entered
                }
            }

            return $run->refresh();
        });
    }

    /**
     * Add or refresh an employee's payslip on a draft run (one per
     * employee per run).
     */
    public function addPayslip(PayRun $run, Employee $employee, ?float $hours = null, ?float $grossOverride = null, ?string $notes = null): Payslip
    {
        if ($run->isProcessed()) {
            throw new \InvalidArgumentException('This pay run is already processed — reverse it to edit.');
        }

        $figures = $this->computePayslip($employee, $run, $hours, $grossOverride);

        return Payslip::updateOrCreate(
            ['pay_run_id' => $run->id, 'employee_id' => $employee->id],
            $figures + [
                'is_closely_linked' => (bool) $employee->is_closely_linked,
                'is_personal_services' => (bool) $employee->is_personal_services,
                'notes' => $notes,
            ],
        );
    }

    /**
     * Process a draft run: post the three journals and stamp the run.
     * Locked or closed payment dates refuse before anything posts.
     */
    public function process(PayRun $run): PayRun
    {
        if ($run->isProcessed()) {
            throw new \InvalidArgumentException('This pay run is already processed.');
        }

        if ($run->payslips()->count() === 0) {
            throw new \InvalidArgumentException('Add at least one payslip before processing.');
        }

        $entity = IfrsPosting::resolveEntity();
        $this->assertDatePostable($run->payment_date, $entity, 'payment date');

        $gross = round((float) $run->payslips()->sum('gross'), 2);
        $payg = round((float) $run->payslips()->sum('payg_withheld'), 2);
        $super = round((float) $run->payslips()->sum('super'), 2);
        $net = round($gross - $payg, 2);

        return DB::transaction(function () use ($run, $entity, $gross, $payg, $super, $net) {
            // A psi_residual payslip was computed when it was seeded;
            // the requirement may have moved since (another run
            // processed, a run reversed, an invoice cancelled). Under
            // the same entity lock as seeding, refuse one that now
            // exceeds the remainder.
            $this->assertPsiResidualsWithinRemainder($run, $entity);

            $wages = $this->account($entity, 'wages_expense');
            $superExpense = $this->accountOrCreate($entity, 'super_expense', Account::OPERATING_EXPENSE, 'Superannuation Expense');
            $paygAccount = $this->account($entity, 'payg_withholding');
            $superPayable = $this->account($entity, 'super_payable');
            $wagesPayable = $this->accountOrCreate($entity, 'wages_payable', Account::CURRENT_LIABILITY, 'Wages Payable');
            $bank = $this->account($entity, 'bank');

            // 1. Wages accrual: Dr Salaries & Wages (gross) against the
            //    withheld PAYG and the net owed to employees.
            $accrual = $this->postJournal($run, $entity, $wages, false, [
                [$paygAccount, $payg],
                [$wagesPayable, $net],
            ], 'ACC');

            // 2. Super accrual: Dr Superannuation Expense / Cr payable.
            $superJournal = $super > 0 ? $this->postJournal($run, $entity, $superExpense, false, [
                [$superPayable, $super],
            ], 'SUP') : null;

            // 3. Payment: Dr Wages Payable / Cr Bank for the net.
            $payment = $this->postJournal($run, $entity, $bank, true, [
                [$wagesPayable, $net],
            ], 'PAY');

            $run->forceFill([
                'status' => PayRun::STATUS_PROCESSED,
                'ifrs_transaction_id' => $accrual->id,
                'ifrs_super_transaction_id' => $superJournal?->id,
                'ifrs_payment_transaction_id' => $payment->id,
                'processed_at' => now(),
                'processed_by' => auth()->id(),
            ])->save();

            Log::info('Pay run processed', [
                'pay_run_id' => $run->id,
                'gross' => $gross,
                'payg' => $payg,
                'super' => $super,
                'net' => $net,
            ]);

            return $run;
        });
    }

    /**
     * One journal per call: the main account on its side, the legs on
     * the other (the package's JournalEntry shape — same as the
     * dividend and settlement postings). Zero legs drop out.
     *
     * @param  list<array{0: Account, 1: float}>  $legs
     */
    protected function postJournal(PayRun $run, Entity $entity, Account $main, bool $credited, array $legs, string $suffix): JournalEntry
    {
        IfrsPosting::ensureReportingPeriod($run->payment_date, $entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($run->payment_date, $entity),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $entity->id,
            // Carried explicitly: a bank main account defaults the
            // currency, but a line-item bank needs it up front.
            'currency_id' => $entity->currency_id,
            'narration' => 'Payroll — '.$run->label(),
            'reference' => 'PAYROLL-'.$run->id.'-'.$suffix,
        ]);

        foreach ($legs as [$account, $amount]) {
            if ($amount <= 0) {
                continue;
            }

            // Persisted before addLineItem(): unsaved items share a
            // null id and the package silently drops all but the first.
            $line = LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $entity->id,
            ]);
            $journal->addLineItem($line);
        }

        $journal->post();

        return $journal;
    }

    /**
     * Resolve a seeded payroll ledger account by its configured code.
     */
    protected function account(Entity $entity, string $key): Account
    {
        $code = config("payroll.accounts.{$key}");

        $account = Account::where('entity_id', $entity->id)->where('code', $code)->first();

        if (! $account) {
            throw new \InvalidArgumentException(
                "The payroll {$key} account (code {$code}) does not exist for this entity."
            );
        }

        return $account;
    }

    /**
     * Like account(), but creates the account on first use so existing
     * installs pick up payroll additions without reseeding.
     */
    protected function accountOrCreate(Entity $entity, string $key, int|string $accountType, string $name): Account
    {
        $code = config("payroll.accounts.{$key}");

        return Account::firstOrCreate(
            ['entity_id' => $entity->id, 'code' => $code],
            [
                'account_type' => $accountType,
                'name' => $name,
                'currency_id' => $entity->currency_id,
            ],
        );
    }

    /**
     * Reverse a processed run: mirror the three journals back out and
     * return the run to draft (payslips kept for editing).
     */
    public function reverse(PayRun $run): PayRun
    {
        if (! $run->isProcessed()) {
            throw new \InvalidArgumentException('Only processed runs can be reversed.');
        }

        $entity = IfrsPosting::resolveEntity();
        $this->assertDatePostable($run->payment_date, $entity, 'payment date');

        return DB::transaction(function () use ($run) {
            foreach (['ifrs_payment_transaction_id', 'ifrs_super_transaction_id', 'ifrs_transaction_id'] as $column) {
                if ($run->{$column}) {
                    IfrsPosting::reverseTransaction(
                        (int) $run->{$column},
                        'Reversal of '.$run->label(),
                        'PAYROLL-'.$run->id.'-REV',
                        throw: true,
                    );
                }
            }

            $run->forceFill([
                'status' => PayRun::STATUS_DRAFT,
                'ifrs_transaction_id' => null,
                'ifrs_super_transaction_id' => null,
                'ifrs_payment_transaction_id' => null,
                'processed_at' => null,
                'processed_by' => null,
            ])->save();

            Log::info('Pay run reversed', ['pay_run_id' => $run->id]);

            return $run;
        });
    }

    /**
     * Refuse posting into a closed IFRS year or a locked app period —
     * the same guards the dividend/BAS flows apply.
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

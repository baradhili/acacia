<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayRun;
use App\Models\Payslip;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 */
class PayrollService
{
    public function __construct(protected PeriodLockService $locks) {}

    /**
     * PAYG withholding for a gross amount in the pay frequency, per
     * NAT 1004: convert to the weekly equivalent, y = ax - b rounded
     * to the nearest dollar, then scale the result back to the period
     * (rounded to the cent).
     */
    public function withholding(Employee $employee, float $gross, string $frequency): float
    {
        if ($gross <= 0 || ! $employee->withholdsPayg()) {
            return 0.0;
        }

        // No TFN provided → the ATO flat rate.
        if (empty($employee->tfn)) {
            return round($gross * (float) config('payroll.no_tfn_rate', 0.47), 2);
        }

        $scale = $employee->taxScale();

        $weekly = match ($frequency) {
            'weekly' => $gross,
            'fortnightly' => $gross / 2,
            'monthly' => $gross * 3 / 13,
            default => throw new \InvalidArgumentException("Unknown pay frequency {$frequency}."),
        };

        $y = $this->applyScale($scale, $weekly);
        if ($y <= 0) {
            return 0.0;
        }

        $perPeriod = match ($frequency) {
            'weekly' => $y,
            'fortnightly' => $y * 2,
            'monthly' => $y * 13 / 3,
        };

        return round($perPeriod, 2);
    }

    /**
     * y = ax - b (weekly earnings against the ATO coefficient bands),
     * rounded to the nearest dollar.
     */
    protected function applyScale(int $scale, float $weeklyEarnings): float
    {
        $bands = config("payroll.scale_{$scale}");

        if (! is_array($bands)) {
            throw new \InvalidArgumentException("Unknown PAYG withholding scale {$scale}.");
        }

        foreach ($bands as [$lessThan, $a, $b]) {
            if ($lessThan === null || $weeklyEarnings < $lessThan) {
                return round($weeklyEarnings * $a - $b);
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
     * Compute (not persist) a payslip's figures for an employee.
     *
     * @return array{hours: ?float, gross: float, payg_withheld: float, super: float, net_pay: float}
     */
    public function computePayslip(Employee $employee, PayRun $run, ?float $hours = null, ?float $grossOverride = null): array
    {
        $gross = $grossOverride ?? ($hours !== null && $employee->hourly_rate !== null
            ? round($hours * (float) $employee->hourly_rate, 2)
            : $this->defaultGross($employee, $run->frequency));

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

            // Seed a payslip for every active employee whose defaults
            // can be computed (salary staff); hourly staff get theirs
            // as hours are entered.
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

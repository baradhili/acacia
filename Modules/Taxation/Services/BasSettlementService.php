<?php

namespace Modules\Taxation\Services;

use App\Models\BillPayment;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
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
use Modules\Shares\Models\FrankingAccountEntry;
use Modules\Shares\Services\FrankingService;
use Modules\Taxation\Models\BasSettlement;

/**
 * Records BAS settlements: the ATO payment (or refund) that nets a tax
 * account pair and clears it — GST (Payable vs Receivable via the
 * seeded Vats), PAYG withholding (2210), or income tax payable (2240)
 * which PAYG instalments prepay — the single-liability types settled
 * with the same recipe (a debit balance on them is an overpayment,
 * netted back as the refund side).
 *
 * The unsettled position is the accounts' as-at balances — not
 * per-quarter movement — so one settlement catches up any number of
 * missed quarters, even across closed financial years (the year close
 * carries the balances and balanceAt() reads the whole ledger), and
 * claiming late (the sub-$10k deferral) is simply settling at a later
 * date. The BAS report itself stays movement-based; settlements are
 * the balance-side action. Because a clearing journal is dated the
 * bank date (a month after the quarter it covers), a position as at
 * any earlier date nets those journals in — settled stays settled
 * throughout the coverage-to-bank window, and before coverage the
 * position clamps to nothing-to-settle (a recorded settlement
 * covering a later date has already taken those balances); only
 * backdated postings made after the settlement resurface. Types the
 * accounts are shared between (PAYG instalment and income tax clear
 * the same 2240) net each other's clearings too.
 *
 * Income tax settlements (instalments or assessed tax) also drive the
 * franking account: paying the ATO credits it (TC), a refund debits it
 * (RF), dated the bank movement — the same payment-date rule
 * DividendService applies to FD entries. GST and PAYG withholding are
 * never the company's own income tax and never touch franking.
 *
 * Pay (X > Y):    Dr Payable X / Cr Receivable Y / Cr Bank X-Y
 * Refund (Y > X): Cr Receivable Y / Dr Payable X / Dr Bank Y-X
 * An exact offset nets to a zero bank leg; with nothing unsettled the
 * settlement refuses.
 */
class BasSettlementService
{
    public function __construct(protected PeriodLockService $locks) {}

    /**
     * The GST accounts the entity's Vats post to — the same resolution
     * TaxReportController::ledgerGst uses: the output Vat (G) posts to the
     * payable account, the input Vat (I) to the receivable account, and
     * legacy databases where purchases fall back to the output Vat
     * share one account (settled on its own, receivable null).
     *
     * @return array{payable: ?Account, receivable: ?Account}
     */
    public function gstAccounts(Entity $entity): array
    {
        $inputCode = config('subscriptions.purchase_gst_vat_code', 'I');

        $roleByAccount = [];
        $vats = DB::table('ifrs_vats')
            ->where('entity_id', $entity->id)
            ->whereNotNull('account_id')
            ->get(['code', 'account_id']);

        foreach ($vats as $vat) {
            $role = $vat->code === $inputCode ? 'receivable' : 'payable';
            $roleByAccount[$vat->account_id] = isset($roleByAccount[$vat->account_id]) && $roleByAccount[$vat->account_id] !== $role
                ? 'shared'
                : $role;
        }

        if ($vats->isNotEmpty()) {
            $purchaseVat = BillPayment::purchaseGstVat($entity);
            if ($purchaseVat && $purchaseVat->account_id !== null && $purchaseVat->code !== $inputCode) {
                $roleByAccount[$purchaseVat->account_id] = 'shared';
            }
        }

        $byRole = ['payable' => null, 'receivable' => null, 'shared' => null];
        foreach ($roleByAccount as $accountId => $role) {
            $account = Account::where('entity_id', $entity->id)->where('id', $accountId)->first();
            if ($account) {
                $byRole[$role] = $account;
            }
        }

        if ($byRole['shared'] !== null) {
            return ['payable' => $byRole['shared'], 'receivable' => null];
        }

        return ['payable' => $byRole['payable'], 'receivable' => $byRole['receivable']];
    }

    /**
     * The accounts a settlement type nets. GST resolves through the
     * entity's Vats (gstAccounts()); the single-liability types resolve
     * by seeded code from config — and play both roles, so a debit
     * balance (an overpayment) nets back as the receivable side.
     *
     * @return array{payable: ?Account, receivable: ?Account}
     */
    public function accountsFor(string $type, Entity $entity): array
    {
        if ($type === BasSettlement::TYPE_GST) {
            return $this->gstAccounts($entity);
        }

        if (! in_array($type, BasSettlement::TYPES)) {
            throw new \InvalidArgumentException("Unknown settlement type {$type}.");
        }

        $code = match ($type) {
            BasSettlement::TYPE_PAYG => config('australian.bas.payg_account_code', 2210),
            // Instalments prepay the assessed income tax liability, so
            // both settle the same account.
            BasSettlement::TYPE_PAYG_INSTALMENT, BasSettlement::TYPE_INCOME_TAX => config('australian.bas.income_tax_account_code', 2240),
        };

        $account = Account::where('entity_id', $entity->id)->where('code', $code)->first();

        return ['payable' => $account, 'receivable' => $account];
    }

    /**
     * The unsettled position at an as-at date for one settlement type:
     * the accounts' balances (balanceAt is debit-positive, so the
     * payable credit balance is negated), netted for settlements whose
     * coverage runs through the date. Everything never settled to
     * date, across quarters and closed years.
     *
     * @return array{payable: float, receivable: float, net: float}
     */
    public function position(?Carbon $asAt = null, string $type = BasSettlement::TYPE_GST): array
    {
        $entity = IfrsPosting::resolveEntity();

        return $this->positionFor($entity, $this->accountsFor($type, $entity), ($asAt ?? now())->copy()->endOfDay(), $type);
    }

    /**
     * Positions for every settlement type, keyed by type — the
     * settlement screen's overview card.
     *
     * @return array<string, array{payable: float, receivable: float, net: float}>
     */
    public function positions(?Carbon $asAt = null): array
    {
        $asAt = ($asAt ?? now())->copy()->endOfDay();
        $entity = IfrsPosting::resolveEntity();

        $positions = [];
        foreach (BasSettlement::TYPES as $type) {
            $positions[$type] = $this->positionFor($entity, $this->accountsFor($type, $entity), $asAt, $type);
        }

        return $positions;
    }

    /**
     * The prior financial year's component still inside a settlement
     * recorded as at $asAt: the type's position the day before the
     * as-at date's financial year began. One settlement is balance-
     * based, so recording it rolls this carry in with the current
     * year's quarters — the note that says so.
     *
     * Null when nothing carries over, or once anything of this type
     * has been settled since that FY began — a settlement's journal
     * clears prior-year and current-year balances together, and the
     * split is no longer derivable from the ledger.
     *
     * @return array{fy_end: int, net: float}|null
     */
    public function priorYearsCarry(Carbon $asAt, string $type = BasSettlement::TYPE_GST): ?array
    {
        $entity = IfrsPosting::resolveEntity();
        $year = ReportingPeriod::year($asAt, $entity);
        ['start' => $fyStart] = (new FiscalYearService)->bounds($entity, $year);

        $carried = $this->positionFor(
            $entity,
            $this->accountsFor($type, $entity),
            $fyStart->copy()->subDay()->endOfDay(),
            $type,
        )['net'];

        if (abs($carried) < 0.005) {
            return null;
        }

        $settledSince = BasSettlement::query()
            ->where('entity_id', $entity->id)
            ->where('type', $type)
            ->whereNull('reversed_at')
            ->whereDate('settled_at', '>=', $fyStart->toDateString())
            ->exists();

        return $settledSince ? null : ['fy_end' => $year, 'net' => $carried];
    }

    /**
     * @param  array{payable: ?Account, receivable: ?Account}  $accounts
     * @return array{payable: float, receivable: float, net: float}
     */
    protected function positionFor(Entity $entity, array $accounts, Carbon $asAt, ?string $type = null): array
    {
        // Settlements covering through $asAt but bank-dated after it:
        // their clearing journals are invisible to balanceAt($asAt), so
        // their legs are netted in here — otherwise a settled quarter
        // keeps showing as unsettled (the screen's default as-at is the
        // quarter end, the bank date lags it) and invites a second
        // payment of the same position.
        $settled = $type !== null
            ? $this->settledBeyondDate($entity, $type, $accounts, $asAt)
            : ['payable' => 0.0, 'receivable' => 0.0];

        $payable = $accounts['payable']
            ? max(0.0, -round(OpeningBalances::balanceAt($accounts['payable'], $entity, $asAt) + $settled['payable'], 2))
            : 0.0;
        $receivable = $accounts['receivable']
            ? max(0.0, round(OpeningBalances::balanceAt($accounts['receivable'], $entity, $asAt) + $settled['receivable'], 2))
            : 0.0;

        return ['payable' => $payable, 'receivable' => $receivable, 'net' => round($payable - $receivable, 2)];
    }

    /**
     * The clearing journals' own legs (signed debit-positive movement)
     * on the settlement accounts, for non-reversed settlements whose
     * bank date — the date the journal carries — still falls after
     * $asAt, throughout the whole coverage-to-bank window and before
     * it: before coverage the subtraction overshoots and the per-side
     * clamp leaves "nothing to settle", which is right — a recorded
     * settlement already covering a later date has taken those
     * balances with it. Types sharing the accounts (PAYG instalment
     * and income tax both clear 2240) net together, so a settlement
     * of the sibling type cannot be re-settled under this one. The
     * legs are the exact amounts cleared, unlike the settlement row's
     * whole-dollar labels, so a backdated posting made after the
     * settlement shows through as a genuine residual rather than
     * being swallowed.
     *
     * @param  array{payable: ?Account, receivable: ?Account}  $accounts
     * @return array{payable: float, receivable: float}
     */
    protected function settledBeyondDate(Entity $entity, string $type, array $accounts, Carbon $asAt): array
    {
        $accountIds = array_values(array_unique(array_filter([
            $accounts['payable']?->id,
            $accounts['receivable']?->id,
        ])));

        if ($accountIds === []) {
            return ['payable' => 0.0, 'receivable' => 0.0];
        }

        $transactionIds = BasSettlement::query()
            ->where('entity_id', $entity->id)
            ->whereIn('type', $this->typesSharingAccounts($entity, $type, $accountIds))
            ->whereNull('reversed_at')
            ->whereNotNull('ifrs_transaction_id')
            ->whereDate('settled_at', '>', $asAt->toDateString())
            ->pluck('ifrs_transaction_id');

        if ($transactionIds->isEmpty()) {
            return ['payable' => 0.0, 'receivable' => 0.0];
        }

        $movementByAccount = DB::table('ifrs_ledgers')
            ->whereIn('transaction_id', $transactionIds)
            ->whereIn('post_account', $accountIds)
            ->whereNull('deleted_at')
            ->groupBy('post_account')
            ->selectRaw("post_account,
                SUM(CASE WHEN entry_type = '".Balance::CREDIT."' THEN -amount ELSE amount END) as movement")
            ->pluck('movement', 'post_account');

        $movement = fn (?int $accountId): float => $accountId === null
            ? 0.0
            : round((float) ($movementByAccount[$accountId] ?? 0), 2);

        return [
            'payable' => $movement($accounts['payable']?->id),
            'receivable' => $movement($accounts['receivable']?->id),
        ];
    }

    /**
     * The settlement types whose accounts intersect the given ones —
     * normally just $type itself, plus the PAYG-instalment/income-tax
     * pair, which accountsFor() maps to the same 2240 liability: a
     * position on a shared account must net both siblings' clearings
     * or the second type could settle what the first already paid.
     *
     * @param  list<int>  $accountIds
     * @return list<string>
     */
    protected function typesSharingAccounts(Entity $entity, string $type, array $accountIds): array
    {
        $types = [$type];

        foreach (BasSettlement::TYPES as $other) {
            if ($other === $type) {
                continue;
            }

            $otherAccounts = $this->accountsFor($other, $entity);
            $otherIds = array_filter([
                $otherAccounts['payable']?->id,
                $otherAccounts['receivable']?->id,
            ]);

            if (array_intersect($accountIds, $otherIds) !== []) {
                $types[] = $other;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Record a settlement: net the type's account balances as at as_at,
     * post the clearing journal dated settled_at, and persist the
     * snapshot row — in one transaction. The bank date is typically in
     * the month after the covered quarter (BAS lodgement lag).
     *
     * Amounts lodge whole dollars the ATO-conservative way: owed TO
     * the ATO rounds down and owed BY the ATO rounds up, and the net
     * is those rounded labels subtracted — the arithmetic the BAS
     * form itself performs, matching the lodged payment to the cent.
     * The ATO carries nothing over, so the clearing journal still
     * clears the tax accounts at their exact ledger balances and a
     * second, sub-$2 rounding journal moves the difference between
     * the exact and rounded nets into GST Rounding — the cents never
     * linger on the tax accounts.
     *
     * @param  array{as_at: mixed, settled_at: mixed, type?: string, reference?: ?string, notes?: ?string}  $data
     */
    public function settle(array $data): BasSettlement
    {
        $entity = IfrsPosting::resolveEntity();
        $type = $data['type'] ?? BasSettlement::TYPE_GST;
        if (! in_array($type, BasSettlement::TYPES)) {
            throw new \InvalidArgumentException("Unknown settlement type {$type}.");
        }

        $label = BasSettlement::typeLabel($type);
        $asAt = Carbon::parse($data['as_at'])->startOfDay();
        $settledAt = Carbon::parse($data['settled_at'])->startOfDay();

        $this->assertDatePostable($settledAt, $entity, 'bank date');

        $accounts = $this->accountsFor($type, $entity);
        if (! $accounts['payable'] && ! $accounts['receivable']) {
            throw new \InvalidArgumentException('No accounts are configured for '.strtolower($label).' settlements — seed the chart of accounts.');
        }

        return DB::transaction(function () use ($entity, $type, $label, $accounts, $asAt, $settledAt, $data) {
            // The entity row lock PaygInstalmentService's accrue and
            // accrual-reverse also take, held to commit: the position
            // this settlement nets and posts cannot shift underneath a
            // concurrent accrual posting or accrual reversal — and a
            // reversal's coverage check cannot miss a settlement still
            // mid-post. All three paths serialise on this one lock.
            Entity::withoutGlobalScope(EntityScope::class)
                ->whereKey($entity->id)
                ->lockForUpdate()
                ->first();

            ['payable' => $payableRaw, 'receivable' => $receivableRaw, 'net' => $netRaw] = $this->positionFor($entity, $accounts, $asAt->copy()->endOfDay(), $type);

            if ($payableRaw < 0.005 && $receivableRaw < 0.005) {
                throw new \InvalidArgumentException("There is no unsettled {$label} as at {$asAt->format('d M Y')}.");
            }

            // Cents alone cannot lodge — checked on the RAW balances,
            // before ceil() could inflate a sub-dollar receivable into
            // a lodgable label.
            if ($payableRaw < 1 && $receivableRaw < 1) {
                throw new \InvalidArgumentException(
                    "Only cents remain unsettled {$label} as at {$asAt->format('d M Y')} — nothing whole-dollar to lodge."
                );
            }

            // The BAS labels: owed-to rounds down, owed-by rounds up,
            // and the payment is the rounded labels subtracted.
            $payable = floor($payableRaw);
            $receivable = ceil($receivableRaw);
            $net = round($payable - $receivable, 2);

            // The clearing journal follows the ledger's own net — the
            // rounded labels can flip its sign at a boundary, and the
            // journal's shape must stay coherent with the exact
            // balances it clears. The rounding journal then moves the
            // bank to the rounded figure, and the record documents
            // the lodged labels and their net.
            $journalDirection = $netRaw >= 0 ? BasSettlement::DIRECTION_PAY : BasSettlement::DIRECTION_REFUND;
            $direction = $net >= 0 ? BasSettlement::DIRECTION_PAY : BasSettlement::DIRECTION_REFUND;

            // The clearing journal at the ledger's exact balances —
            // the tax accounts clear in full, never carrying cents.
            $journal = $this->postSettlementJournal(
                $entity,
                $accounts,
                $payableRaw,
                $receivableRaw,
                $netRaw,
                $journalDirection,
                $settledAt,
                $asAt,
                $type,
            );

            // The rounding adjustment when the rounded net differs
            // from the exact one: the bank moves |$net| across both
            // journals combined.
            $rounding = round(abs($netRaw - $net), 2);
            $roundingJournal = $rounding >= 0.005
                ? $this->postRoundingJournal($entity, $rounding, $direction, $settledAt, $asAt, $type)
                : null;

            $settlement = BasSettlement::create([
                'entity_id' => $entity->id,
                'type' => $type,
                'as_at' => $asAt->toDateString(),
                'settled_at' => $settledAt->toDateString(),
                'gst_payable' => $payable,
                'gst_receivable' => $receivable,
                'net_amount' => $net,
                'bank_amount' => abs($net),
                'direction' => $direction,
                'ifrs_transaction_id' => $journal->id,
                'ifrs_rounding_transaction_id' => $roundingJournal?->id,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if (in_array($type, BasSettlement::INCOME_TAX_TYPES, true) && abs($net) >= 0.005
                && class_exists(FrankingAccountEntry::class)) {
                $this->recordFrankingEntry($settlement, $journal, $entity);
            }

            Log::info('BAS settlement posted', [
                'settlement_id' => $settlement->id,
                'type' => $type,
                'as_at' => $asAt->toDateString(),
                'payable' => $payable,
                'receivable' => $receivable,
                'net' => $net,
                'transaction_id' => $journal->id,
            ]);

            return $settlement;
        });
    }

    /**
     * Mirror a settlement's journals back out (a recorded mistake) and
     * mark the settlement reversed, restoring the tax balances — both
     * the clearing journal and the rounding adjustment when one was
     * posted. The reversals keep the original transaction dates, so
     * the same date/period guards as posting apply first — a period
     * locked since the settlement was recorded refuses with a clear
     * error — and the ledger reversals and the settlement state commit
     * or roll back together.
     */
    public function reverse(BasSettlement $settlement): BasSettlement
    {
        if ($settlement->isReversed()) {
            throw new \InvalidArgumentException('This settlement has already been reversed.');
        }

        if (! $settlement->ifrs_transaction_id) {
            throw new \InvalidArgumentException('This settlement has no posted journal to reverse.');
        }

        $this->assertDatePostable($settlement->settled_at, $settlement->entity, 'bank date');

        return DB::transaction(function () use ($settlement) {
            $reversalId = IfrsPosting::reverseTransaction(
                $settlement->ifrs_transaction_id,
                'Reversal of BAS settlement — '.$settlement->label(),
                'BAS-SETT-'.static::typeCode($settlement->type).'-'.$settlement->as_at->format('Ymd').'-REV',
                throw: true,
            );

            if ($settlement->ifrs_rounding_transaction_id) {
                IfrsPosting::reverseTransaction(
                    (int) $settlement->ifrs_rounding_transaction_id,
                    'Reversal of BAS settlement rounding — '.$settlement->label(),
                    'BAS-SETT-'.static::typeCode($settlement->type).'-'.$settlement->as_at->format('Ymd').'-ROUND-REV',
                    throw: true,
                );
            }

            if (class_exists(FrankingAccountEntry::class)) {
                $this->reverseFrankingEntry($settlement, $reversalId);
            }

            $settlement->forceFill([
                'reversal_transaction_id' => $reversalId,
                'reversed_at' => now(),
            ])->save();

            Log::info('BAS settlement reversed', [
                'settlement_id' => $settlement->id,
                'reversal_id' => $reversalId,
            ]);

            return $settlement;
        });
    }

    /**
     * The franking entry an income tax settlement drives: paying the
     * ATO credits the account (TC), a refund debits it (RF) — dated the
     * bank movement, like DividendService dates FD entries at payment.
     * The amount is the settlement's bank leg: an exact offset pays and
     * refunds nothing, so it posts no entry.
     */
    protected function recordFrankingEntry(BasSettlement $settlement, JournalEntry $journal, Entity $entity)
    {
        $pay = $settlement->direction === BasSettlement::DIRECTION_PAY;

        return FrankingAccountEntry::create([
            'entity_id' => $entity->id,
            'financial_year' => FrankingService::financialYearFor($settlement->settled_at, $entity),
            'entry_date' => $settlement->settled_at->toDateString(),
            'entry_type' => $pay ? FrankingAccountEntry::TYPE_TAX_PAYMENT : FrankingAccountEntry::TYPE_REFUND_RECEIVED,
            'reference' => 'BAS-SETT-'.$settlement->id,
            'description' => ucfirst(BasSettlement::typeLabel($settlement->type)).($pay ? ' paid to ' : ' refund to ')
                .$settlement->as_at->format('d M Y'),
            'credit_amount' => $pay ? $settlement->bank_amount : 0,
            'debit_amount' => $pay ? 0 : $settlement->bank_amount,
            'is_estimated' => false,
            'ifrs_transaction_id' => $journal->id,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Mirror a settlement's franking entry back out — same type,
     * opposite side, same date — so the notional balance reads as if
     * the mistaken settlement never happened, the same way the ledger
     * reversal keeps the original journal and its mirror. Settlements
     * that drove no franking (GST, PAYG withholding, an exact offset)
     * find no entry and post none.
     */
    protected function reverseFrankingEntry(BasSettlement $settlement, int|string $reversalId): void
    {
        $entry = FrankingAccountEntry::query()
            ->where('entity_id', $settlement->entity_id)
            ->where('ifrs_transaction_id', $settlement->ifrs_transaction_id)
            ->first();

        if (! $entry) {
            return;
        }

        FrankingAccountEntry::create([
            'entity_id' => $entry->entity_id,
            'financial_year' => $entry->financial_year,
            'entry_date' => $entry->entry_date->toDateString(),
            'entry_type' => $entry->entry_type,
            'reference' => 'BAS-SETT-'.$settlement->id.'-REV',
            'description' => 'Reversal — '.lcfirst((string) $entry->description),
            'credit_amount' => $entry->debit_amount,
            'debit_amount' => $entry->credit_amount,
            'is_estimated' => $entry->is_estimated,
            'ifrs_transaction_id' => $reversalId,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Completed BAS quarter ends for the form's quick picks (most
     * recent last): consecutive three-month blocks of the FY from
     * entity.year_start, same arithmetic as the BAS report. Spans the
     * previous FY too, so a catch-up settlement can pick any recent
     * quarter end.
     *
     * @return list<array{end: Carbon, label: string}>
     */
    public function quarterEnds(Entity $entity, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $service = new FiscalYearService;
        $quarters = [];

        foreach ([ReportingPeriod::year($asOf, $entity) - 1, ReportingPeriod::year($asOf, $entity)] as $fy) {
            ['start' => $start] = $service->bounds($entity, $fy);

            foreach ([0, 1, 2, 3] as $i) {
                $end = $start->copy()->addMonths($i * 3 + 2)->endOfMonth();
                if ($end->copy()->endOfDay()->lessThan($asOf)) {
                    $quarters[] = [
                        'end' => $end->startOfDay(),
                        'label' => sprintf('Q%d FY%d (ended %s)', $i + 1, $fy, $end->format('d M Y')),
                    ];
                }
            }
        }

        return $quarters;
    }

    /**
     * Short code for journal references (GST/PAYG/PAYGI/TAX).
     */
    public static function typeCode(string $type): string
    {
        return match ($type) {
            BasSettlement::TYPE_PAYG => 'PAYG',
            BasSettlement::TYPE_PAYG_INSTALMENT => 'PAYGI',
            BasSettlement::TYPE_INCOME_TAX => 'TAX',
            default => 'GST',
        };
    }

    /**
     * Post the clearing journal — the DividendService::postJournal
     * recipe with two lines: the main account takes one side alone
     * (pay: Dr the payable; refund: Cr the receivable — the same
     * account for the single-liability types) and the remaining
     * clearing/bank legs take the other.
     *
     * @param  array{payable: ?Account, receivable: ?Account}  $accounts
     */
    protected function postSettlementJournal(
        Entity $entity,
        array $accounts,
        float $payable,
        float $receivable,
        float $net,
        string $direction,
        Carbon $settledAt,
        Carbon $asAt,
        string $type = BasSettlement::TYPE_GST,
    ): JournalEntry {
        $bank = Account::where('entity_id', $entity->id)
            ->where('code', config('australian.bas.bank_account_code', 320))
            ->first();
        if (! $bank) {
            throw new \InvalidArgumentException('The operating bank account is not configured.');
        }

        [$main, $credited] = $direction === BasSettlement::DIRECTION_PAY
            ? [$accounts['payable'], false]
            : [$accounts['receivable'], true];

        // The opposite-side legs: clear the other GST account and move
        // the net amount to/from the bank. Zero legs drop out (e.g. an
        // exact offset posts no bank movement).
        $legs = [];
        if ($direction === BasSettlement::DIRECTION_PAY) {
            if ($receivable > 0 && $accounts['receivable']) {
                $legs[] = [$accounts['receivable'], $receivable];
            }
            if ($net > 0) {
                $legs[] = [$bank, $net];
            }
        } else {
            if ($payable > 0 && $accounts['payable']) {
                $legs[] = [$accounts['payable'], $payable];
            }
            $legs[] = [$bank, abs($net)];
        }

        IfrsPosting::ensureReportingPeriod($settledAt, $entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($settledAt, $entity),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $entity->id,
            // Bank can be a line item; without an explicit currency the
            // package defaults from the MAIN account only and the bank
            // line fails the single-currency check at addLineItem().
            'currency_id' => $entity->currency_id,
            'narration' => 'BAS settlement — '.BasSettlement::typeLabel($type).' to '.$asAt->format('d M Y'),
            'reference' => 'BAS-SETT-'.static::typeCode($type).'-'.$asAt->format('Ymd'),
        ]);

        foreach ($legs as [$account, $amount]) {
            // Persisted before addLineItem(): unsaved items share a null
            // id and the package silently drops all but the first.
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
     * The rounding adjustment beside the clearing journal: the tax
     * accounts cleared at their exact balances and the clearing
     * journal moved the bank at that exact net, but the bank must
     * move the whole-dollar BAS net — which under the conservative
     * pair (owed-to down, owed-by up) is always at most the exact
     * one. The sub-$2 difference therefore always tops the bank back
     * up to the rounded figure and credits GST Rounding: Dr Bank /
     * Cr Rounding, both directions.
     */
    protected function postRoundingJournal(
        Entity $entity,
        float $rounding,
        string $direction,
        Carbon $settledAt,
        Carbon $asAt,
        string $type,
    ): JournalEntry {
        $bank = Account::where('entity_id', $entity->id)
            ->where('code', config('australian.bas.bank_account_code', 320))
            ->first();
        if (! $bank) {
            throw new \InvalidArgumentException('The operating bank account is not configured.');
        }

        $roundingAccount = $this->ensureRoundingAccount($entity);

        IfrsPosting::ensureReportingPeriod($settledAt, $entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($settledAt, $entity),
            'account_id' => $bank->id,
            'credited' => false,
            'entity_id' => $entity->id,
            'currency_id' => $entity->currency_id,
            'narration' => 'BAS settlement rounding — '.BasSettlement::typeLabel($type).' to '.$asAt->format('d M Y'),
            'reference' => 'BAS-SETT-'.static::typeCode($type).'-'.$asAt->format('Ymd').'-ROUND',
        ]);

        $line = LineItem::create([
            'account_id' => $roundingAccount->id,
            'amount' => $rounding,
            'quantity' => 1,
            'entity_id' => $entity->id,
        ]);
        $journal->addLineItem($line);

        $journal->post();

        return $journal;
    }

    /**
     * The GST Rounding account (4530, non-operating revenue): seeded
     * on fresh installs, lazily created for existing ones.
     */
    protected function ensureRoundingAccount(Entity $entity): Account
    {
        return Account::firstOrCreate(
            ['entity_id' => $entity->id, 'code' => config('australian.bas.rounding_account_code', 4530)],
            [
                'account_type' => Account::NON_OPERATING_REVENUE,
                'name' => 'GST Rounding',
                'currency_id' => $entity->currency_id,
            ],
        );
    }

    /**
     * Refuse posting into a closed IFRS year or a locked app period —
     * the same guards DividendService/PrepaymentService apply.
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

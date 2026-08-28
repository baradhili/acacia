<?php

namespace App\Services;

use App\Models\CompanyShareholder;
use App\Models\DividendDeclaration;
use App\Models\ShareClass;
use App\Models\Shareholding;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shareholding transaction ledger management. Holdings as at any date are
 * the sum of active transactions dated on or before it — the books-close
 * entitlement rule for dividends. company_shareholders.shares_held /
 * share_class stay as a display cache synced after every change; the
 * ledger is authoritative.
 */
class ShareholdingService
{
    /**
     * Signed shares held by a shareholder (optionally in one class) as at
     * $asOf (default: today). Future-dated and cancelled transactions are
     * excluded.
     */
    public static function totalShares(int $shareholderId, ?int $shareClassId = null, $asOf = null): int
    {
        $asOf = Carbon::parse($asOf ?? today())->endOfDay();

        return (int) Shareholding::query()
            ->where('company_shareholder_id', $shareholderId)
            ->where('status', Shareholding::STATUS_ACTIVE)
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->when($shareClassId, fn ($q) => $q->where('share_class_id', $shareClassId))
            ->sum('quantity');
    }

    /**
     * Current holdings of a shareholder grouped by share class.
     *
     * @return array<int, array{class: ShareClass, quantity: int}>
     */
    public static function holdingsByClass(CompanyShareholder $shareholder, $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? today())->endOfDay();

        $rows = Shareholding::query()
            ->selectRaw('share_class_id, SUM(quantity) as total')
            ->where('company_shareholder_id', $shareholder->id)
            ->where('status', Shareholding::STATUS_ACTIVE)
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->groupBy('share_class_id')
            ->havingRaw('SUM(quantity) != 0')
            ->pluck('total', 'share_class_id');

        $holdings = [];
        foreach ($rows as $classId => $total) {
            $class = ShareClass::find($classId);
            if ($class) {
                $holdings[] = ['class' => $class, 'quantity' => (int) $total];
            }
        }

        return $holdings;
    }

    /**
     * Total issued shares of a class across all shareholders as at $asOf —
     * the denominator for the shareholder register's percentage column.
     */
    public static function issuedShares(int $shareClassId, $asOf = null): int
    {
        $asOf = Carbon::parse($asOf ?? today())->endOfDay();

        return (int) Shareholding::query()
            ->where('share_class_id', $shareClassId)
            ->where('status', Shareholding::STATUS_ACTIVE)
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->sum('quantity');
    }

    /**
     * Record a shareholding transaction after validating it can never take
     * the holding negative (the balance is lowest at the transaction date,
     * so checking there covers every later date).
     */
    public static function record(CompanyShareholder $shareholder, array $attributes): Shareholding
    {
        $attributes['quantity'] = (int) ($attributes['quantity'] ?? 0);
        if ($attributes['quantity'] === 0) {
            throw new \InvalidArgumentException('Quantity must not be zero.');
        }
        if (!in_array($attributes['transaction_type'] ?? '', array_keys(Shareholding::types()), true)) {
            throw new \InvalidArgumentException('Invalid transaction type.');
        }

        $holding = new Shareholding($attributes);
        $holding->company_shareholder_id = $shareholder->id;

        $existing = self::totalShares(
            $shareholder->id,
            $holding->share_class_id,
            $holding->transaction_date
        );
        if ($existing + $holding->quantity < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Transaction would take the holding negative: %d held as at %s, quantity %d.',
                $existing,
                $holding->transaction_date->toDateString(),
                $holding->quantity,
            ));
        }

        $holding->created_by = auth()->id();
        $holding->status = Shareholding::STATUS_ACTIVE;
        $holding->save();
        self::syncSharesHeld($shareholder);

        return $holding;
    }

    /**
     * Cancel (soft) a holding. Refused while an approved or completed
     * declaration's books-close date falls on/after the transaction — the
     * eligibility those distributions were calculated from must stay
     * reproducible.
     */
    public static function cancel(Shareholding $holding): void
    {
        self::assertNotProtected($holding);

        $holding->update(['status' => Shareholding::STATUS_CANCELLED]);
        self::syncSharesHeld($holding->shareholder);
    }

    /**
     * Update an editable attribute set of a holding, with the same
     * negative-balance and declaration guards as record()/cancel().
     */
    public static function update(Shareholding $holding, array $attributes): Shareholding
    {
        self::assertNotProtected($holding);

        $holding->fill($attributes);
        if ($holding->quantity === 0) {
            throw new \InvalidArgumentException('Quantity must not be zero.');
        }

        $existing = Shareholding::query()
            ->where('company_shareholder_id', $holding->company_shareholder_id)
            ->where('share_class_id', $holding->share_class_id)
            ->where('status', Shareholding::STATUS_ACTIVE)
            ->whereDate('transaction_date', '<=', $holding->transaction_date->toDateString())
            ->where('id', '!=', $holding->id)
            ->sum('quantity');
        if ($existing + $holding->quantity < 0) {
            throw new \InvalidArgumentException(
                "Update would take the holding negative ({$existing} held as at {$holding->transaction_date->toDateString()})."
            );
        }

        $holding->save();
        self::syncSharesHeld($holding->shareholder);

        return $holding;
    }

    /**
     * Does an approved/completed declaration depend on this holding for
     * its books-close eligibility? Only the shareholder's own lines count.
     */
    public static function protectedByDeclarations(Shareholding $holding): bool
    {
        return DividendDeclaration::query()
            ->whereIn('status', [DividendDeclaration::STATUS_APPROVED, DividendDeclaration::STATUS_COMPLETED])
            ->where('share_class_id', $holding->share_class_id)
            ->whereDate('books_close_date', '>=', $holding->transaction_date->toDateString())
            ->whereHas('distributions', fn ($q) => $q->where('company_shareholder_id', $holding->company_shareholder_id))
            ->exists();
    }

    protected static function assertNotProtected(Shareholding $holding): void
    {
        if (self::protectedByDeclarations($holding)) {
            throw new \InvalidArgumentException(
                'This holding is relied on by an approved dividend declaration and can no longer be changed.'
            );
        }
    }

    /**
     * Refresh the display cache on the shareholder row: total shares across
     * classes and the class of their most recent transaction.
     */
    public static function syncSharesHeld(CompanyShareholder $shareholder): void
    {
        $shareholder->refresh();
        $latest = $shareholder->shareholdings()
            ->where('status', Shareholding::STATUS_ACTIVE)
            ->first();

        $shareholder->forceFill([
            'shares_held' => self::totalShares($shareholder->id),
            'share_class' => $latest?->shareClass?->code ?? $shareholder->share_class,
        ])->save();
    }

    /**
     * Backfill opening issue transactions for shareholders whose Phase 1
     * shares_held is not yet represented in the ledger (used by tests and
     * any future repair tooling; the migration covers existing rows).
     */
    public static function backfillOpenings(CompanyShareholder $shareholder, ShareClass $class): void
    {
        $existing = Shareholding::where('company_shareholder_id', $shareholder->id)->exists();
        if ($existing || $shareholder->shares_held <= 0) {
            return;
        }

        DB::table('shareholdings')->insert([
            'company_shareholder_id' => $shareholder->id,
            'share_class_id' => $class->id,
            'transaction_type' => Shareholding::TYPE_ISSUE,
            'transaction_date' => $shareholder->created_at?->toDateString() ?? today()->toDateString(),
            'quantity' => $shareholder->shares_held,
            'reference' => 'OPENING',
            'status' => Shareholding::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::syncSharesHeld($shareholder);
    }
}

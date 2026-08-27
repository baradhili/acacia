<?php

namespace App\Rules;

use App\Services\PeriodLockService;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects dates that cannot be posted to the ledger: either the app's
 * FiscalPeriod covering the date is locked, or the financial year has
 * been CLOSED by the year-end close — the IFRS package would then throw
 * (or, for best-effort postings, silently never reach the ledger).
 */
class NotInClosedPeriod implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return; // malformed values are the `date` rule's to report
        }

        $lockService = app(PeriodLockService::class);

        if ($lockService->isDateBlocked($date)) {
            $fail($lockService->dateBlockedMessage($date)
                ?? "The {$attribute} falls in a closed financial year.");
        }
    }
}

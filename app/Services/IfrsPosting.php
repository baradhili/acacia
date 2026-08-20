<?php

namespace App\Services;

use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Support\Facades\Auth;

/**
 * Shared plumbing for posting payments to the IFRS ledger.
 * Payment::postToIFRS() and BillPayment::postToIFRS() both resolve their
 * entity and reporting period through this service, so the posting
 * prerequisites are hardened in one place.
 */
class IfrsPosting
{
    /**
     * Resolve the IFRS entity to post against: the authed user's entity if
     * available, otherwise the first entity. Returns null if none exist.
     * Works in queued jobs where no user is authed (falls back to a query).
     */
    public static function resolveEntity(): ?Entity
    {
        try {
            $user = Auth::user();
            if ($user && isset($user->entity) && $user->entity) {
                return $user->entity;
            }
        } catch (\Throwable $e) {
            // Auth not available (e.g. queued job) — fall through to query.
        }

        return Entity::orderBy('id')->first();
    }

    /**
     * Ensure an OPEN reporting period exists for the fiscal year $date falls
     * in (mirrors ReportController::getReportingPeriod() / IFRSSeeder).
     * Transaction::save() throws MissingReportingPeriod when the period row
     * is absent, and the seeder only creates the current FY — a payment dated
     * in any other fiscal year would fail forever without this. Idempotent:
     * firstOrCreate skips periods that already exist.
     */
    public static function ensureReportingPeriod($date, Entity $entity): ReportingPeriod
    {
        $year = ReportingPeriod::year(Carbon::parse($date), $entity);

        return ReportingPeriod::firstOrCreate(
            ['entity_id' => $entity->id, 'calendar_year' => $year],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );
    }
}

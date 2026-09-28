<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Support\Facades\Auth;

/**
 * The reporting context every report controller shares: the IFRS
 * entity of the authenticated user, and the reporting periods the
 * IFRS statement helpers need to exist.
 */
trait ResolvesReportingContext
{
    /**
     * Ensure reporting periods exist for the financial year containing
     * $date AND the FY before it. The package derives FY boundaries from
     * the entity's year_start (July for Australia: 1 Jul – 30 Jun), but
     * Account::openingBalance($year) internally resolves its period via
     * "{year}-01-01" — which with year_start = 7 lands in the PREVIOUS
     * financial year — so the package's statement helpers also need the
     * prior-FY period row to exist. Mirrors IFRSSeeder's period shape.
     */
    protected function getReportingPeriod($date = null): ReportingPeriod
    {
        $date = Carbon::parse($date ?? now());
        $entity = $this->ifrsEntity();
        $year = ReportingPeriod::year($date, $entity);

        $period = ReportingPeriod::firstOrCreate(
            ['entity_id' => $entity->id, 'calendar_year' => $year],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );

        if ($year - 1 >= 1) {
            ReportingPeriod::firstOrCreate(
                ['entity_id' => $entity->id, 'calendar_year' => $year - 1],
                ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
            );
        }

        return $period;
    }

    /**
     * The IFRS entity of the authenticated user. Report consumers must
     * own their entity: IfrsPosting::resolveEntity()'s posting fallback
     * (first entity, lent to the user in memory) exists for unauthenticated
     * jobs — borrowing it here would silently serve an entity-less user
     * someone else's books, so refuse with 404 instead (also covers a
     * fresh install with no entities at all).
     */
    protected function ifrsEntity(): Entity
    {
        $entity = Auth::user()?->entity;

        abort_unless((bool) $entity, 404, 'No IFRS entity assigned to your account.');

        return $entity;
    }
}

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
        // No entity means there is nothing to report on — ReportingPeriod::
        // year()/firstOrCreate below need one. 404 (not 500) via the
        // framework's abort path.
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');
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
     * The IFRS entity of the authenticated user (falling back to the first
     * entity) — most package helpers need it explicitly in background
     * contexts where no user is logged in.
     */
    protected function ifrsEntity(): ?Entity
    {
        return Auth::user()?->entity ?? Entity::first();
    }
}

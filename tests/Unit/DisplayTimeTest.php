<?php

namespace Tests\Unit;

use App\Support\DisplayTime;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Stored-UTC timestamps rendered in the configured display timezone
 * with the zone's own abbreviation attached (AEST/AEDT/AWST/UTC), so
 * a screen time never has to be guessed at: the label says which
 * zone it is in, DST included — the abbreviation comes from the
 * rendered moment, not from config, so a DST-shifting zone labels
 * itself correctly year-round.
 */
class DisplayTimeTest extends TestCase
{
    public function test_renders_in_the_display_timezone_with_its_abbreviation(): void
    {
        // Pin the zone the expectations assume — the host env's
        // APP_DISPLAY_TIMEZONE must not decide this test.
        config(['app.display_timezone' => 'Australia/Sydney']);

        // Winter: Sydney runs AEST (UTC+10).
        $this->assertSame(
            '01 Jul 2026 14:00 AEST',
            DisplayTime::format(Carbon::parse('2026-07-01 04:00:00')),
        );

        // Summer: same config, AEDT (UTC+11).
        $this->assertSame(
            '01 Dec 2026 15:00 AEDT',
            DisplayTime::format(Carbon::parse('2026-12-01 04:00:00')),
        );
    }

    public function test_perth_stays_on_awst_across_the_year(): void
    {
        config(['app.display_timezone' => 'Australia/Perth']);

        $this->assertSame(
            '01 Jul 2026 12:00 AWST',
            DisplayTime::format(Carbon::parse('2026-07-01 04:00:00')),
        );
        $this->assertSame(
            '01 Dec 2026 12:00 AWST',
            DisplayTime::format(Carbon::parse('2026-12-01 04:00:00')),
        );
    }

    public function test_a_utc_display_config_labels_itself_utc(): void
    {
        config(['app.display_timezone' => 'UTC']);

        $this->assertSame(
            '01 Jul 2026 04:00 UTC',
            DisplayTime::format(Carbon::parse('2026-07-01 04:00:00')),
        );
    }

    public function test_an_invalid_zone_falls_back_to_utc_rather_than_failing_the_screen(): void
    {
        config(['app.display_timezone' => 'Mars/Olympus_Mons']);

        $this->assertSame(
            '01 Jul 2026 04:00 UTC',
            DisplayTime::format(Carbon::parse('2026-07-01 04:00:00')),
        );
    }

    public function test_null_passes_through_and_the_source_value_is_not_mutated(): void
    {
        $this->assertNull(DisplayTime::format(null));

        $time = Carbon::parse('2026-07-01 04:00:00');
        DisplayTime::format($time);

        $this->assertSame('2026-07-01 04:00:00', $time->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $time->timezoneName);
    }
}

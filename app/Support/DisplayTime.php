<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * UI rendering of stored timestamps, always labelled with the zone
 * (AEST/AEDT/AWST/UTC/GMT+11/...) so no reader has to guess which
 * zone a time is in.
 *
 * Timestamps are always written as UTC — APP_TIMEZONE stays UTC,
 * that is the storage invariant — and conversion happens only at
 * render time. Which zone a reader actually sees: their own
 * browser's, applied client-side by resources/js/display-time.js to
 * the `<x-display-time>` element's markup. The configured
 * app.display_timezone (APP_DISPLAY_TIMEZONE, Australia/Sydney by
 * default) renders the server-side text those elements start with —
 * the no-JS fallback and the first paint — with its abbreviation
 * taken from the rendered moment, so a DST-shifting zone shows AEST
 * over winter and AEDT over summer with no config change. An invalid
 * configured zone falls back to UTC rather than failing the screen.
 * Null passes through (the component renders the caller's
 * "never"/"-").
 */
class DisplayTime
{
    public static function format(?CarbonInterface $time, string $format = 'd M Y H:i'): ?string
    {
        if ($time === null) {
            return null;
        }

        // copy() first: model datetimes are mutable Carbon, and
        // timezone() would otherwise shift the model's own value.
        return $time->copy()
            ->timezone(self::zone())
            ->format($format.' T');
    }

    /**
     * The configured display zone, falling back to UTC when the
     * configured value is not a zone PHP knows — a typo in .env must
     * not take the screen down, and UTC is labelled UTC either way.
     */
    protected static function zone(): \DateTimeZone
    {
        $configured = (string) config('app.display_timezone', 'Australia/Sydney');

        try {
            return new \DateTimeZone($configured);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}

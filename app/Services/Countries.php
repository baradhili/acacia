<?php

namespace App\Services;

/**
 * Country list for the contact-form dropdowns, with the configured
 * "pinned" countries (config/countries.php, PINNED_COUNTRIES) floated to
 * the top. Records store country names, not ISO codes.
 */
class Countries
{
    /**
     * Pinned countries in configured order. Only names present in the
     * list are honoured, so a typo in the env var cannot inject a bogus
     * dropdown entry.
     */
    public static function pinned(): array
    {
        $pinned = array_map('trim', explode(',', (string) config('countries.pinned')));

        return array_values(array_filter(
            $pinned,
            fn ($country) => $country !== '' && self::exists($country)
        ));
    }

    /**
     * All country names, alphabetically.
     */
    public static function all(): array
    {
        return array_values(config('countries.list', []));
    }

    /**
     * Dropdown structure: [$pinned, $rest] — the pinned countries first
     * (in configured order), then everything else alphabetically. Pinned
     * countries are excluded from the rest so they appear only once.
     */
    public static function dropdown(): array
    {
        $pinned = self::pinned();

        return [$pinned, array_values(array_diff(self::all(), $pinned))];
    }

    /**
     * Is the given name a known country?
     */
    public static function exists(?string $country): bool
    {
        return in_array($country, self::all(), true);
    }
}

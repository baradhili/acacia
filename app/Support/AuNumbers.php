<?php

namespace App\Support;

/**
 * Australian business/tax number formatting and normalisation — the
 * way the ATO/ASIC write them: ABN 11 digits grouped 2-3-3-3, ACN 9
 * digits grouped 3-3-3, TFN 9 digits as 3-3-3 (8 digits as 3-5).
 *
 * Entry accepts the same spacing (values are normalised to digits on
 * the way into the models); formatting only regroups values whose
 * digit count matches the expected shape, so partial or foreign
 * values pass through untouched rather than being mangled.
 */
class AuNumbers
{
    public static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return $digits === '' ? null : $digits;
    }

    public static function abn(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        if (strlen($digits) !== 11) {
            return $value;
        }

        return substr($digits, 0, 2).' '.substr($digits, 2, 3).' '.substr($digits, 5, 3).' '.substr($digits, 8, 3);
    }

    public static function acn(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        if (strlen($digits) !== 9) {
            return $value;
        }

        return substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6, 3);
    }

    public static function tfn(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        return match (strlen($digits)) {
            9 => substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6, 3),
            8 => substr($digits, 0, 3).' '.substr($digits, 3, 5),
            default => $value,
        };
    }
}

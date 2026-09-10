<?php

namespace App\Rules;

use App\Support\AuNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an ABN / ACN / TFN, accepting the usual spaced entry:
 * digits (and any spacing) are stripped before the digit-count check,
 * so "12 345 678 901" validates as an ABN exactly like "12345678901".
 * Models normalise the value to bare digits on save.
 */
class AuNumber implements ValidationRule
{
    public function __construct(public string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $length = strlen((string) AuNumbers::digits((string) $value));

        $expected = match ($this->kind) {
            'abn' => [11],
            'acn' => [9],
            'tfn' => [8, 9],
            default => throw new \InvalidArgumentException("Unknown ATO number kind {$this->kind}."),
        };

        if (! in_array($length, $expected, true)) {
            $fail("The {$attribute} must be a valid ".strtoupper($this->kind)
                .' ('.implode(' or ', $expected).' digits — spaces are fine).');
        }
    }
}

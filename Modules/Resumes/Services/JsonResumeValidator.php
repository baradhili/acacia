<?php

namespace Modules\Resumes\Services;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator as JsonSchemaLibraryValidator;

/**
 * Validates an uploaded resume array against the vendored (modified)
 * JSON Resume schema. Ported from baradhili/laravel-resume: same
 * justinrainbow/json-schema library and the same error formatting,
 * but the schema ships with the module and is never fetched at
 * runtime.
 */
class JsonResumeValidator
{
    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    public static function validate(array $jsonData): array
    {
        $schemaPath = config('resumes.schema');

        $jsonData = self::normalize($jsonData);

        if (! is_file($schemaPath)) {
            return [
                'valid' => false,
                'errors' => ['Resume schema is not installed. Run: php artisan resumes:fetch-schema --force'],
            ];
        }

        // Load schema as OBJECT (required by library)
        $schema = json_decode(file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);

        // Convert data to OBJECT (required for by-reference passing in PHP 8+)
        $dataToValidate = json_decode(json_encode($jsonData), false, 512, JSON_THROW_ON_ERROR);

        $validator = new JsonSchemaLibraryValidator;

        // Pass variables (not expressions) to satisfy PHP 8+ reference rules
        $validator->validate(
            $dataToValidate,
            $schema,
            Constraint::CHECK_MODE_APPLY_DEFAULTS
        );

        if ($validator->isValid()) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            if (! is_array($error)) {
                continue;
            }

            $property = $error['property'] ?? $error['pointer'] ?? 'root';
            $message = $error['message'] ?? 'Validation failed';

            $constraintRaw = $error['constraint'] ?? null;
            $constraintName = null;
            $constraintParams = null;

            if (is_array($constraintRaw)) {
                $constraintName = $constraintRaw['name'] ?? null;
                $constraintParams = $constraintRaw['params'] ?? null;
            } elseif (is_object($constraintRaw)) {
                $constraintName = $constraintRaw->name ?? null;
                $constraintParams = isset($constraintRaw->params) ? (array) $constraintRaw->params : null;
            } elseif (is_string($constraintRaw)) {
                $constraintName = $constraintRaw;
            }

            // Clean property path (remove "root." prefix, keep array notation like work[3])
            $property = preg_replace('/^root\.?/', '', $property);
            $field = $property ?: 'document';

            $parts = [];

            if ($field !== 'document') {
                $parts[] = "[{$field}]";
            }

            $parts[] = (string) $message;

            if ($constraintName && is_string($constraintName)) {
                if ($constraintParams && is_array($constraintParams) && ! empty($constraintParams)) {
                    $paramParts = [];
                    foreach ($constraintParams as $key => $value) {
                        $valStr = is_array($value) ? json_encode($value) : (string) $value;
                        $paramParts[] = "{$key}={$valStr}";
                    }
                    $parts[] = '(constraint: '.$constraintName.': '.implode(', ', $paramParts).')';
                } else {
                    $parts[] = "(constraint: {$constraintName})";
                }
            }

            $errors[] = trim(implode(' ', $parts));
        }

        $errors = array_values(array_filter($errors, fn ($e) => is_string($e) && $e !== ''));

        if (empty($errors)) {
            $errors = ['Schema validation failed (no detailed errors available)'];
        }

        return [
            'valid' => false,
            'errors' => $errors,
        ];
    }

    /**
     * Empty strings read as absent throughout JSON Resume — without
     * this, '' fails format:uri on optional fields like basics.image.
     * Controllers store the normalized form so exports agree with
     * what was validated.
     */
    public static function normalize(array $data): array
    {
        return self::dropEmptyStrings($data);
    }

    protected static function dropEmptyStrings(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::dropEmptyStrings($value);
            } elseif ($value === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }
}

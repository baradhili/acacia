<?php

namespace App\Traits;

/**
 * Key/value custom fields on a model's custom_fields JSON column
 * (array-cast). Used by Client and Supplier — the contacts migration
 * gives each a nullable json column. The mutators assign the whole
 * attribute, so changes persist only on save.
 */
trait HasCustomFields
{
    /**
     * Get a custom field value by key
     */
    public function getCustomField(string $key, $default = null)
    {
        return $this->custom_fields[$key] ?? $default;
    }

    /**
     * Set a custom field value
     */
    public function setCustomField(string $key, $value): void
    {
        $fields = $this->custom_fields ?? [];
        $fields[$key] = $value;
        $this->custom_fields = $fields;
    }

    /**
     * Remove a custom field
     */
    public function removeCustomField(string $key): void
    {
        $fields = $this->custom_fields ?? [];
        unset($fields[$key]);
        $this->custom_fields = array_values($fields);
    }

    /**
     * Check if a custom field exists
     */
    public function hasCustomField(string $key): bool
    {
        return isset($this->custom_fields[$key]);
    }

    /**
     * Get all custom field keys
     */
    public function getCustomFieldKeys(): array
    {
        return array_keys($this->custom_fields ?? []);
    }
}

<?php

namespace App\Models;

use IFRS\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Administrator settings for the reporting entity. One row per entity;
 * open_year pins the "currently open" financial year (null = follow the
 * calendar). Window/expiry rules live in FiscalYearService.
 */
class EntitySetting extends Model
{
    protected $fillable = [
        'entity_id',
        'open_year',
    ];

    protected $casts = [
        'open_year' => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public static function forEntity(Entity $entity): self
    {
        return static::firstOrNew(['entity_id' => $entity->id]);
    }

    /**
     * The stored open-year pin, raw — may be stale (outside the allowed
     * window) if the clock has moved on since it was set.
     */
    public static function storedOpenYear(Entity $entity): ?int
    {
        $year = static::forEntity($entity)->open_year;

        return $year === null ? null : (int) $year;
    }

    public static function setOpenYear(Entity $entity, ?int $year): void
    {
        static::updateOrCreate(
            ['entity_id' => $entity->id],
            ['open_year' => $year],
        );
    }
}

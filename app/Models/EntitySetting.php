<?php

namespace App\Models;

use App\Console\Commands\PruneClosedYearLedgers;
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
        'psi_mode',
        'psb_results',
        'psi_assessed_at',
        'retention_years',
    ];

    protected $casts = [
        'open_year' => 'integer',
        'psi_mode' => 'boolean',
        'psb_results' => 'array',
        'psi_assessed_at' => 'datetime',
        'retention_years' => 'integer',
    ];

    /**
     * The configured data-retention window in years (closed years
     * older than this are pruned by ledger:prune). Null means the
     * documented default of 7.
     */
    public static function retentionYears(Entity $entity): int
    {
        return static::forEntity($entity)->retention_years
            ?? PruneClosedYearLedgers::DEFAULT_RETENTION_YEARS;
    }

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

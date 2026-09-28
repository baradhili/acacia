<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a user's saved dashboard layout, keyed by widget id (the
 * class basename from the registry). position_y is the widget's index
 * in the sequence the full save wrote (grid widgets first, then
 * removed ones); NULL — the default — means no full save ever placed
 * it and the widget renders in registry order. width is the
 * column-span override where 0 means the registry's shipped span;
 * visible=false is a widget removed through edit mode. position_x and
 * collapsed are unused for now — reserved for the freeform-resize and
 * collapse follow-ups.
 */
class WidgetPreference extends Model
{
    protected $fillable = [
        'user_id',
        'widget_name',
        'position_x',
        'position_y',
        'width',
        'visible',
        'collapsed',
    ];

    protected $casts = [
        'visible' => 'boolean',
        'collapsed' => 'boolean',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'width' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getForUser(int $userId): array
    {
        return static::where('user_id', $userId)
            ->get()
            ->keyBy('widget_name')
            ->toArray();
    }

    public static function updateForUser(int $userId, string $widgetName, array $data): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'widget_name' => $widgetName],
            $data
        );
    }
}

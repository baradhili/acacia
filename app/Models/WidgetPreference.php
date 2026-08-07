<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetPreference extends Model
{
    protected $fillable = [
        'user_id',
        'widget_name',
        'position_x',
        'position_y',
        'width',
        'height',
        'visible',
        'collapsed',
    ];

    protected $casts = [
        'visible' => 'boolean',
        'collapsed' => 'boolean',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
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

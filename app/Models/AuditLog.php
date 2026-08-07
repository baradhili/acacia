<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';

    public $timestamps = false;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'user_id',
        'user_name',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
        'changed_fields',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
            if (empty($model->user_id) && auth()->check()) {
                $model->user_id = auth()->id();
                $model->user_name = auth()->user()->name ?? null;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForModel($query, string $modelType, ?int $modelId = null)
    {
        $query->where('auditable_type', $modelType);
        
        if ($modelId !== null) {
            $query->where('auditable_id', $modelId);
        }
        
        return $query;
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeCreated($query)
    {
        return $query->where('action', self::ACTION_CREATED);
    }

    public function scopeUpdated($query)
    {
        return $query->where('action', self::ACTION_UPDATED);
    }

    public function scopeDeleted($query)
    {
        return $query->where('action', self::ACTION_DELETED);
    }

    public function getChangedFieldsList(): string
    {
        if (empty($this->changed_fields)) {
            return 'None';
        }
        return implode(', ', $this->changed_fields);
    }
}

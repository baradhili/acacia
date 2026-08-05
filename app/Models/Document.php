<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'name',
        'file_path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the download URL
     */
    public function getUrlAttribute(): string
    {
        return route('documents.download', $this);
    }
}

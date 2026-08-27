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

    protected static function boot()
    {
        parent::boot();

        // Remove the stored file along with the row (the documentable
        // morph has no FK cascade, so rows are deleted explicitly — e.g.
        // when a bill is deleted). Best-effort: a missing/unreadable
        // file never blocks the row delete.
        static::deleting(function ($document) {
            if ($document->file_path) {
                try {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($document->file_path);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to delete document file', [
                        'document_id' => $document->id,
                        'file_path' => $document->file_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

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

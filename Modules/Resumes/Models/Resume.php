<?php

namespace Modules\Resumes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payroll\Models\Employee;

/**
 * A resume uploaded against a payroll payee, on the modified JSON
 * Resume v1.0.0 schema. parsed_data is the whole document — every
 * export (JSON, PDF, LaTeX, DOCX) regenerates from it, so no raw
 * copy is kept on disk.
 */
class Resume extends Model
{
    protected $fillable = [
        'employee_id',
        'entity_id',
        'uploaded_by',
        'original_filename',
        'parsed_data',
        'json_resume_version',
        'uploaded_at',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'uploaded_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getBasicsAttribute(): array
    {
        return $this->parsed_data['basics'] ?? [];
    }

    public function getNameAttribute(): string
    {
        return $this->basics['name'] ?? 'Unknown';
    }

    public function getEmailAttribute(): string
    {
        return $this->basics['email'] ?? '';
    }
}

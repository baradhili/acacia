<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    protected $table = 'crm_lead_activities';

    protected $fillable = [
        'lead_id',
        'user_id',
        'type',
        'summary',
        'details',
        'happened_at',
    ];

    protected $casts = [
        'happened_at' => 'datetime',
    ];

    public const TYPES = ['note', 'call', 'email', 'meeting', 'task'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

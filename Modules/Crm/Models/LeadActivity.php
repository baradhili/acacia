<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interaction logged against a CRM lead — a note, call, email,
 * meeting or task — written by whoever handled it and dated when it
 * happened rather than when it was keyed in; the lead's activity
 * trail is ordered by that happened-at time.
 */
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

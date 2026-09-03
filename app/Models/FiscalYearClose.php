<?php

namespace App\Models;

use Carbon\Carbon;
use IFRS\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearClose extends Model
{
    // Trial close computed; reviewable, no approval requested yet.
    public const STATUS_TRIAL = 'trial';

    // Approval requested; waiting on an accountant/admin other than the requester.
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    // Approved and ready to execute.
    public const STATUS_APPROVED = 'approved';

    // Close executed: closing entries posted, period CLOSED, app periods locked.
    public const STATUS_CLOSED = 'closed';

    // Closed then reopened: closing entries reversed, period OPEN again.
    public const STATUS_REOPENED = 'reopened';

    public const STATUSES = [
        self::STATUS_TRIAL,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_CLOSED,
        self::STATUS_REOPENED,
    ];

    protected $fillable = [
        'entity_id',
        'year',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'closed_at',
        'reopened_at',
        'checklist',
        'trial_totals',
        'closing_transaction_ids',
        'superseded_opening_balances',
    ];

    protected $casts = [
        'year' => 'integer',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'checklist' => 'array',
        'trial_totals' => 'array',
        'closing_transaction_ids' => 'array',
        'superseded_opening_balances' => 'array',
    ];

    /**
     * The ledger reference stamped on closing entries for this year —
     * reports exclude transactions whose reference starts with this
     * prefix from P&L movement so historical statements survive the close.
     */
    public const CLOSING_REFERENCE_PREFIX = 'FY-CLOSE-';

    public function closingReference(): string
    {
        return self::CLOSING_REFERENCE_PREFIX.$this->year;
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Workflow transitions that each action allows. A reopened year can
     * go through the cycle again (fresh trial → approval → close).
     */
    public function canSubmit(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_REOPENED], true)
            || $this->status === self::STATUS_PENDING_APPROVAL; // resubmit refreshes
    }

    public function canApprove(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function canClose(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canReopen(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Human-readable phase of the workflow row for badges/labels.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_TRIAL => 'Trial close',
            self::STATUS_PENDING_APPROVAL => 'Pending approval',
            self::STATUS_APPROVED => 'Approved — ready to close',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_REOPENED => 'Reopened',
            default => $this->status,
        };
    }

    public function closedAt(): ?Carbon
    {
        return $this->closed_at;
    }
}

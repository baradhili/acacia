<?php

namespace Modules\Crm\Models;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead moving through the sales funnel: new → contacted →
 * qualified → proposal → won/lost. Winning happens by converting the
 * lead to a Client (the ERP seam); losing records why. Open leads
 * (anything before won/lost) make up the pipeline — estimated value
 * weighted by probability for the forecast.
 */
class Lead extends Model
{
    protected $table = 'crm_leads';

    protected $fillable = [
        'name',
        'company',
        'email',
        'phone',
        'source',
        'status',
        'notes',
        'estimated_value',
        'probability',
        'owner_id',
        'next_follow_up',
        'loss_reason',
        'client_id',
        'estimate_id',
        'converted_at',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'probability' => 'decimal:2',
        'next_follow_up' => 'date',
        'converted_at' => 'datetime',
    ];

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_PROPOSAL = 'proposal';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_PROPOSAL,
        self::STATUS_WON,
        self::STATUS_LOST,
    ];

    /** The funnel steps before an outcome — the open pipeline. */
    public const OPEN_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_PROPOSAL,
    ];

    protected static array $transitions = [
        'new' => ['contacted', 'lost'],
        'contacted' => ['qualified', 'lost', 'new'],
        'qualified' => ['proposal', 'lost', 'contacted'],
        'proposal' => ['won', 'lost', 'qualified'],
        'won' => [],
        'lost' => ['new'], // re-open
    ];

    public static function sources(): array
    {
        return ['website', 'referral', 'cold_call', 'event', 'existing_client', 'other'];
    }

    public static function labels(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_CONTACTED => 'Contacted',
            self::STATUS_QUALIFIED => 'Qualified',
            self::STATUS_PROPOSAL => 'Proposal',
            self::STATUS_WON => 'Won',
            self::STATUS_LOST => 'Lost',
        ];
    }

    public function label(): string
    {
        return static::labels()[$this->status] ?? ucfirst($this->status);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, static::$transitions[$this->status] ?? [], true);
    }

    /**
     * Forecast contribution: estimated value weighted by the win
     * probability.
     */
    public function weightedValue(): float
    {
        return round((float) $this->estimated_value * ((float) $this->probability / 100), 2);
    }

    /** Open pipeline leads (before won/lost). */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The estimate prepared through the proposal-stage shortcut.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('happened_at');
    }
}

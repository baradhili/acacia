<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    // Default expense categories
    const CATEGORIES = [
        'travel',
        'software',
        'subcontractors',
        'office_supplies',
        'equipment',
        'marketing',
        'utilities',
        'rent',
        'insurance',
        'professional_services',
        'training',
        'meals',
        'communication',
        'other',
    ];

    protected $fillable = [
        'supplier_id',
        'category',
        'amount',
        'tax_amount',
        'total',
        'expense_date',
        'due_date',
        'status',
        'description',
        'reference',
        'receipt_path',
        'paid_by_user_id',
        'paid_date',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'expense_date' => 'date',
        'due_date' => 'date',
        'paid_date' => 'date',
    ];

    /**
     * Get the supplier (contact) for this expense
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    /**
     * Get the user who paid this expense
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * Calculate total from amount and tax
     */
    public function calculateTotal(): float
    {
        return (float) $this->amount + (float) $this->tax_amount;
    }

    /**
     * Check if expense can be edited
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED]);
    }

    /**
     * Check if expense can be deleted
     */
    public function canBeDeleted(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT]);
    }

    /**
     * Check if expense can be paid
     */
    public function canBePaid(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED]);
    }

    /**
     * Submit expense for approval
     */
    public function submit(): bool
    {
        if ($this->status !== self::STATUS_DRAFT) {
            return false;
        }
        
        $this->update(['status' => self::STATUS_SUBMITTED]);
        return true;
    }

    /**
     * Approve expense
     */
    public function approve(): bool
    {
        if ($this->status !== self::STATUS_SUBMITTED) {
            return false;
        }
        
        $this->update(['status' => self::STATUS_APPROVED]);
        return true;
    }

    /**
     * Mark expense as paid
     */
    public function markAsPaid(string $paymentMethod = null, int $userId = null): bool
    {
        if (!$this->canBePaid()) {
            return false;
        }
        
        $this->update([
            'status' => self::STATUS_PAID,
            'paid_date' => now(),
            'payment_method' => $paymentMethod,
            'paid_by_user_id' => $userId,
        ]);
        
        return true;
    }

    /**
     * Cancel expense
     */
    public function cancel(): bool
    {
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED])) {
            return false;
        }
        
        $this->update(['status' => self::STATUS_CANCELLED]);
        return true;
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by category
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('expense_date', [$startDate, $endDate]);
    }
}

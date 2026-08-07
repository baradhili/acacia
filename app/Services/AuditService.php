<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditService
{
    protected array $ignoredFields = ['updated_at', 'remember_token'];

    protected array $modelsToAudit = [
        \App\Models\Invoice::class,
        \App\Models\Payment::class,
        \App\Models\Client::class,
        \App\Models\Expense::class,
        \App\Models\Project::class,
        \App\Models\PurchaseOrder::class,
        \App\Models\TimeEntry::class,
        \App\Models\BankTransaction::class,
    ];

    /**
     * Log a model creation
     */
    public function logCreated(Model $model): void
    {
        $this->log($model, AuditLog::ACTION_CREATED, [
            'new_values' => $this->getValues($model),
        ]);
    }

    /**
     * Log a model update
     */
    public function logUpdated(Model $model, array $oldValues): void
    {
        $changedFields = $this->getChangedFields($model, $oldValues);
        
        if (empty($changedFields)) {
            return; // No changes
        }

        $this->log($model, AuditLog::ACTION_UPDATED, [
            'old_values' => $this->filterIgnoredFields($oldValues),
            'new_values' => $this->filterIgnoredFields($model->getAttributes()),
            'changed_fields' => $changedFields,
        ]);
    }

    /**
     * Log a model deletion
     */
    public function logDeleted(Model $model): void
    {
        $this->log($model, AuditLog::ACTION_DELETED, [
            'old_values' => $this->getValues($model),
        ]);
    }

    /**
     * Create the audit log entry
     */
    protected function log(Model $model, string $action, array $data): void
    {
        $request = request();
        
        AuditLog::create([
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'user_id' => auth()->id(),
            'user_name' => auth()->user()->name ?? null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'changed_fields' => $data['changed_fields'] ?? null,
        ]);
    }

    /**
     * Get all values from a model
     */
    protected function getValues(Model $model): array
    {
        return $this->filterIgnoredFields($model->getAttributes());
    }

    /**
     * Get changed fields between old and new values
     */
    protected function getChangedFields(Model $model, array $oldValues): array
    {
        $newValues = $model->getAttributes();
        $changedFields = [];

        foreach ($oldValues as $key => $oldValue) {
            if (in_array($key, $this->ignoredFields)) {
                continue;
            }

            $newValue = $newValues[$key] ?? null;

            if ($this->isDifferent($oldValue, $newValue)) {
                $changedFields[] = $key;
            }
        }

        return $changedFields;
    }

    /**
     * Check if two values are different
     */
    protected function isDifferent($old, $new): bool
    {
        if (is_array($old) || is_array($new)) {
            return json_encode($old) !== json_encode($new);
        }
        
        return $old !== $new;
    }

    /**
     * Filter out ignored fields
     */
    protected function filterIgnoredFields(array $values): array
    {
        return array_diff_key($values, array_flip($this->ignoredFields));
    }

    /**
     * Get audit history for a model
     */
    public function getHistory(string $modelType, int $modelId): \Illuminate\Database\Eloquent\Collection
    {
        return AuditLog::forModel($modelType, $modelId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get audit statistics
     */
    public function getStats(?string $startDate = null, ?string $endDate = null): array
    {
        $query = AuditLog::query();

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $byAction = (clone $query)
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        $byModel = (clone $query)
            ->selectRaw('auditable_type, COUNT(*) as count')
            ->groupBy('auditable_type')
            ->pluck('count', 'auditable_type')
            ->toArray();

        $byUser = (clone $query)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, user_name, COUNT(*) as count')
            ->groupBy('user_id', 'user_name')
            ->get()
            ->mapWithKeys(fn($item) => [$item->user_name ?? 'Unknown' => $item->count])
            ->toArray();

        return [
            'total' => $query->count(),
            'by_action' => $byAction,
            'by_model' => $byModel,
            'by_user' => $byUser,
        ];
    }

    /**
     * Check if a model type should be audited
     */
    public function shouldAudit(string $modelType): bool
    {
        return in_array($modelType, $this->modelsToAudit);
    }

    /**
     * Add a model type to audit
     */
    public function addModelToAudit(string $modelType): void
    {
        if (!in_array($modelType, $this->modelsToAudit)) {
            $this->modelsToAudit[] = $modelType;
        }
    }

    /**
     * Remove a model type from audit
     */
    public function removeModelFromAudit(string $modelType): void
    {
        $this->modelsToAudit = array_filter(
            $this->modelsToAudit,
            fn($type) => $type !== $modelType
        );
    }
}

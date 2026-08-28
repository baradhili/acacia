<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\DividendDeclaration;
use App\Models\DividendDistribution;
use App\Models\FrankingAccountEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Shareholding;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditService
{
    protected array $ignoredFields = ['updated_at', 'remember_token'];

    protected array $modelsToAudit = [
        Invoice::class,
        Payment::class,
        Client::class,
        Bill::class,
        BillPayment::class,
        Project::class,
        PurchaseOrder::class,
        TimeEntry::class,
        BankTransaction::class,
        Shareholding::class,
        FrankingAccountEntry::class,
        DividendDeclaration::class,
        DividendDistribution::class,
    ];

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    /**
     * Log a model creation
     */
    public function logCreated(Model $model): void
    {
        $this->log($model, self::ACTION_CREATED, [
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

        $this->log($model, self::ACTION_UPDATED, [
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
        $this->log($model, self::ACTION_DELETED, [
            'old_values' => $this->getValues($model),
        ]);
    }

    /**
     * Write audit entry to syslog
     */
    protected function log(Model $model, string $action, array $data): void
    {
        $request = request();

        $auditEntry = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'audit',
            'action' => $action,
            'model' => [
                'type' => get_class($model),
                'id' => $model->getKey(),
            ],
            'user' => [
                'id' => auth()->id(),
                'name' => auth()->user()->name ?? null,
            ],
            'request' => [
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ],
            'changes' => $data,
        ];

        // Write to syslog as JSON
        Log::channel('syslog')->info('AUDIT', $auditEntry);
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
        if (! in_array($modelType, $this->modelsToAudit)) {
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
            fn ($type) => $type !== $modelType
        );
    }
}

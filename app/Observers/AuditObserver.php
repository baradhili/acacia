<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Handle the model "created" event.
     */
    public function created(Model $model): void
    {
        if ($this->shouldAudit($model)) {
            $this->auditService->logCreated($model);
        }
    }

    /**
     * Handle the model "updated" event.
     */
    public function updated(Model $model): void
    {
        if ($this->shouldAudit($model)) {
            $this->auditService->logUpdated($model, $model->getOriginal());
        }
    }

    /**
     * Handle the model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        if ($this->shouldAudit($model)) {
            $this->auditService->logDeleted($model);
        }
    }

    /**
     * Check if model should be audited
     */
    protected function shouldAudit(Model $model): bool
    {
        // Check if model is in the audit list
        $modelType = get_class($model);
        
        // Get the service directly to avoid issues with DI
        $service = app(AuditService::class);
        
        return $service->shouldAudit($modelType);
    }
}

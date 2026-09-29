<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder for the Proposals module's Estimate when that module
 * is uninstalled — the crm_leads.estimate_id FK and the core-schema
 * estimates table outlive the class, so Lead::estimate() binds to
 * this stub (guarded by class_exists per the cross-module rule)
 * instead of dereferencing a missing class. Lead::estimate() also
 * constrains the relation to never match a row: callers get null,
 * not a fatal, and no half-working Estimate behaviour leaks through.
 */
class OrphanedEstimate extends Model
{
    protected $table = 'estimates';
}

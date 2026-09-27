<?php

namespace Modules\Skills\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The service side of a skill link: a core catalogue service
 * requires a skill. Modelled explicitly for one-service syncs;
 * Skill::services() reads the same table from the other side.
 */
class ServiceSkill extends Model
{
    protected $table = 'service_skill';

    protected $fillable = ['service_id', 'skill_id'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}

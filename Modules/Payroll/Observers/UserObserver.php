<?php

namespace Modules\Payroll\Observers;

use App\Models\User;
use App\Services\IfrsPosting;
use Modules\Payroll\Models\Employee;

/**
 * Every staff-side user shows as a payee: creating a user seeds their
 * linked employee record (employees.user_id), which is also what lets
 * them manage their own resumes. Portal clients are skipped — they
 * are customers, not people the entity pays through payroll — but the
 * check only sees roles held at creation time; a user flipped to
 * client afterwards keeps their payee (delete it manually — and the
 * client role itself is slated for removal).
 */
class UserObserver
{
    public function created(User $user): void
    {
        self::ensureEmployeeFor($user);
    }

    /**
     * Create the user's linked payee when they don't have one (and
     * aren't a portal client). Shared with payroll:sync-users, which
     * backfills users created before the observer existed.
     */
    public static function ensureEmployeeFor(User $user): ?Employee
    {
        // loaded role names, not hasRole(): spatie throws when asked
        // about a role that isn't registered on this deployment
        if ($user->getRoleNames()->contains('client')) {
            return null;
        }

        if (Employee::where('user_id', $user->id)->exists()) {
            return null;
        }

        // nowhere to hang the payee (e.g. entity-less users in edge
        // deployments) — skip rather than fail the user creation
        $entityId = $user->entity_id ?? IfrsPosting::resolveEntity()?->id;
        if ($entityId === null) {
            return null;
        }

        return Employee::create([
            'user_id' => $user->id,
            'entity_id' => $entityId,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}

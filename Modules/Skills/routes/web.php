<?php

use Illuminate\Support\Facades\Route;
use Modules\Skills\Http\Controllers\EmployeeSkillController;
use Modules\Skills\Http\Controllers\ServiceSkillController;
use Modules\Skills\Http\Controllers\SkillController;

/*
|--------------------------------------------------------------------------
| Skills module routes
|--------------------------------------------------------------------------
| Browsing the skill library and the two link matrices (per payee,
| per service) is open to every signed-in user — like resumes, this
| is staffing-facing data; changing the library or the links is
| admin/accountant work, matching the services and payee master data
| it hangs off. 'web' is explicit — provider-loaded routes inherit
| no group, and without SubstituteBindings the controllers receive
| empty models.
*/

// Managing the library and the links. This group comes first:
// /skills/create must be matched before the /skills/{skill} show
// route below, or it would bind "create" as a skill id.
Route::middleware(['web', 'auth', 'role:admin|accountant'])->group(function () {
    Route::get('/skills/create', [SkillController::class, 'create'])->name('skills.create');
    Route::post('/skills', [SkillController::class, 'store'])->name('skills.store');
    Route::post('/skills/rsd', [SkillController::class, 'uploadRsd'])->name('skills.rsd');
    Route::get('/skills/{skill}/edit', [SkillController::class, 'edit'])->name('skills.edit');
    Route::match(['put', 'patch'], '/skills/{skill}', [SkillController::class, 'update'])->name('skills.update');
    Route::delete('/skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');

    // The matrices post back to themselves — plain POST forms, so no
    // PUT spoofing to get wrong.
    Route::post('/skills/employees/{employee}', [EmployeeSkillController::class, 'update'])->name('skills.employees.update');
    Route::post('/skills/services/{service}', [ServiceSkillController::class, 'update'])->name('skills.services.update');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/skills', [SkillController::class, 'index'])->name('skills.index');
    Route::get('/skills/employees', [EmployeeSkillController::class, 'index'])->name('skills.employees.index');
    Route::get('/skills/employees/{employee}', [EmployeeSkillController::class, 'show'])->name('skills.employees.show');
    Route::get('/skills/services', [ServiceSkillController::class, 'index'])->name('skills.services.index');
    Route::get('/skills/services/{service}', [ServiceSkillController::class, 'show'])->name('skills.services.show');

    // Kept last so the literal paths above win over the parameter.
    Route::get('/skills/{skill}', [SkillController::class, 'show'])->name('skills.show');
});

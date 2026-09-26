<?php

use Illuminate\Support\Facades\Route;
use Modules\Resumes\Http\Controllers\ResumeController;

/*
|--------------------------------------------------------------------------
| Resumes module routes
|--------------------------------------------------------------------------
| Open to every signed-in user (deliberate: staffing decisions need
| wider visibility than the payroll master data these hang off).
| 'web' is explicit — provider-loaded routes inherit no group, and
| without SubstituteBindings the controllers receive empty models.
*/

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/resumes', [ResumeController::class, 'index'])->name('resumes.index');
    Route::get('/resumes/create', [ResumeController::class, 'create'])->name('resumes.create');
    Route::post('/resumes', [ResumeController::class, 'store'])->name('resumes.store');
    Route::get('/resumes/{resume}', [ResumeController::class, 'show'])->name('resumes.show');
    Route::delete('/resumes/{resume}', [ResumeController::class, 'destroy'])->name('resumes.destroy');

    Route::get('/resumes/{resume}/download/json', [ResumeController::class, 'downloadJson'])->name('resumes.json');
    Route::get('/resumes/{resume}/download/pdf', [ResumeController::class, 'downloadPdf'])->name('resumes.pdf');
    Route::get('/resumes/{resume}/download/latex', [ResumeController::class, 'downloadLatex'])->name('resumes.latex');
    Route::get('/resumes/{resume}/download/docx', [ResumeController::class, 'downloadDocx'])->name('resumes.docx');
});

<?php

use App\Http\Controllers\Knowledge\ClaimController;
use App\Http\Controllers\Knowledge\CredentialController;
use App\Http\Controllers\Knowledge\EmploymentController;
use App\Http\Controllers\Knowledge\KnowledgeController;
use App\Http\Controllers\Knowledge\ProjectController;
use App\Http\Controllers\Knowledge\SkillController;
use App\Http\Controllers\Profile\ContactController;
use App\Http\Controllers\Profile\LanguageController;
use App\Http\Controllers\Profile\LinkController;
use App\Http\Controllers\Profile\ResumeController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'tenant', 'candidate.two-factor'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Profile: contact details + resumes (resume-first authoring, D9).
    Route::prefix('profile')->group(function () {
        Route::get('/', [ResumeController::class, 'index'])->name('resumes.index');

        Route::middleware('can:edit-candidate-profile')->group(function () {
            Route::get('contact', [ContactController::class, 'edit'])->name('contact.edit');
            Route::patch('contact', [ContactController::class, 'update'])->name('contact.update');
            Route::post('contact/languages', [LanguageController::class, 'store'])->name('contact.languages.store');
            Route::delete('contact/languages/{language}', [LanguageController::class, 'destroy'])->name('contact.languages.destroy');
            Route::post('contact/links', [LinkController::class, 'store'])->name('contact.links.store');
            Route::delete('contact/links/{link}', [LinkController::class, 'destroy'])->name('contact.links.destroy');
        });

        Route::get('resumes/{resume}', [ResumeController::class, 'show'])->name('resumes.show');

        Route::middleware('can:edit-resumes')->group(function () {
            Route::post('resumes', [ResumeController::class, 'store'])->name('resumes.store');
            Route::put('resumes/{resume}', [ResumeController::class, 'update'])->name('resumes.update');
            Route::patch('resumes/{resume}/default', [ResumeController::class, 'makeDefault'])->name('resumes.default');
            Route::patch('resumes/{resume}/archive', [ResumeController::class, 'archive'])->name('resumes.archive');
            Route::patch('resumes/{resume}/unarchive', [ResumeController::class, 'unarchive'])->name('resumes.unarchive');
            Route::post('resumes/{resume}/revisions/{revision}/restore', [ResumeController::class, 'restoreRevision'])->name('resumes.revisions.restore');
            Route::post('resumes/{resume}/claims/{claim}/apply', [ResumeController::class, 'applyBullet'])->name('resumes.apply-bullet');
        });
    });

    // Library: every job, project and bullet point (the shared facts behind all resumes).
    Route::prefix('library')->name('knowledge.')->group(function () {
        Route::get('/', [KnowledgeController::class, 'index'])->name('index');

        Route::resource('employments', EmploymentController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('employments/{employment}/verify', [EmploymentController::class, 'verify'])->name('employments.verify');
        Route::patch('employments/{employment}/archive', [EmploymentController::class, 'archive'])->name('employments.archive');
        Route::patch('employments/{employment}/restore', [EmploymentController::class, 'restore'])->name('employments.restore');

        Route::resource('projects', ProjectController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('projects/{project}/verify', [ProjectController::class, 'verify'])->name('projects.verify');
        Route::patch('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::patch('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

        Route::resource('credentials', CredentialController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('credentials/{credential}/verify', [CredentialController::class, 'verify'])->name('credentials.verify');
        Route::patch('credentials/{credential}/archive', [CredentialController::class, 'archive'])->name('credentials.archive');
        Route::patch('credentials/{credential}/restore', [CredentialController::class, 'restore'])->name('credentials.restore');

        Route::middleware('can:edit-candidate-profile')->group(function () {
            Route::post('skills', [SkillController::class, 'store'])->name('skills.store');
            Route::patch('skills/{skill}', [SkillController::class, 'update'])->name('skills.update');
            Route::delete('skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');
        });

        Route::get('claims/bulk', [ClaimController::class, 'bulkCreate'])->name('claims.bulk.create');
        Route::post('claims/bulk', [ClaimController::class, 'bulkStore'])->name('claims.bulk.store');
        Route::resource('claims', ClaimController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('claims/{claim}/verify', [ClaimController::class, 'verify'])->name('claims.verify');
        Route::patch('claims/{claim}/reject', [ClaimController::class, 'reject'])->name('claims.reject');
        Route::patch('claims/{claim}/archive', [ClaimController::class, 'archive'])->name('claims.archive');
        Route::patch('claims/{claim}/restore', [ClaimController::class, 'restore'])->name('claims.restore');
    });

    Route::middleware('can:manage-vas')->group(function () {
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('team/{user:ulid}/deactivate', [TeamController::class, 'deactivate'])->name('team.deactivate');
        Route::patch('team/{user:ulid}/reactivate', [TeamController::class, 'reactivate'])->name('team.reactivate');
        Route::post('team/{user:ulid}/password-link', [TeamController::class, 'passwordLink'])->name('team.password-link');
    });
});

require __DIR__.'/settings.php';

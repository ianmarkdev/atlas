<?php

use App\Http\Controllers\Knowledge\CandidateProfileController;
use App\Http\Controllers\Knowledge\EmploymentController;
use App\Http\Controllers\Knowledge\KnowledgeController;
use App\Http\Controllers\Knowledge\ProjectController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'tenant', 'candidate.two-factor'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('knowledge')->name('knowledge.')->group(function () {
        Route::get('/', [KnowledgeController::class, 'index'])->name('index');

        Route::middleware('can:edit-candidate-profile')->group(function () {
            Route::get('profile', [CandidateProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('profile', [CandidateProfileController::class, 'update'])->name('profile.update');
        });

        Route::resource('employments', EmploymentController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('employments/{employment}/verify', [EmploymentController::class, 'verify'])->name('employments.verify');
        Route::patch('employments/{employment}/archive', [EmploymentController::class, 'archive'])->name('employments.archive');
        Route::patch('employments/{employment}/restore', [EmploymentController::class, 'restore'])->name('employments.restore');

        Route::resource('projects', ProjectController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('projects/{project}/verify', [ProjectController::class, 'verify'])->name('projects.verify');
        Route::patch('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::patch('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
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

<?php

use App\Http\Controllers\Settings\AiActivityController;
use App\Http\Controllers\Settings\AiComparisonController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

// Blind scoring of AI model comparisons (M4), candidate only.
Route::middleware(['auth', 'verified', 'tenant', 'candidate.two-factor', 'can:edit-candidate-profile'])->group(function () {
    Route::get('settings/ai/comparisons', [AiComparisonController::class, 'index'])->name('ai-comparisons.index');
    Route::patch('settings/ai/comparisons/{result}', [AiComparisonController::class, 'rate'])->name('ai-comparisons.rate');

    // Audit of every AI call: outcomes, latency, usage and accuracy.
    Route::get('settings/ai/activity', [AiActivityController::class, 'index'])->name('ai-activity.index');
    Route::get('settings/ai/activity/{run:ulid}', [AiActivityController::class, 'show'])->name('ai-activity.show');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');

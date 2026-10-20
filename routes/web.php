<?php

use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'tenant', 'candidate.two-factor'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('can:manage-vas')->group(function () {
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('team/{user:ulid}/deactivate', [TeamController::class, 'deactivate'])->name('team.deactivate');
        Route::patch('team/{user:ulid}/reactivate', [TeamController::class, 'reactivate'])->name('team.reactivate');
        Route::post('team/{user:ulid}/password-link', [TeamController::class, 'passwordLink'])->name('team.password-link');
    });
});

require __DIR__.'/settings.php';

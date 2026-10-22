<?php

use App\Http\Controllers\Api\CapturedApplicationController;
use Illuminate\Support\Facades\Route;

// The Career Clipper browser extension: saves the job listing open in the browser as an
// application. Token-only (`app:extension-token`), one narrow ability, no reads.
Route::middleware(['auth:sanctum', 'abilities:applications:capture', 'verified', 'tenant', 'candidate.two-factor', 'throttle:application-capture'])
    ->prefix('extension')
    ->group(function () {
        Route::post('applications', CapturedApplicationController::class)->name('api.extension.applications.store');
    });

<?php

use App\Domain\Ai\GenerationPayload;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AI prompt/response bodies are kept only for `ai.payload_retention_days` (M4).
Schedule::command('model:prune', ['--model' => [GenerationPayload::class]])->daily();

// Weekly "knowledge health": suggest results for plain bullet points (M9).
Schedule::command('app:suggest-achievements')->weeklyOn(1, '07:00');

// Applications saved outside this app (Career VA writes to the shared database and cannot queue
// jobs): analyse new ones, and keep the AI payloads of those it moved to a submitted stage.
Schedule::command('app:analyse-new-applications')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('app:retain-submitted-work')->daily();

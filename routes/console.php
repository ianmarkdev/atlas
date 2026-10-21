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

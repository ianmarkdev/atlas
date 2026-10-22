<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\GenerationRun;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmRouter;
use Illuminate\Support\Facades\Storage;

test('the analysis eval scores every fixture job description and saves a report, leaving no runs behind', function () {
    Storage::fake('local');
    $candidate = candidateWithTwoFactor();

    // A deliberately imperfect model: it lists every bullet line as a must-have and never reports injections.
    app(LlmRouter::class)->fake(new FakeLlmClient(array_fill(0, 10, function (LlmRequest $request): array {
        preg_match_all('/^\s*(?:[-•]\s*)?(.{6,120})$/mu', $request->messages[0]['content'], $lines);

        return [
            'summary' => 'A role.', 'seniority' => 'senior',
            'must_haves' => array_map(fn (string $line) => ['requirement' => trim($line), 'quote' => trim($line)], array_slice($lines[1], 0, 20)),
            'nice_to_haves' => [], 'employer_language' => [], 'questions' => [], 'red_flags' => [], 'injection_warnings' => [],
        ];
    })));

    $this->artisan('app:eval-analysis')
        ->expectsOutputToContain('Nothing is saved to your applications')
        ->expectsOutputToContain('must have recall')
        ->expectsOutputToContain('injection caught by server')
        ->assertSuccessful();

    $report = json_decode((string) Storage::disk('local')->get(Storage::disk('local')->files('evals')[0]), true);
    expect($report['summary']['valid_output'])->toBe('10/10')
        ->and($report['summary']['injection_caught_by_model'])->toBe('0/1')
        ->and($report['summary']['injection_caught_by_server'])->toBe('1/1')
        ->and($report['summary']['nice_listed_as_must'])->toBeGreaterThan(0)
        ->and($report['results']['php-backend']['must_recall'])->toEqual(1.0)
        ->and(inWorkspaceOf($candidate, fn () => GenerationRun::query()->count()))->toBe(0);
});

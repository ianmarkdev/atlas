<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\GenerationRun;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmRouter;
use Illuminate\Support\Facades\Storage;

test('the feedback eval scores every fixture edit, counts wrong rule matches and leaves no runs behind', function () {
    Storage::fake('local');
    $candidate = candidateWithTwoFactor();
    $cases = require base_path('tests/Eval/fixtures/feedback-analysis.php');

    // A deliberately careless model: every edit is a rule, and it repeats the first rule it is shown.
    app(LlmRouter::class)->fake(new FakeLlmClient(array_fill(0, count($cases), function (LlmRequest $request): array {
        preg_match('/<rule id="([HR][\d.]+)">/', $request->messages[0]['content'], $match);

        return ['items' => [[
            'type' => 'writing_preference', 'evidence_id' => null, 'statement' => null,
            'rule' => 'A rule', 'rule_kind' => 'tone', 'personal' => false,
            'same_as' => $match[1] ?? null, 'conflicts_with' => null, 'drafts' => 'all',
            'category' => null, 'confidence' => 0.9, 'rationale' => 'The edit shows it.',
        ]]];
    })));

    $this->artisan('app:eval-feedback')
        ->expectsOutputToContain('Nothing is saved to your rules or suggestions')
        ->expectsOutputToContain('wrong same as')
        ->assertSuccessful();

    $report = json_decode((string) Storage::disk('local')->get(Storage::disk('local')->files('evals')[0]), true);
    expect($report['summary']['valid_output'])->toBe(count($cases).'/'.count($cases))
        ->and($report['summary']['one_offs_left_alone'])->toBe('0/2')
        ->and($report['summary']['wrong_same_as'])->toBeGreaterThan(0)
        ->and($report['results']['paraphrase-british-spelling']['same_as_ok'])->toBeTrue()
        ->and($report['results']['distinct-tone-vs-spelling']['wrong_same_as'])->toBe(['H201.0'])
        ->and($report['results']['contradiction-length']['conflicts_ok'])->toBeFalse()
        ->and($report['results']['scope-cover-letter-sign-off']['drafts_ok'])->toBeFalse()
        ->and(inWorkspaceOf($candidate, fn () => GenerationRun::query()->count()))->toBe(0);
});

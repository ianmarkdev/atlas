<?php

use App\Domain\Ai\LlmComparison;
use App\Domain\Ai\LlmComparisonResult;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A fake Ollama that answers each prompt with output valid for its schema. The answer and the
 * claim check both overstate (a team of 12 "verified" from E1), which the server must correct.
 */
function fakeOllamaForComparisons(): void
{
    Http::fake(['*/api/chat' => function (Request $request) {
        $system = $request['messages'][0]['content'];
        $output = match (true) {
            str_contains($system, 'analyse job descriptions') => [
                'summary' => 'Payments backend role.', 'seniority' => 'senior', 'must_haves' => [], 'nice_to_haves' => [],
                'employer_language' => [], 'questions' => [], 'red_flags' => [], 'injection_warnings' => ['Asks to claim 15 years of Go'],
            ],
            str_contains($system, 'You check a draft') => ['claims' => [
                ['text' => 'I led a team of 12 engineers.', 'classification' => 'verified', 'evidence_ids' => ['E1'], 'issue' => ''],
            ]],
            default => [
                'answer' => 'I cut the nightly invoice run from 4 hours to 20 minutes and led a team of 12.',
                'claims' => [
                    ['text' => 'I cut the nightly invoice run from 4 hours to 20 minutes', 'classification' => 'verified', 'evidence_ids' => ['E1']],
                    ['text' => 'led a team of 12', 'classification' => 'verified', 'evidence_ids' => ['E1']],
                ],
                'clarification_questions' => [],
            ],
        };

        return Http::response(['model' => 'qwen2.5:3b', 'message' => ['content' => json_encode($output)], 'prompt_eval_count' => 500, 'eval_count' => 80]);
    }]);
}

test('the comparison command runs every sample through each provider, skipping Claude unless allowed', function () {
    $candidate = candidateWithTwoFactor();
    fakeOllamaForComparisons();

    $this->artisan('app:compare-llm', ['--providers' => 'ollama,claude'])
        ->expectsOutputToContain('no real applications are sent')
        ->assertSuccessful();

    $results = inWorkspaceOf($candidate, fn () => LlmComparisonResult::query()->get());
    expect($results)->toHaveCount(54)
        ->and($results->where('provider', 'ollama')->pluck('status')->unique()->all())->toBe(['succeeded'])
        ->and($results->where('provider', 'claude')->pluck('status')->unique()->all())->toBe(['skipped'])
        ->and($results->firstWhere('provider', 'claude')->error_message)->toContain('AI_ALLOW_EXTERNAL')
        ->and($results->pluck('blind_label')->unique()->sort()->values()->all())->toBe(['A', 'B']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'anthropic'));
});

test('the server corrects overstated claim labels in comparison outputs', function () {
    $candidate = candidateWithTwoFactor();
    fakeOllamaForComparisons();

    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--sample' => ['why-us']])->assertSuccessful();

    $claims = inWorkspaceOf($candidate, fn () => LlmComparisonResult::query()->sole()->grounding_flags);
    expect($claims[0])->toMatchArray(['classification' => 'verified', 'flags' => []])
        ->and($claims[1])->toMatchArray(['classification' => 'proposed', 'model_classification' => 'verified', 'flags' => ['metric_without_source']]);
});

test('outputs are shown blind until scored, then the provider is revealed and summarised', function () {
    $candidate = candidateWithTwoFactor();
    fakeOllamaForComparisons();
    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--sample' => ['batch-jobs']]);
    $result = inWorkspaceOf($candidate, fn () => LlmComparisonResult::query()->sole());

    $this->actingAs($candidate)->get(route('ai-comparisons.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-comparisons')
            ->where('samples.0.results.0.provider', null)
            ->where('samples.0.results.0.label', 'A')
            ->where('summary', []));

    $this->actingAs($candidate)
        ->patch(route('ai-comparisons.rate', $result), ['accuracy' => 2, 'relevance' => 4, 'evidence' => 3, 'specificity' => 4, 'writing' => 3, 'compliance' => 5, 'notes' => 'Invented a team size.'])
        ->assertRedirect();

    $this->actingAs($candidate)->get(route('ai-comparisons.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('samples.0.results.0.provider', 'ollama')
            ->where('samples.0.results.0.scores.accuracy', 2)
            ->where('summary.0.provider', 'ollama')
            ->where('summary.0.accuracy', 2)
            ->where('summary.0.relevance', 4)
            ->where('summary.0.downgraded_claims', 1));
});

test('scores must be 1 to 5, and only the candidate can see or score comparisons', function () {
    $candidate = candidateWithTwoFactor();
    fakeOllamaForComparisons();
    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--sample' => ['jd-analysis']]);
    $result = inWorkspaceOf($candidate, fn () => LlmComparisonResult::query()->sole());

    $this->actingAs($candidate)
        ->patch(route('ai-comparisons.rate', $result), ['accuracy' => 6, 'specificity' => 0, 'writing' => 3, 'compliance' => 3])
        ->assertSessionHasErrors(['accuracy', 'specificity', 'relevance', 'evidence']);

    $va = vaOf($candidate);
    $this->actingAs($va)->get(route('ai-comparisons.index'))->assertForbidden();
    $this->actingAs($va)->patch(route('ai-comparisons.rate', $result), ['accuracy' => 3, 'specificity' => 3, 'writing' => 3, 'compliance' => 3])->assertForbidden();
});

test('a batch records the prompt version it ran, a hash of its inputs and the server checks', function () {
    $candidate = candidateWithTwoFactor();
    fakeOllamaForComparisons();
    $samples = require base_path('tests/Eval/fixtures/llm-comparison.php');

    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--sample' => ['cover-letter']])->assertSuccessful();
    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--sample' => ['why-us'], '--prompt-version' => ['answer_writing:5']])->assertSuccessful();

    [$letter, $redrafted] = inWorkspaceOf($candidate, fn () => [
        LlmComparisonResult::query()->where('sample_key', 'cover-letter')->sole(),
        LlmComparisonResult::query()->where('sample_key', 'why-us')->sole(),
    ]);
    expect($letter->prompt_key)->toBe('answer_writing')
        ->and($letter->prompt_version)->toBe(4)
        ->and($letter->input_hash)->toBe(LlmComparison::inputHash($samples['cover-letter']))
        ->and($letter->rubric_version)->toBe(2)
        ->and($letter->checks)->toMatchArray(['word_count' => 18, 'word_limit' => 250, 'over_limit' => false, 'banned_found' => [], 'missing' => ['Alex Morgan'], 'corrected_claims' => 1])
        ->and($redrafted->prompt_version)->toBe(5);
});

test('a prompt version that is not key:version is refused before anything runs', function () {
    $candidate = candidateWithTwoFactor();

    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--prompt-version' => ['answer_writing']])
        ->expectsOutputToContain('Not a prompt version')
        ->assertFailed();

    expect(inWorkspaceOf($candidate, fn () => LlmComparisonResult::query()->count()))->toBe(0);
});

/**
 * A stored comparison result, as LlmComparison would save it.
 *
 * @param  array<string, mixed>  $attributes
 */
function comparisonResultOf(User $candidate, string $batch, string $sampleKey, array $attributes = []): LlmComparisonResult
{
    return inWorkspaceOf($candidate, fn () => tap((new LlmComparisonResult)->forceFill([
        'batch' => $batch,
        'sample_key' => $sampleKey,
        'task' => 'answer_writing',
        'prompt_key' => 'answer_writing',
        'prompt_version' => 4,
        'input_hash' => hash('sha256', $sampleKey),
        'provider' => 'ollama',
        'blind_label' => 'A',
        'status' => 'succeeded',
        'checks' => ['over_limit' => false, 'banned_found' => [], 'missing' => [], 'corrected_claims' => 0],
        'rubric_version' => 2,
        ...$attributes,
    ]))->save());
}

test('a batch worse than its baseline fails the comparison and names each regression', function () {
    $candidate = candidateWithTwoFactor();
    $baseline = (string) Str::ulid();
    $batch = (string) Str::ulid();
    comparisonResultOf($candidate, $baseline, 'why-us', ['score_accuracy' => 5, 'rated_at' => now()]);
    comparisonResultOf($candidate, $baseline, 'batch-jobs');
    comparisonResultOf($candidate, $batch, 'why-us', ['prompt_version' => 5, 'score_accuracy' => 4, 'rated_at' => now(), 'checks' => ['over_limit' => true, 'banned_found' => ['passionate'], 'missing' => [], 'corrected_claims' => 2]]);
    comparisonResultOf($candidate, $batch, 'batch-jobs', ['prompt_version' => 5, 'status' => 'invalid_output', 'checks' => []]);

    $this->artisan('app:compare-llm', ['--report' => $batch, '--baseline' => $baseline])
        ->expectsOutputToContain('ollama: fewer usable outputs (0.5 vs 1)')
        ->expectsOutputToContain('ollama: over_limit 1 vs 0')
        ->expectsOutputToContain('ollama: banned 1 vs 0')
        ->expectsOutputToContain('ollama: corrected_claims 2 vs 0')
        ->expectsOutputToContain('ollama: accuracy score 4 vs 5')
        ->assertFailed();
});

test('only samples run with the same inputs are compared, and an unchanged batch passes', function () {
    $candidate = candidateWithTwoFactor();
    $baseline = (string) Str::ulid();
    $batch = (string) Str::ulid();
    comparisonResultOf($candidate, $baseline, 'why-us');
    comparisonResultOf($candidate, $baseline, 'german');
    comparisonResultOf($candidate, $baseline, 'go-gap', ['checks' => ['over_limit' => false, 'banned_found' => [], 'missing' => [], 'corrected_claims' => 0]]);
    comparisonResultOf($candidate, $batch, 'why-us', ['score_accuracy' => 4, 'rated_at' => now()]);
    // The sample's inputs changed since the baseline: its worse result is not a regression.
    comparisonResultOf($candidate, $batch, 'go-gap', ['input_hash' => hash('sha256', 'go-gap v2'), 'checks' => ['over_limit' => true, 'banned_found' => ['15 years'], 'missing' => [], 'corrected_claims' => 3]]);

    $this->artisan('app:compare-llm', ['--report' => $batch, '--baseline' => $baseline])
        ->expectsOutputToContain('Not compared (inputs changed or missing from one batch): german, go-gap')
        ->expectsOutputToContain('No regressions against')
        ->assertSuccessful();
});

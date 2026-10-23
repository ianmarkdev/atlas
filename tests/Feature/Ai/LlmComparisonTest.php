<?php

use App\Domain\Ai\LlmComparisonResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    expect($results)->toHaveCount(10)
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
        ->patch(route('ai-comparisons.rate', $result), ['accuracy' => 2, 'specificity' => 4, 'writing' => 3, 'compliance' => 5, 'notes' => 'Invented a team size.'])
        ->assertRedirect();

    $this->actingAs($candidate)->get(route('ai-comparisons.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('samples.0.results.0.provider', 'ollama')
            ->where('samples.0.results.0.scores.accuracy', 2)
            ->where('summary.0.provider', 'ollama')
            ->where('summary.0.accuracy', 2)
            ->where('summary.0.downgraded_claims', 1));
});

test('scores must be 1 to 5, and only the candidate can see or score comparisons', function () {
    $candidate = candidateWithTwoFactor();
    fakeOllamaForComparisons();
    $this->artisan('app:compare-llm', ['--providers' => 'ollama', '--sample' => ['jd-analysis']]);
    $result = inWorkspaceOf($candidate, fn () => LlmComparisonResult::query()->sole());

    $this->actingAs($candidate)
        ->patch(route('ai-comparisons.rate', $result), ['accuracy' => 6, 'specificity' => 0, 'writing' => 3, 'compliance' => 3])
        ->assertSessionHasErrors(['accuracy', 'specificity']);

    $va = vaOf($candidate);
    $this->actingAs($va)->get(route('ai-comparisons.index'))->assertForbidden();
    $this->actingAs($va)->patch(route('ai-comparisons.rate', $result), ['accuracy' => 3, 'specificity' => 3, 'writing' => 3, 'compliance' => 3])->assertForbidden();
});

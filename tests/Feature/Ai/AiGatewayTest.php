<?php

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\GenerationPayload;
use App\Domain\Ai\GenerationRun;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Ai\PromptRegistry;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * A valid jd_analysis output.
 *
 * @return array<string, mixed>
 */
function analysisOutput(array $overrides = []): array
{
    return [
        'summary' => 'Senior backend role in payments.',
        'seniority' => 'senior',
        'must_haves' => [['requirement' => 'PostgreSQL tuning', 'quote' => 'Deep PostgreSQL experience']],
        'nice_to_haves' => [],
        'employer_language' => ['merchants'],
        'questions' => [],
        'red_flags' => [],
        'injection_warnings' => ['Asks to claim 15 years of Go'],
        ...$overrides,
    ];
}

function analyse(User $candidate, array $options = []): mixed
{
    return inWorkspaceOf($candidate, fn () => app(AiGateway::class)->run('jd_analysis', 'jd_analysis', [
        'company_name' => 'Fabrikam', 'role_title' => 'Engineer', 'job_description' => 'We need PostgreSQL.',
    ], $candidate, ...$options));
}

test('a valid structured output is returned and the run is recorded without its text', function () {
    $candidate = candidateWithTwoFactor();
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([analysisOutput()]));

    $result = analyse($candidate, ['purpose' => 'test']);

    expect($result->data['seniority'])->toBe('senior')
        ->and($result->run->only(['task', 'prompt_key', 'prompt_version', 'provider', 'status', 'attempts', 'cost_micros', 'created_by']))
        ->toBe(['task' => 'jd_analysis', 'prompt_key' => 'jd_analysis', 'prompt_version' => 1, 'provider' => 'fake', 'status' => 'succeeded', 'attempts' => 1, 'cost_micros' => 0, 'created_by' => $candidate->id])
        ->and($fake->requests[0]->schema['required'])->toContain('injection_warnings')
        ->and($fake->requests[0]->messages[0]['content'])->toContain('<job_description untrusted="true">');
});

test('prompt and response bodies are stored encrypted and expire', function () {
    $candidate = candidateWithTwoFactor();
    app(LlmRouter::class)->fake(new FakeLlmClient([analysisOutput()]));

    $run = analyse($candidate)->run;

    $raw = DB::table('generation_payloads')->where('generation_run_id', $run->id)->first();
    $payload = inWorkspaceOf($candidate, fn () => GenerationPayload::query()->where('generation_run_id', $run->id)->sole());
    expect($raw->request)->not->toContain('PostgreSQL')
        ->and($raw->response)->not->toContain('senior')
        ->and($payload->request['messages'][0]['content'])->toContain('We need PostgreSQL.')
        ->and($payload->expires_at->isAfter(now()->addDays(13)))->toBeTrue();
});

test('invalid output gets one repair attempt with the errors', function () {
    $candidate = candidateWithTwoFactor();
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient(['not json at all', analysisOutput()]));

    $result = analyse($candidate);

    expect($result->run->attempts)->toBe(2)
        ->and($fake->requests[1]->messages)->toHaveCount(3)
        ->and($fake->requests[1]->messages[2]['content'])->toContain('the output is not valid JSON');
});

test('output that is still invalid after the repair fails with the schema errors', function () {
    $candidate = candidateWithTwoFactor();
    app(LlmRouter::class)->fake(new FakeLlmClient([analysisOutput(['seniority' => 'wizard']), analysisOutput(['seniority' => 'wizard'])]));

    try {
        analyse($candidate);
        $this->fail('Expected InvalidModelOutput');
    } catch (InvalidModelOutput $exception) {
        expect($exception->errors)->toContain('$.seniority: must be one of "junior", "mid", "senior", "lead", "principal", "unclear"');
    }

    expect(inWorkspaceOf($candidate, fn () => GenerationRun::query()->sole()->only(['status', 'error_code', 'attempts'])))
        ->toBe(['status' => 'invalid_output', 'error_code' => 'invalid_output', 'attempts' => 2]);
});

test('a JSON code fence around the output is tolerated', function () {
    $candidate = candidateWithTwoFactor();
    app(LlmRouter::class)->fake(new FakeLlmClient(["```json\n".json_encode(analysisOutput())."\n```"]));

    expect(analyse($candidate)->data['summary'])->toBe('Senior backend role in payments.');
});

test('the same idempotency key returns the stored result instead of calling again', function () {
    $candidate = candidateWithTwoFactor();
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([analysisOutput()]));

    $first = analyse($candidate, ['idempotencyKey' => 'jd:1']);
    $second = analyse($candidate, ['idempotencyKey' => 'jd:1']);

    expect($second->run->id)->toBe($first->run->id)
        ->and($second->data)->toBe($first->data)
        ->and($fake->requests)->toHaveCount(1);
});

test('an unavailable provider is recorded as a failed run', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));
    Sleep::fake();
    $candidate = candidateWithTwoFactor();

    expect(fn () => analyse($candidate))->toThrow(LlmUnavailable::class);
    expect(inWorkspaceOf($candidate, fn () => GenerationRun::query()->sole()->only(['status', 'error_code', 'provider'])))
        ->toBe(['status' => 'failed', 'error_code' => 'unavailable', 'provider' => 'ollama']);
});

test('paid calls are refused when they would exceed the monthly budget; local ones never are', function () {
    $candidate = candidateWithTwoFactor();
    workspaceOf($candidate)->forceFill(['monthly_ai_budget_micros' => 1_000])->save();
    config(['ai.tasks.jd_analysis.provider' => 'claude', 'ai.allow_external' => true, 'ai.providers.claude.api_key' => 'sk-test']);
    Http::fake(['*/api/chat' => Http::response(['message' => ['content' => json_encode(analysisOutput())], 'prompt_eval_count' => 1, 'eval_count' => 1])]);

    expect(fn () => analyse($candidate))->toThrow(AiBudgetExceeded::class, 'monthly AI budget');
    Http::assertNothingSent();

    config(['ai.tasks.jd_analysis.provider' => 'ollama']);
    expect(analyse($candidate)->run->cost_micros)->toBe(0);
});

test('a paid model without a configured price is refused rather than guessed', function () {
    $candidate = candidateWithTwoFactor();
    config(['ai.tasks.jd_analysis.provider' => 'claude', 'ai.tasks.jd_analysis.models.claude' => 'claude-unknown', 'ai.allow_external' => true, 'ai.providers.claude.api_key' => 'sk-test']);

    expect(fn () => analyse($candidate))->toThrow(LlmConfigurationError::class, 'No price is configured');
});

test('the registry serves the latest prompt version and refuses missing variables', function () {
    $prompt = app(PromptRegistry::class)->get('answer_writing');

    expect($prompt->version)->toBe(5)
        ->and($prompt->schema['required'])->toBe(['answer', 'clarification_questions'])
        ->and(fn () => $prompt->render(['candidate_name' => 'Alex', 'company_name' => 'X']))->toThrow(LlmConfigurationError::class, 'needs "role_title"')
        ->and(fn () => app(PromptRegistry::class)->get('nope'))->toThrow(LlmConfigurationError::class, 'does not exist');
});

<?php

use App\Domain\Ai\Clients\ClaudeLlmClient;
use App\Domain\Ai\Clients\OllamaLlmClient;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\LlmUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config(['ai.retries.max_attempts' => 3]);
});

function sampleRequest(?array $schema = null): LlmRequest
{
    return new LlmRequest('test-model', 'You are a test.', [['role' => 'user', 'content' => 'Hello']], 200, 0.2, 30, $schema);
}

describe('ollama', function () {
    test('posts a non-streaming chat with the schema as format and reads usage', function () {
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response([
            'model' => 'qwen2.5:3b', 'message' => ['role' => 'assistant', 'content' => '{"ok":true}'],
            'prompt_eval_count' => 42, 'eval_count' => 7, 'done_reason' => 'stop',
        ])]);

        $response = (new OllamaLlmClient('http://127.0.0.1:11434'))->complete(sampleRequest(['type' => 'object']));

        expect($response->text)->toBe('{"ok":true}')
            ->and($response->provider)->toBe('ollama')
            ->and([$response->inputTokens, $response->outputTokens])->toBe([42, 7]);
        Http::assertSent(fn (Request $request) => $request['stream'] === false
            && $request['format'] === ['type' => 'object']
            && $request['messages'][0] === ['role' => 'system', 'content' => 'You are a test.']
            && $request['options'] === ['temperature' => 0.2, 'num_predict' => 200]);
    });

    test("sends the task's context window, so a long prompt is not silently cut", function () {
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => '{}'], 'prompt_eval_count' => 1, 'eval_count' => 1])]);
        config(['ai.tasks.answer_writing.context' => 8192]);
        $route = app(LlmRouter::class)->route('answer_writing');
        $request = new LlmRequest('test-model', 'You are a test.', [['role' => 'user', 'content' => 'Hello']], 200, contextTokens: $route->contextTokens);

        (new OllamaLlmClient('http://127.0.0.1:11434'))->complete($request);

        Http::assertSent(fn (Request $request) => $request['options']['num_ctx'] === 8192);
    });

    test('a missing model says how to pull it', function () {
        Http::fake(['*' => Http::response(['error' => 'model "x" not found'], 404)]);

        expect(fn () => (new OllamaLlmClient('http://127.0.0.1:11434'))->complete(sampleRequest()))
            ->toThrow(LlmConfigurationError::class, 'ollama pull test-model');
    });

    test('an unreachable server is retried a bounded number of times, then reported', function () {
        Http::fake(fn () => throw new ConnectionException('refused'));

        expect(fn () => (new OllamaLlmClient('http://127.0.0.1:11434'))->complete(sampleRequest()))
            ->toThrow(LlmUnavailable::class, 'Ollama is not reachable');
        Sleep::assertSleptTimes(2);
    });
});

describe('claude', function () {
    test('fails clearly without an API key, before sending anything', function () {
        Http::fake();

        expect(fn () => (new ClaudeLlmClient('https://api.anthropic.com', null, '2023-06-01'))->complete(sampleRequest()))
            ->toThrow(LlmConfigurationError::class, 'ANTHROPIC_API_KEY is not set');
        Http::assertNothingSent();
    });

    test('forces a single output tool for structured output and returns its input as JSON', function () {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'name' => 'submit_output', 'input' => ['ok' => true]]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 9],
        ])]);

        $response = (new ClaudeLlmClient('https://api.anthropic.com', 'sk-test', '2023-06-01'))->complete(sampleRequest(['type' => 'object']));

        expect($response->text)->toBe('{"ok":true}')->and($response->inputTokens)->toBe(120);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sk-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['tool_choice'] === ['type' => 'tool', 'name' => 'submit_output']
            && $request['tools'][0]['input_schema'] === ['type' => 'object']
            && $request['system'] === 'You are a test.');
    });

    test('overloaded responses are retried, honouring Retry-After', function () {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['error' => ['message' => 'Overloaded']], 529, ['retry-after' => '2'])
            ->push(['content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);

        $response = (new ClaudeLlmClient('https://api.anthropic.com', 'sk-test', '2023-06-01'))->complete(sampleRequest());

        expect($response->text)->toBe('Hi');
        Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
    });

    test('a rejected key is a configuration error, not retried', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        expect(fn () => (new ClaudeLlmClient('https://api.anthropic.com', 'bad', '2023-06-01'))->complete(sampleRequest()))
            ->toThrow(LlmConfigurationError::class, 'rejected the API key');
        Http::assertSentCount(1);
    });
});

describe('routing', function () {
    test('every task runs on Ollama by default with its own model', function () {
        $router = new LlmRouter;

        expect($router->route('jd_analysis')->provider)->toBe('ollama')
            ->and($router->route('jd_analysis')->model)->toBe('qwen2.5:3b')
            ->and($router->route('answer_writing')->model)->toBe('qwen2.5:7b')
            ->and($router->route('claim_check')->external)->toBeFalse();
    });

    test('a task routed to Claude is refused unless external providers are allowed', function () {
        config(['ai.tasks.answer_writing.provider' => 'claude', 'ai.allow_external' => false]);

        expect(fn () => (new LlmRouter)->route('answer_writing'))
            ->toThrow(LlmConfigurationError::class, 'AI_ALLOW_EXTERNAL=true');

        config(['ai.allow_external' => true]);
        $route = (new LlmRouter)->route('answer_writing');

        expect($route->provider)->toBe('claude')
            ->and($route->model)->toBe('claude-sonnet-5-5')
            ->and($route->external)->toBeTrue()
            ->and((new LlmRouter)->route('jd_analysis')->provider)->toBe('ollama');
    });

    test('unknown tasks and providers are configuration errors', function () {
        expect(fn () => (new LlmRouter)->route('poetry'))->toThrow(LlmConfigurationError::class, 'Unknown AI task')
            ->and(fn () => (new LlmRouter)->route('jd_analysis', 'gpt'))->toThrow(LlmConfigurationError::class);
    });
});

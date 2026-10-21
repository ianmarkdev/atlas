<?php

namespace App\Domain\Ai\Clients;

use App\Domain\Ai\LlmClient;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmResponse;
use Closure;
use RuntimeException;

/**
 * For tests: returns queued responses (strings, arrays encoded as JSON, or closures receiving
 * the request) in order, and records every request it got.
 */
class FakeLlmClient implements LlmClient
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    /**
     * @param  list<string|array<mixed>|Closure(LlmRequest): (string|array<mixed>)>  $responses
     */
    public function __construct(private array $responses = [], private string $providerName = 'fake') {}

    /**
     * @param  string|array<mixed>|Closure(LlmRequest): (string|array<mixed>)  ...$responses
     */
    public function push(string|array|Closure ...$responses): self
    {
        array_push($this->responses, ...array_values($responses));

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses) ?? throw new RuntimeException('FakeLlmClient has no response queued for this request.');
        $value = $next instanceof Closure ? $next($request) : $next;
        $text = is_array($value) ? (string) json_encode($value) : $value;

        return new LlmResponse($text, $this->providerName, $request->model, 100, max(1, intdiv(strlen($text), 4)), 5, 'stop');
    }

    public function provider(): string
    {
        return $this->providerName;
    }
}

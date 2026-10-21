<?php

namespace App\Domain\Ai;

/**
 * One text-generation backend (local Ollama, Claude, or a fake for tests). Clients only talk
 * to the provider; validation, budgets and recording are AiGateway's job.
 */
interface LlmClient
{
    /**
     * @throws LlmUnavailable when the provider cannot be reached or keeps failing
     * @throws LlmConfigurationError when the client is not set up (e.g. no API key)
     */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * The provider name used in config and records ("ollama", "claude", "fake").
     */
    public function provider(): string;
}

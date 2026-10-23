<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\ClaimGroundingValidator;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Applications\AiAnswerService;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Applications\EvidencePackBuilder;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Label the claims of an AI draft written without them (`ai.answer_claims = separate`): the
 * claim_check prompt splits the text into claims against the draft's own evidence pack, and the
 * server checks every label as for any AI claim (ClaimGroundingValidator).
 *
 * Queued behind the drafts already waiting, so "Draft all" writes every answer with the large
 * model first and then labels them all with the small one, swapping models only twice.
 */
class CheckAnswerClaims implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $workspaceId, public int $versionId)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(AiGateway $gateway, ClaimGroundingValidator $grounding, AiAnswerService $answers): void
    {
        $version = AnswerDraftVersion::query()->find($this->versionId);

        if ($version === null || $version->author_type !== 'ai' || $version->grounding !== null) {
            return;
        }

        $pack = $version->evidence_pack ?? [];

        try {
            $result = $gateway->run('claim_check', 'claim_check', [
                'evidence' => EvidencePackBuilder::render($pack),
                'draft' => $version->body,
            ], purpose: 'grounding_check', idempotencyKey: "claim-check:{$version->id}");
        } catch (InvalidModelOutput) {
            $answers->failClaimCheck($version, __('its answer was not usable'));

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            $answers->failClaimCheck($version, $exception->getMessage());

            return;
        }

        $answers->storeClaims($version, $grounding->check(
            ClaimGroundingValidator::distinct((array) ($result->data['claims'] ?? [])),
            array_map(fn (array $item): array => ['status' => $item['status'], 'text' => $item['text']], $pack),
        ));
    }
}

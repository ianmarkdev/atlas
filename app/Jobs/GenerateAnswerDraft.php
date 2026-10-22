<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\ClaimGroundingValidator;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Applications\AiAnswerService;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\EvidencePackBuilder;
use App\Domain\Knowledge\CandidateProfile;
use App\Jobs\Middleware\WithWorkspaceContext;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Write an answer or cover letter with AI (M8): build the evidence pack, run the answer_writing
 * prompt through AiGateway, have the server check every claim label against that evidence, and
 * store the result as a new version of the answer. Failures are stored on the draft.
 */
class GenerateAnswerDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A 7B model partly on the CPU can take a few minutes for a cover letter. */
    public int $timeout = 900;

    public function __construct(public int $workspaceId, public int $questionId)
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

    public function handle(AiGateway $gateway, EvidencePackBuilder $evidence, ClaimGroundingValidator $grounding, AiAnswerService $answers): void
    {
        $question = ApplicationQuestion::query()->with('application.posting')->find($this->questionId);
        $draft = $question === null ? null : AnswerDraft::query()->where('application_question_id', $question->id)->first();

        if ($question === null || $draft === null || $draft->generation_status !== 'queued') {
            return;
        }

        $draft->forceFill(['generation_status' => 'running'])->save();
        $application = $question->application;
        $requester = $draft->generation_requested_by === null ? null : User::query()->find($draft->generation_requested_by);
        $built = $evidence->build($application, $question);
        $pack = $built['pack'];
        $instruction = $draft->generation_instruction;

        try {
            $result = $gateway->run('answer_writing', 'answer_writing', [
                'candidate_name' => CandidateProfile::current()->display_name,
                'company_name' => $application->companyLabel(),
                'role_title' => $application->role_title,
                'job_description' => $application->jobDescription() ?? '(no job description pasted)',
                'evidence' => EvidencePackBuilder::render($pack),
                'storyline' => $built['storyline'],
                'instruction' => $instruction ?? '(none)',
                'question' => $question->isCoverLetter() ? __('Write a cover letter for this role, addressed to the hiring team.') : $question->prompt_text,
                'limit' => self::limitOf($question),
            ], $requester, purpose: 'answer_generation', idempotencyKey: "answer:{$question->id}:".$draft->generation_requested_at?->getTimestamp());
        } catch (InvalidModelOutput $exception) {
            $answers->fail($draft, __("The AI's answer was not usable (invalid structure), even after a repair attempt. Try again, or use a larger model."));

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            $answers->fail($draft, $exception->getMessage());

            return;
        }

        $data = $result->data ?? [];
        $claims = [];

        foreach ((array) ($data['segments'] ?? []) as $segment) {
            foreach ((array) ($segment['claims'] ?? []) as $claim) {
                // Small models repeat claims across segments; check each statement once.
                $key = mb_strtolower(trim((string) ($claim['text'] ?? '')));

                if ($key === '' || isset($claims[$key])) {
                    continue;
                }

                $claims[$key] = [
                    'text' => (string) ($claim['text'] ?? ''),
                    'classification' => (string) ($claim['classification'] ?? ''),
                    'evidence_ids' => array_values(array_map(strval(...), (array) ($claim['evidence_ids'] ?? []))),
                ];
            }
        }

        $answers->storeDraft(
            $draft,
            self::withoutEvidenceIds((string) ($data['answer'] ?? '')),
            $result->run,
            $pack,
            $grounding->check(array_values($claims), array_map(fn (array $item): array => ['status' => $item['status'], 'text' => $item['text']], $pack)),
            array_values(array_map(fn (mixed $item): array => [
                'question' => (string) ($item['question'] ?? ''),
                'why' => (string) ($item['why'] ?? ''),
            ], array_filter((array) ($data['clarification_questions'] ?? []), is_array(...)))),
            $requester,
            $instruction,
            $built['resume_version_id'],
        );
    }

    /**
     * The answer without evidence ids the model copied into the text ("(E1, E2)", "[E3]"):
     * they belong in the claim list, not in what the employer reads.
     */
    private static function withoutEvidenceIds(string $answer): string
    {
        $clean = (string) preg_replace('/\s*[\(\[]\s*E\d+(?:\s*[,;]\s*E\d+)*\s*[\)\]]/u', '', $answer);

        return trim((string) preg_replace('/ +([.,;:])/u', '$1', $clean));
    }

    private static function limitOf(ApplicationQuestion $question): string
    {
        return match (true) {
            $question->word_limit !== null => __('at most :n words', ['n' => $question->word_limit]),
            $question->char_limit !== null => __('at most :n characters', ['n' => $question->char_limit]),
            $question->isCoverLetter() => __('about 250 to 350 words, in three or four short paragraphs'),
            default => __('concise: about 100 to 200 words'),
        };
    }
}

<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\ClaimGroundingValidator;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Ai\PromptBudget;
use App\Domain\Ai\PromptRegistry;
use App\Domain\Applications\AiAnswerService;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\EvidencePackBuilder;
use App\Domain\Applications\ParagraphRedraft;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\WritingPreference;
use App\Jobs\Middleware\WithWorkspaceContext;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Write an answer or cover letter with AI (M8): build the evidence pack, run the answer_writing
 * prompt (with the writing preferences that apply to the requester, M10) through AiGateway,
 * have the server check every claim label against that evidence, and store the result as a new
 * version of the answer. Failures are stored on the draft. The prompt is kept within the
 * model's window (PromptBudget): evidence is chosen to fit, then the job description is shortened.
 *
 * With `ai.answer_claims = separate` (the default) the writer writes only the text and
 * CheckAnswerClaims labels its claims afterwards; until then the draft cannot be approved.
 *
 * A paragraph redraft runs the answer_paragraph prompt over the version it was requested from
 * and puts the new paragraph back in place (AiAnswerService::storeParagraph).
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

    public function handle(AiGateway $gateway, EvidencePackBuilder $evidence, ClaimGroundingValidator $grounding, AiAnswerService $answers, LlmRouter $router, PromptRegistry $prompts, PromptBudget $budget): void
    {
        $question = ApplicationQuestion::query()->with('application.posting')->find($this->questionId);
        $draft = $question === null ? null : AnswerDraft::query()->where('application_question_id', $question->id)->first();

        if ($question === null || $draft === null || $draft->generation_status !== 'queued') {
            return;
        }

        $draft->forceFill(['generation_status' => 'running'])->save();
        $application = $question->application;
        $requester = $draft->generation_requested_by === null ? null : User::query()->find($draft->generation_requested_by);
        $instruction = $draft->generation_instruction;
        $paragraph = $draft->generation_paragraph;
        $base = $paragraph === null ? null : AnswerDraftVersion::query()->find($draft->generation_base_version_id);

        if ($paragraph !== null && $base === null) {
            $answers->fail($draft, __('The version to redraft a paragraph of no longer exists. Try again.'));

            return;
        }

        $variables = [
            'candidate_name' => CandidateProfile::current()->display_name,
            'company_name' => $application->companyLabel(),
            'role_title' => $application->role_title,
            'job_description' => '',
            'evidence' => '',
            'writing_rules' => WritingPreference::render(WritingPreference::query()->appliesTo($requester)->oldest()->get()),
            'instruction' => $instruction ?? '(none)',
            'question' => $question->isCoverLetter() ? __('Write a cover letter for this role, addressed to the hiring team.') : $question->prompt_text,
            'limit' => self::limitOf($question),
        ];
        $variables += $base === null
            ? ['storyline' => '']
            : ParagraphRedraft::context($base->body, $paragraph);
        $requestKey = "answer:{$question->id}:".$draft->generation_requested_at?->getTimestamp().($paragraph === null ? '' : ":p{$paragraph}");
        // A whole draft's claims are labelled afterwards by CheckAnswerClaims (faster on a small GPU),
        // unless configured to have the writer label them (answer_writing v4).
        $checkSeparately = $base === null && config('ai.answer_claims') === 'separate';
        $promptKey = $base === null ? 'answer_writing' : 'answer_paragraph';
        $promptVersion = $base === null && ! $checkSeparately ? 4 : null;

        try {
            // The evidence and the job description share what the rest of the prompt leaves of the
            // model's window; the job description is sure of a third of it and is shortened last.
            $room = $budget->remaining($router->route('answer_writing'), $prompts->get($promptKey, $promptVersion), $variables);
            $jobDescription = $application->jobDescription() ?? '(no job description pasted)';

            // A paragraph of an AI draft is rewritten from that draft's evidence, so the claims kept
            // from the other paragraphs still point at the same items.
            $built = $base !== null && $base->author_type === 'ai' && ($base->evidence_pack ?? []) !== []
                ? ['pack' => $base->evidence_pack, 'storyline' => '(none)', 'resume_version_id' => $base->resume_version_id]
                : $evidence->build($application, $question, $room - min(mb_strlen($jobDescription), intdiv($room, 3)));
            $pack = $built['pack'];
            $variables['evidence'] = EvidencePackBuilder::render($pack);

            if ($base === null) {
                $variables['storyline'] = $built['storyline'];
            }

            $variables['job_description'] = PromptBudget::shorten(
                $jobDescription,
                $room - mb_strlen($variables['evidence']) - mb_strlen($base === null ? $variables['storyline'] : ''),
                __('[… the rest of the job description was left out to fit the model\'s window]'),
            );

            $result = $gateway->run(
                'answer_writing',
                $promptKey,
                $variables,
                $requester,
                purpose: 'answer_generation',
                idempotencyKey: $requestKey,
                promptVersion: $promptVersion,
            );
        } catch (InvalidModelOutput $exception) {
            $answers->fail($draft, __("The AI's answer was not usable (invalid structure), even after a repair attempt. Try again, or use a larger model."));

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            $answers->fail($draft, $exception->getMessage());

            return;
        }

        $data = $result->data ?? [];
        $claims = (array) ($data['claims'] ?? []);

        foreach ((array) ($data['segments'] ?? []) as $segment) {
            array_push($claims, ...array_values((array) ($segment['claims'] ?? [])));
        }

        $checked = $grounding->check(ClaimGroundingValidator::distinct($claims), array_map(fn (array $item): array => ['status' => $item['status'], 'text' => $item['text']], $pack));
        $clarifications = array_values(array_map(fn (mixed $item): array => [
            'question' => (string) ($item['question'] ?? ''),
            'why' => (string) ($item['why'] ?? ''),
        ], array_filter((array) ($data['clarification_questions'] ?? []), is_array(...))));

        if ($base !== null) {
            $text = ParagraphRedraft::clean(self::withoutEvidenceIds((string) ($data['paragraph'] ?? '')));

            if ($text === '' || ParagraphRedraft::isUnchanged(ParagraphRedraft::context($base->body, $paragraph)['current'], $text)) {
                $answers->fail($draft, __('The AI gave the paragraph back unchanged. Try again with an instruction, or with a larger model.'));

                return;
            }

            $answers->storeParagraph($draft, $base->id, $paragraph, $text, $result->run, $pack, $checked, $clarifications, $requester, $instruction);

            return;
        }

        $answers->storeDraft($draft, self::withoutEvidenceIds((string) ($data['answer'] ?? '')), $result->run, $pack, $checkSeparately ? null : $checked, $clarifications, $requester, $instruction, $built['resume_version_id']);
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

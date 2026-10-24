<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiQueue;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Ai\PromptBudget;
use App\Domain\Ai\PromptRegistry;
use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\ApplicationResumeService;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\ResumeTailor;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Resumes\ResumeDocumentValidator;
use App\Domain\Resumes\ResumeFacts;
use App\Http\Controllers\Profile\ContactController;
use App\Http\Controllers\Profile\ResumeController;
use App\Jobs\Middleware\WithWorkspaceContext;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

/**
 * Tailor an application's resume with AI (M8, docs/04 §4.10): show the model the resume and the
 * job brief, let ResumeTailor keep only the checked parts of its proposal, and store the result
 * as a new version written by `ai`. Failures are stored on the resume. The prompt is kept within
 * the model's window (PromptBudget): the job description is shortened to what the resume leaves.
 */
class TailorApplicationResume implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A whole resume from a 7B model partly on the CPU can take several minutes. */
    public int $timeout = 900;

    public function __construct(public int $workspaceId, public int $resumeId)
    {
        $this->afterCommit();
        $this->onQueue(AiQueue::for('resume_tailoring'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(AiGateway $gateway, ResumeTailor $tailor, ResumeDocumentValidator $validator, ApplicationResumeService $resumes, LlmRouter $router, PromptRegistry $prompts, PromptBudget $budget): void
    {
        $resume = ApplicationResume::query()->with(['currentVersion', 'application.posting'])->find($this->resumeId);

        if ($resume === null || $resume->generation_status !== 'queued' || $resume->current_version_id === null) {
            return;
        }

        $resume->forceFill(['generation_status' => 'running'])->save();
        $application = $resume->application;
        $requester = $resume->generation_requested_by === null ? null : User::query()->find($resume->generation_requested_by);
        $library = ResumeController::editorLibrary();
        $document = $resume->document();
        $presented = $tailor->present($document, $library);
        $analysis = $application->analyses()->where('status', 'succeeded')->first();

        $variables = [
            'company_name' => $application->companyLabel(),
            'role_title' => $application->role_title,
            'job_description' => '',
            'brief' => self::briefOf($analysis),
            'resume' => $presented['text'],
        ];

        try {
            // The whole resume must go in (the model rewrites it); the job description gets what is
            // left and is shortened — the brief already lists its requirements.
            $room = $budget->remaining($router->route('resume_tailoring'), $prompts->get('resume_tailoring'), $variables);

            if ($room === 0) {
                $resumes->failTailoring($resume, __('The resume is too long for the AI model\'s window. Shorten it, or use a model with a larger window.'));

                return;
            }

            $variables['job_description'] = PromptBudget::shorten(
                $application->jobDescription() ?? '(no job description pasted)',
                $room,
                __('[… the rest of the job description was left out to fit the model\'s window]'),
            );

            $result = $gateway->run('resume_tailoring', 'resume_tailoring', $variables, $requester, purpose: 'resume_tailoring', idempotencyKey: "resume:{$resume->id}:".$resume->generation_requested_at?->getTimestamp());
        } catch (InvalidModelOutput) {
            $resumes->failTailoring($resume, __("The AI's proposal was not usable (invalid structure), even after a repair attempt. Try again, or use a larger model."));

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            $resumes->failTailoring($resume, $exception->getMessage());

            return;
        }

        $applied = $tailor->apply($document, $result->data ?? [], $presented['map'], $requester?->isCandidate() ?? false);

        try {
            $tailored = $validator->validate($applied['document']);
        } catch (ValidationException $exception) {
            $resumes->failTailoring($resume, __('The tailored resume did not pass the resume checks: :errors', ['errors' => implode(' ', $exception->validator->errors()->all())]));

            return;
        }

        $snapshot = ResumeFacts::snapshot($library, ContactController::contactOf(CandidateProfile::current()), $tailored);

        $resumes->storeTailored($resume, $resume->current_version_id, $tailored, $snapshot, $result->run, $applied['report'], $requester, [
            'job_posting_id' => $application->posting?->id,
            'job_analysis_id' => $analysis?->id,
        ]);
    }

    /**
     * The checked brief as short lines for the prompt, or a note that there is none.
     */
    private static function briefOf(?JobAnalysis $analysis): string
    {
        $brief = $analysis?->brief;

        if ($brief === null) {
            return '(the job description has not been analysed; work from the job description itself)';
        }

        $lines = ['Summary: '.($brief['summary'] ?? '')];

        foreach ((array) ($brief['must_haves'] ?? []) as $item) {
            $lines[] = '- Must have: '.($item['requirement'] ?? '');
        }

        foreach ((array) ($brief['nice_to_haves'] ?? []) as $item) {
            $lines[] = '- Nice to have: '.($item['requirement'] ?? '');
        }

        if (($brief['employer_language'] ?? []) !== []) {
            $lines[] = "Employer's words: ".implode(', ', array_map(strval(...), (array) $brief['employer_language']));
        }

        return implode("\n", $lines);
    }

    /**
     * The queue gave up on the job (the worker stopped, or it ran past its timeout), so the
     * handler never recorded an outcome. What is still waiting for this job must not stay so.
     */
    public function failed(): void
    {
        WithWorkspaceContext::run($this->workspaceId, function (): void {
            $resume = ApplicationResume::query()->find($this->resumeId);

            if ($resume !== null && in_array($resume->generation_status, ['queued', 'running'], true)) {
                app(ApplicationResumeService::class)->failTailoring($resume, __('Tailoring stopped unexpectedly. Try again.'));
            }
        });
    }
}

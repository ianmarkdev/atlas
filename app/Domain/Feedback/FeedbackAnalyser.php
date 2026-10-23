<?php

namespace App\Domain\Feedback;

use App\Domain\Ai\GenerationRun;
use App\Domain\Applications\EvidencePackBuilder;
use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\DuplicateClaimDetector;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalService;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Knowledge\WritingPreference;
use App\Domain\Knowledge\WritingPreferenceDrafts;
use App\Domain\Knowledge\WritingPreferenceKind;
use App\Domain\Knowledge\WritingPreferenceService;
use App\Domain\Shared\PlainText;
use App\Domain\Shared\WordDiff;

/**
 * Turns the AI's reading of an edit into proposals (M10, docs/04 §4.7), checking each item on
 * the server first:
 *
 * - a factual correction must use only numbers that are in the edited text; one aimed at an
 *   evidence item becomes a `revise_claim` of that verified bullet point, otherwise a
 *   `create_claim` — for the candidate to approve;
 * - a writing rule not already in place becomes a `create_writing_preference`: for the
 *   candidate, or for a VA alone when it is their personal habit. A rule the AI is unsure of is
 *   held until a second edit shows it again: the AI is shown the held rules and names the one a
 *   new rule repeats (`same_as`), however differently it is worded. The suggestion carries
 *   the edits it was seen in and the drafts it is for (only cover letters or only question
 *   answers when every one of those edits was of that kind);
 * - a reusable answer (not a cover letter, not already in the library) becomes a
 *   `create_library_answer`;
 * - a one-off is recorded and nothing else.
 *
 * Every item is kept in the event's analysis with its outcome.
 */
class FeedbackAnalyser
{
    /** Below this, a writing rule waits until another edit shows the same rule. */
    public const CONFIDENT = 0.7;

    /** Below this, a reusable answer is not offered. */
    public const REUSABLE = 0.5;

    /** At most this many held rules are shown to the AI, newest first. */
    public const HELD_RULES = 20;

    public function __construct(
        private ProposalService $proposals,
        private WritingPreferenceService $preferences,
        private DuplicateClaimDetector $duplicates,
    ) {}

    /**
     * The prompt variables for the feedback_analysis prompt.
     *
     * @return array<string, string>
     */
    public function variables(FeedbackEvent $event): array
    {
        $editor = $event->user;
        $question = $event->answerDraft->question;

        return [
            'question' => $question->isCoverLetter() ? __('Cover letter') : $question->prompt_text,
            'editor' => $editor === null || $editor->isCandidate() ? 'the candidate' : 'an assistant writing for the candidate',
            'original' => $event->fromVersion->body,
            'edited' => $event->toVersion->body,
            'changes' => WordDiff::render($event->diff),
            'evidence' => EvidencePackBuilder::render($event->fromVersion->evidence_pack ?? []),
            'writing_rules' => WritingPreference::render(WritingPreference::query()->appliesTo($editor)->get()),
            'held_rules' => self::renderHeldRules($this->heldRules($event)),
        ];
    }

    /**
     * The held rules as tagged lines for the prompt.
     *
     * @param  array<string, string>  $heldRules
     */
    public static function renderHeldRules(array $heldRules): string
    {
        if ($heldRules === []) {
            return '(none)';
        }

        $lines = [];

        foreach ($heldRules as $id => $rule) {
            $lines[] = sprintf('<rule id="%s">%s</rule>', $id, htmlspecialchars($rule, ENT_NOQUOTES));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $output  the model's validated output
     * @return list<array<string, mixed>> the items with their outcome (`proposed`, `held`, `skipped`, `recorded`) and reason
     */
    public function route(FeedbackEvent $event, array $output, GenerationRun $run): array
    {
        $results = [];

        foreach ((array) ($output['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            [$outcome, $reason] = match ($item['type'] ?? null) {
                'factual_correction' => $this->factualCorrection($event, $item, $run),
                'writing_preference' => $this->writingPreference($event, $item, $run, $results),
                'reusable_answer' => $this->reusableAnswer($event, $item, $run),
                default => ['recorded', null],
            };

            $results[] = [...$item, 'outcome' => $outcome, 'reason' => $reason];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{string, ?string}
     */
    private function factualCorrection(FeedbackEvent $event, array $item, GenerationRun $run): array
    {
        $statement = PlainText::normalize((string) ($item['statement'] ?? ''));
        $edited = $event->toVersion->body;

        if ($statement === '' || mb_strlen($statement) > 1000 || preg_match('/\[confirm:/iu', $statement) === 1) {
            return ['skipped', 'no usable statement'];
        }

        preg_match_all('/\d+(?:[.,]\d+)*/u', $statement, $numbers);

        foreach ($numbers[0] as $number) {
            if (! str_contains($edited, $number)) {
                return ['skipped', "the number {$number} is not in the edited text"];
            }
        }

        $claim = $this->claimOf($event, (string) ($item['evidence_id'] ?? ''));
        $from = $this->from($event, $run);

        if ($claim !== null && $claim->status === ClaimStatus::Verified) {
            if (mb_strtolower($claim->statement()) === mb_strtolower($statement)) {
                return ['skipped', 'the bullet point already says this'];
            }

            $proposal = $this->proposals->suggest('revise_claim', $claim, [
                'statement' => $statement,
                'kind' => $claim->kind->value,
                'change_reason' => $this->changeReason($event),
            ], $this->rationale($item), $from);

            return $proposal === null ? ['skipped', 'the bullet point already has a suggestion waiting'] : ['proposed', null];
        }

        if ($this->duplicates->exactDuplicate($event->answerDraft->question->application->candidate_profile_id, $statement) !== null
            || $this->pendingCreation('create_claim', 'statement', $statement)) {
            return ['skipped', 'already in the library or suggested'];
        }

        $this->proposals->suggest('create_claim', null, [
            'statement' => $statement,
            'kind' => ClaimKind::Achievement->value,
            // A correction of an unverified bullet point is about the same job or project.
            'subject' => ClaimSubject::key($claim?->subject),
        ], $this->rationale($item), $from);

        return ['proposed', null];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $earlier  items of this analysis already routed
     * @return array{string, ?string}
     */
    private function writingPreference(FeedbackEvent $event, array $item, GenerationRun $run, array $earlier): array
    {
        $rule = PlainText::normalize((string) ($item['rule'] ?? ''));

        if ($rule === '' || mb_strlen($rule) > 200) {
            return ['skipped', 'no usable rule'];
        }

        $editor = $event->user;
        $owner = $editor !== null && ! $editor->isCandidate() && ($item['personal'] ?? false) === true ? $editor : null;
        $normalized = WritingPreference::normalizeRule($rule);

        if ($this->preferences->exists($rule, $owner)
            || $this->pendingCreation('create_writing_preference', 'rule', $rule)
            || collect($earlier)->contains(fn (array $seen): bool => WritingPreference::normalizeRule((string) ($seen['rule'] ?? '')) === $normalized)) {
            return ['skipped', 'already a rule or suggested'];
        }

        $sameAs = (string) ($item['same_as'] ?? '');
        $earlierEventId = array_key_exists($sameAs, $this->heldRules($event))
            ? (int) substr($sameAs, 1, (int) strpos($sameAs, '.') - 1)
            : $this->earlierSighting($event, $normalized);

        if ((float) ($item['confidence'] ?? 0) < self::CONFIDENT && $earlierEventId === null) {
            return ['held', 'waiting to see the same rule in another edit'];
        }

        $this->proposals->suggest('create_writing_preference', null, [
            'rule' => $rule,
            'kind' => (WritingPreferenceKind::tryFrom((string) ($item['rule_kind'] ?? '')) ?? WritingPreferenceKind::Tone)->value,
            'scope' => $owner === null ? 'profile' : 'user',
            'drafts' => $this->draftsFor($event, $item, $earlierEventId)->value,
            'supported_by' => array_values(array_filter([$event->id, $earlierEventId])),
        ], $this->rationale($item), [...$this->from($event, $run), 'reviewer' => $owner]);

        return ['proposed', null];
    }

    /**
     * The drafts a learned rule is for: only cover letters or only question answers when the AI
     * says so and every edit it was seen in was of that kind; otherwise all.
     *
     * @param  array<string, mixed>  $item
     */
    private function draftsFor(FeedbackEvent $event, array $item, ?int $earlierEventId): WritingPreferenceDrafts
    {
        $here = WritingPreferenceDrafts::of($event->answerDraft->question);

        if (WritingPreferenceDrafts::tryFrom((string) ($item['drafts'] ?? '')) !== $here) {
            return WritingPreferenceDrafts::All;
        }

        if ($earlierEventId !== null) {
            $earlier = FeedbackEvent::query()->with('answerDraft.question')->find($earlierEventId)?->answerDraft?->question;

            if ($earlier === null || WritingPreferenceDrafts::of($earlier) !== $here) {
                return WritingPreferenceDrafts::All;
            }
        }

        return $here;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{string, ?string}
     */
    private function reusableAnswer(FeedbackEvent $event, array $item, GenerationRun $run): array
    {
        $question = $event->answerDraft->question;

        if ($question->isCoverLetter()) {
            return ['skipped', 'cover letters are written per job'];
        }

        if ((float) ($item['confidence'] ?? 0) < self::REUSABLE) {
            return ['skipped', 'not confident it is reusable'];
        }

        $normalized = mb_strtolower(trim($question->prompt_text));
        $inLibrary = AnswerLibraryEntry::query()
            ->where('status', '!=', AnswerStatus::Archived)
            ->whereRaw('lower(question) = ?', [$normalized])
            ->exists();

        if ($inLibrary || $this->pendingCreation('create_library_answer', 'question', $question->prompt_text)) {
            return ['skipped', 'this question is already in the answer library'];
        }

        $this->proposals->suggest('create_library_answer', null, [
            'question' => $question->prompt_text,
            'body' => $event->toVersion->body,
            'category' => (AnswerCategory::tryFrom((string) ($item['category'] ?? '')) ?? AnswerCategory::Other)->value,
        ], $this->rationale($item), $this->from($event, $run));

        return ['proposed', null];
    }

    /**
     * The bullet point behind an evidence item of the AI draft, if it was one.
     */
    private function claimOf(FeedbackEvent $event, string $evidenceId): ?Claim
    {
        $ref = (string) ($event->fromVersion->evidence_pack[$evidenceId]['ref'] ?? '');

        if (! str_starts_with($ref, 'claim:')) {
            return null;
        }

        return Claim::query()->where('ulid', substr($ref, 6))->with(['currentRevision', 'subject'])->first();
    }

    /**
     * Whether a pending creating proposal already carries the same value.
     */
    private function pendingCreation(string $type, string $field, string $value): bool
    {
        $normalized = WritingPreference::normalizeRule($value);

        return KnowledgeProposal::query()
            ->where('proposal_type', $type)
            ->where('status', ProposalStatus::Pending)
            ->get(['payload'])
            ->contains(fn (KnowledgeProposal $proposal): bool => WritingPreference::normalizeRule((string) ($proposal->payload[$field] ?? '')) === $normalized);
    }

    /**
     * The latest earlier edit that showed this rule in the same words, if any.
     */
    private function earlierSighting(FeedbackEvent $event, string $normalizedRule): ?int
    {
        return FeedbackEvent::query()
            ->whereKeyNot($event->id)
            ->where('status', 'analysed')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'analysis'])
            ->first(fn (FeedbackEvent $earlier): bool => collect($earlier->analysis['items'] ?? [])
                ->contains(fn (array $item): bool => ($item['type'] ?? null) === 'writing_preference'
                    && WritingPreference::normalizeRule((string) ($item['rule'] ?? '')) === $normalizedRule))
            ?->id;
    }

    /**
     * Rules held in earlier edits that no later edit has repeated yet, newest first, keyed by the
     * id the AI names in `same_as`: `H{event id}.{item index}`.
     *
     * @return array<string, string>
     */
    private function heldRules(FeedbackEvent $event): array
    {
        $earlierEvents = FeedbackEvent::query()
            ->whereKeyNot($event->id)
            ->where('status', 'analysed')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'analysis']);

        $repeated = $earlierEvents
            ->flatMap(fn (FeedbackEvent $earlier): array => $earlier->analysis['items'] ?? [])
            ->filter(fn (array $item): bool => ($item['outcome'] ?? null) !== 'held')
            ->pluck('same_as')
            ->filter()
            ->flip();

        $held = [];

        foreach ($earlierEvents as $earlier) {
            foreach ($earlier->analysis['items'] ?? [] as $index => $item) {
                $id = "H{$earlier->id}.{$index}";

                if (($item['type'] ?? null) === 'writing_preference' && ($item['outcome'] ?? null) === 'held' && ! $repeated->has($id)) {
                    $held[$id] = (string) ($item['rule'] ?? '');
                }
            }
        }

        return array_slice($held, 0, self::HELD_RULES, true);
    }

    /**
     * @return array{origin: string, origin_id: int, run_id: int}
     */
    private function from(FeedbackEvent $event, GenerationRun $run): array
    {
        return ['origin' => 'feedback', 'origin_id' => $event->id, 'run_id' => $run->id];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rationale(array $item): ?string
    {
        $rationale = PlainText::normalize((string) ($item['rationale'] ?? ''));

        return $rationale === '' ? null : mb_substr($rationale, 0, 300);
    }

    private function changeReason(FeedbackEvent $event): string
    {
        $application = $event->answerDraft->question->application;

        return __('Learned from an edit of an answer for :role at :company', ['role' => $application->role_title, 'company' => $application->companyLabel()]);
    }
}

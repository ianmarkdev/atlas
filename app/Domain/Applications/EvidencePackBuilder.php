<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalQuery;
use App\Domain\Retrieval\RetrievalUnit;

/**
 * The facts an AI answer may use (M8): the best evidence the latest analysis matched to each
 * must-have (two each) and nice-to-have (one each), plus a search on the question itself —
 * de-duplicated, capped, and numbered E1, E2, … for the prompt and the claim check.
 *
 * A cover letter is written from the tailored resume's storyline (docs/04 §4.10): the resume's
 * bullet points come first, in resume order, and the storyline lists each experience with the
 * evidence ids of its bullets.
 *
 * The best hits of the search on the question itself keep their place even when a job with many
 * requirements would fill the pack. A cover letter keeps fewer, for hits the resume and the
 * matches do not already hold: experience added after the resume was tailored or the job was
 * matched still reaches the letter. Items that do not fit `$maxCharacters` (as rendered for the
 * prompt) are left out, lowest priority first.
 *
 * An item is listed once per library item: a bullet point and a search hit for the same claim
 * keep the first wording (the resume's, for a cover letter).
 *
 * @phpstan-type EvidenceSource array{unit_id: int, source_type: string, source_id: int, source_revision_id: ?int, content_hash: string}
 * @phpstan-type Retrieval array{semantic: bool, semantic_unavailable_reason: ?string, embedding_model: ?array{id: int, provider: string, model: string}, settings: array<string, int|float>, sources: array<string, EvidenceSource>}
 */
class EvidencePackBuilder
{
    public const MAX_ITEMS = 15;

    /** Places kept for the search on a question (not a cover letter) itself. */
    public const QUESTION_SLOTS = 5;

    /** Places kept for the search on the role, for a cover letter. */
    public const COVER_LETTER_SLOTS = 3;

    /** Hits taken from the search on the question (or, for a cover letter, the role). */
    public const SEARCH_LIMIT = 6;

    public function __construct(private HybridSearch $search) {}

    /**
     * @return array{pack: array<string, array{ref: ?string, status: string, text: string}>, storyline: string, resume_version_id: ?int, job_analysis_id: ?int, retrieval: Retrieval}
     */
    public function build(JobApplication $application, ApplicationQuestion $question, int $maxCharacters = PHP_INT_MAX): array
    {
        $items = [];
        $resumeVersion = null;

        if ($question->isCoverLetter()) {
            [$resumeVersion, $items] = $this->resumeStory($application);
        }

        $analysis = $application->analyses()->where('status', 'succeeded')->first();

        foreach ((array) ($analysis->matches['requirements'] ?? []) as $requirement) {
            $take = ($requirement['kind'] ?? null) === 'must' ? 2 : 1;

            foreach (array_slice((array) ($requirement['evidence'] ?? []), 0, $take) as $evidence) {
                $items[] = $evidence;
            }
        }

        $query = $question->isCoverLetter()
            ? trim($application->role_title.'. '.trim($application->proposal_angle.' '.((string) ($analysis->brief['summary'] ?? ''))))
            : $question->prompt_text;
        $result = $this->search->search(new RetrievalQuery($query, $application->candidate_profile_id, self::SEARCH_LIMIT));

        // Every hit keeps the ref of its library item (`claim:…`, `project:…`, …): a correction learned
        // from an edit can find a bullet point (M10), and any answer can be traced to its sources.
        $refs = EvidenceMatcher::refsOf($result->hits);

        foreach ($result->hits as $index => $hit) {
            $items[] = ['ref' => $refs[$index], 'status' => $hit->unit->status, 'text' => $hit->unit->search_text, 'unit_type' => $hit->unit->unit_type, 'from_search' => true, 'source' => self::sourceOf($hit->unit)];
        }

        // Distinct usable items in order, each with the storyline groups it appeared under.
        $candidates = [];

        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            $status = (string) ($item['status'] ?? '');
            $key = $item['ref'] ?? mb_strtolower($text);

            if ($text === '' || ! in_array($status, ['verified', 'approved', 'proposed'], true)) {
                continue;
            }

            $candidates[$key] ??= ['ref' => $item['ref'] ?? null, 'status' => $status, 'text' => $text, 'from_search' => $item['from_search'] ?? false, 'source' => null, 'groups' => []];
            $candidates[$key]['source'] ??= $item['source'] ?? null;

            if (isset($item['group'])) {
                $candidates[$key]['groups'][] = $item['group'];
            }
        }

        $chosen = $this->choose($candidates, $question->isCoverLetter() ? self::COVER_LETTER_SLOTS : self::QUESTION_SLOTS, $maxCharacters);
        $pack = [];
        $sources = [];
        $groups = [];
        $number = 0;

        foreach ($candidates as $key => $candidate) {
            if (! isset($chosen[$key])) {
                continue;
            }

            $number++;
            $id = "E{$number}";
            $pack[$id] = ['ref' => $candidate['ref'], 'status' => $candidate['status'], 'text' => $candidate['text']];

            if ($candidate['source'] !== null) {
                $sources[$id] = $candidate['source'];
            }

            foreach ($candidate['groups'] as $group) {
                $groups[$group][] = $id;
            }
        }

        $storyline = [];

        foreach ($groups as $label => $ids) {
            $storyline[] = sprintf('%d. %s: %s', count($storyline) + 1, $label, implode(', ', array_unique($ids)));
        }

        return [
            'pack' => $pack,
            'storyline' => $storyline === [] ? '(no tailored resume: choose the strongest evidence for this job)' : implode("\n", $storyline),
            'resume_version_id' => $resumeVersion,
            'job_analysis_id' => $analysis?->id,
            'retrieval' => [
                'semantic' => $result->semantic,
                'semantic_unavailable_reason' => $result->semanticUnavailableReason,
                'embedding_model' => $result->embeddingModel === null ? null : [
                    'id' => $result->embeddingModel->id,
                    'provider' => $result->embeddingModel->provider,
                    'model' => $result->embeddingModel->model,
                ],
                'settings' => [
                    'limit' => self::SEARCH_LIMIT,
                    'candidates_per_signal' => (int) config('retrieval.candidates_per_signal'),
                    'rrf_k' => (int) config('retrieval.rrf_k'),
                    'min_similarity' => (float) config('retrieval.min_similarity'),
                    'fused_limit' => (int) config('retrieval.fused_limit'),
                ],
                'sources' => $sources,
            ],
        ];
    }

    /**
     * The indexed text a search hit was, so a later edit of the record can be told apart.
     *
     * @return EvidenceSource
     */
    private static function sourceOf(RetrievalUnit $unit): array
    {
        return [
            'unit_id' => $unit->id,
            'source_type' => $unit->source_type,
            'source_id' => $unit->source_id,
            'source_revision_id' => $unit->source_revision_id,
            'content_hash' => $unit->content_hash,
        ];
    }

    /**
     * The pack as tagged lines for the prompt.
     *
     * @param  array<string, array{ref: ?string, status: string, text: string}>  $pack
     */
    public static function render(array $pack): string
    {
        if ($pack === []) {
            return '(no evidence: the candidate has not added matching facts yet — use placeholders, do not invent)';
        }

        $lines = [];

        foreach ($pack as $id => $item) {
            $lines[] = self::renderItem($id, $item['status'], $item['text']);
        }

        return implode("\n", $lines);
    }

    private static function renderItem(string $id, string $status, string $text): string
    {
        return sprintf('<item id="%s" status="%s">%s</item>', $id, $status, htmlspecialchars($text, ENT_NOQUOTES));
    }

    /**
     * Which candidates go into the pack: the first `$searchSlots` hits found only by the search,
     * then the rest in order, up to MAX_ITEMS and `$maxCharacters`. An item too long for what is
     * left is passed over for shorter ones after it.
     *
     * @param  array<string, array{ref: ?string, status: string, text: string, from_search: bool, groups: list<string>}>  $candidates
     * @return array<string, true>
     */
    private function choose(array $candidates, int $searchSlots, int $maxCharacters): array
    {
        $searchKeys = array_keys(array_filter($candidates, fn (array $candidate): bool => $candidate['from_search']));
        $priority = array_unique([...array_slice($searchKeys, 0, $searchSlots), ...array_keys($candidates)]);
        $chosen = [];
        $characters = 0;

        foreach ($priority as $key) {
            // Measured with the widest id, so renumbering never makes the pack longer than counted.
            $length = mb_strlen(self::renderItem('E'.self::MAX_ITEMS, $candidates[$key]['status'], $candidates[$key]['text'])) + 1;

            if ($characters + $length > $maxCharacters) {
                continue;
            }

            $chosen[$key] = true;
            $characters += $length;

            if (count($chosen) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * The tailored resume's bullet points in resume order, each tagged with its experience
     * ("Senior Engineer at Acme"): the approved version when there is one, else the current one.
     *
     * @return array{?int, list<array{ref: string, status: string, text: string, group: string}>}
     */
    private function resumeStory(JobApplication $application): array
    {
        $resume = $application->resume()->with(['currentVersion', 'approvedVersion'])->first();
        $version = $resume === null ? null : ($resume->isApproved() ? $resume->approvedVersion : $resume->currentVersion);

        if ($version === null) {
            return [null, []];
        }

        $document = $version->document;
        $entries = [];

        foreach ($document['experience'] ?? [] as $job) {
            $entries[] = ['employment' => $job['employment'], 'display_title' => $job['display_title'] ?? null, 'bullets' => $job['bullets'] ?? []];

            foreach ($job['projects'] ?? [] as $project) {
                $entries[] = ['project' => $project['project'], 'bullets' => $project['bullets'] ?? []];
            }
        }

        foreach ($document['projects'] ?? [] as $project) {
            $entries[] = ['project' => $project['project'], 'bullets' => $project['bullets'] ?? []];
        }

        $employments = Employment::query()->whereIn('ulid', array_filter(array_column($entries, 'employment')))->get()->keyBy('ulid');
        $projects = Project::query()->whereIn('ulid', array_filter(array_column($entries, 'project')))->get()->keyBy('ulid');
        $claimUlids = [];

        foreach ($entries as $entry) {
            array_push($claimUlids, ...array_column($entry['bullets'], 'claim'));
        }

        $claims = Claim::query()->whereIn('ulid', $claimUlids)->whereIn('status', ClaimStatus::live())->with('currentRevision')->get()->keyBy('ulid');
        $items = [];

        foreach ($entries as $entry) {
            $label = isset($entry['employment'])
                ? (($entry['display_title'] ?? $employments[$entry['employment']]->title ?? '').' at '.($employments[$entry['employment']]->employer_name ?? ''))
                : (string) ($projects[$entry['project'] ?? '']->name ?? '');

            foreach ($entry['bullets'] as $bullet) {
                $claim = $claims[$bullet['claim']] ?? null;

                if ($claim !== null) {
                    // The resume's wording for this job; the claim's status still decides what it can prove.
                    $items[] = ['ref' => "claim:{$claim->ulid}", 'status' => $claim->status->value, 'text' => $bullet['text'] ?? $claim->statement(), 'group' => $label];
                }
            }
        }

        return [$version->id, $items];
    }
}

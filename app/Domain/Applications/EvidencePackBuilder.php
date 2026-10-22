<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalQuery;

/**
 * The facts an AI answer may use (M8): the best evidence the latest analysis matched to each
 * must-have (two each) and nice-to-have (one each), plus a search on the question itself —
 * de-duplicated, capped, and numbered E1, E2, … for the prompt and the claim check.
 *
 * A cover letter is written from the tailored resume's storyline (docs/04 §4.10): the resume's
 * bullet points come first, in resume order, and the storyline lists each experience with the
 * evidence ids of its bullets.
 */
class EvidencePackBuilder
{
    public const MAX_ITEMS = 15;

    public function __construct(private HybridSearch $search) {}

    /**
     * @return array{pack: array<string, array{ref: ?string, status: string, text: string}>, storyline: string, resume_version_id: ?int}
     */
    public function build(JobApplication $application, ApplicationQuestion $question): array
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
            ? trim($application->role_title.'. '.((string) ($analysis->brief['summary'] ?? '')))
            : $question->prompt_text;
        $result = $this->search->search(new RetrievalQuery($query, $application->candidate_profile_id, 6));

        // Bullet points keep their ref, so a correction learned from an edit can find them (M10).
        $claimUlids = Claim::query()
            ->whereIn('id', array_map(fn ($hit) => $hit->unit->source_id, array_filter($result->hits, fn ($hit) => $hit->unit->source_type === 'claim')))
            ->pluck('ulid', 'id');

        foreach ($result->hits as $hit) {
            $ulid = $hit->unit->source_type === 'claim' ? $claimUlids->get($hit->unit->source_id) : null;
            $items[] = ['ref' => $ulid === null ? null : "claim:{$ulid}", 'status' => $hit->unit->status, 'text' => $hit->unit->search_text, 'unit_type' => $hit->unit->unit_type];
        }

        $pack = [];
        $seen = [];
        $groups = [];
        $number = 0;

        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            $status = (string) ($item['status'] ?? '');
            $key = mb_strtolower($text);

            if ($text === '' || ! in_array($status, ['verified', 'approved', 'proposed'], true)) {
                continue;
            }

            if (isset($seen[$key])) {
                if (isset($item['group'])) {
                    $groups[$item['group']][] = $seen[$key];
                }

                continue;
            }

            $number++;
            $id = "E{$number}";
            $seen[$key] = $id;
            $pack[$id] = ['ref' => $item['ref'] ?? null, 'status' => $status, 'text' => $text];

            if (isset($item['group'])) {
                $groups[$item['group']][] = $id;
            }

            if ($number >= self::MAX_ITEMS) {
                break;
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
            $lines[] = sprintf('<item id="%s" status="%s">%s</item>', $id, $item['status'], htmlspecialchars($item['text'], ENT_NOQUOTES));
        }

        return implode("\n", $lines);
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

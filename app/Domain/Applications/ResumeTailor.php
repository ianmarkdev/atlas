<?php

namespace App\Domain\Applications;

use App\Domain\Ai\ClaimGroundingValidator;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Shared\PlainText;

/**
 * AI tailoring of an application's resume (M8, docs/04 §4.10). `present()` shows the model the
 * resume's jobs, projects and bullet points under short ids (J1, P1, B1); `apply()` turns the
 * model's proposal back into a resume document, keeping only what the server can check:
 *
 * - bullets must belong to their own job or project; library bullets that are not on the resume
 *   yet may be added only when verified;
 * - a rewording that adds numbers not in the original bullet is refused (the old wording stays);
 * - a display title must be one already used for that job (or the official title), and only the
 *   candidate's requests may change it;
 * - jobs are never dropped; projects may be.
 *
 * Everything refused is reported, so the candidate sees what the AI wanted and why it was not used.
 *
 * @phpstan-type TailorItem array{kind: string, ulid: string, label: string, official_title: ?string, title_options: list<string>}
 * @phpstan-type TailorBullet array{claim: string, owner: string, status: string, statement: string, on_resume: bool}
 * @phpstan-type TailorMap array{items: array<string, TailorItem>, bullets: array<string, TailorBullet>}
 */
class ResumeTailor
{
    public function __construct(private ClaimGroundingValidator $grounding) {}

    /**
     * @param  array<string, mixed>  $document  the current tailored document
     * @param  array<string, mixed>  $library  ResumeController::editorLibrary()
     * @return array{text: string, map: TailorMap}
     */
    public function present(array $document, array $library): array
    {
        $employments = self::byUlid($library['employments'] ?? []);
        $projects = self::byUlid([...($library['standaloneProjects'] ?? []), ...array_merge(...array_map(fn (array $job): array => $job['projects'] ?? [], $library['employments'] ?? []))]);
        $titleOptions = self::displayTitlesInUse();
        $map = ['items' => [], 'bullets' => []];
        $lines = ['Title: '.($document['title'] ?? ''), 'Summary: '.($document['summary'] ?? '')];
        $jobNo = 0;
        $projectNo = 0;
        $bulletNo = 0;

        $bullets = function (array $item, array $record, string $owner) use (&$map, &$bulletNo, &$lines): void {
            $onResume = [];

            foreach ($item['bullets'] ?? [] as $bullet) {
                $onResume[$bullet['claim']] = $bullet['text'] ?? null;
            }

            $claims = self::byUlid($record['claims'] ?? []);
            // Bullets on the resume first (in their order), then the item's other library bullets.
            $ordered = [...array_keys($onResume), ...array_diff(array_keys($claims), array_keys($onResume))];

            foreach ($ordered as $ulid) {
                $claim = $claims[$ulid] ?? null;
                $isOn = array_key_exists($ulid, $onResume);

                if ($claim === null || (! $isOn && $claim['status'] !== 'verified')) {
                    continue;
                }

                $id = 'B'.++$bulletNo;
                $map['bullets'][$id] = ['claim' => $ulid, 'owner' => $owner, 'status' => (string) $claim['status'], 'statement' => (string) $claim['statement'], 'on_resume' => $isOn];
                $lines[] = sprintf('  <bullet id="%s" status="%s" on_resume="%s">%s</bullet>', $id, $claim['status'], $isOn ? 'yes' : 'no', self::escape($onResume[$ulid] ?? $claim['statement']));
            }
        };

        $project = function (array $item, string $indent) use (&$map, &$projectNo, &$lines, $projects, $bullets): void {
            $record = $projects[$item['project']] ?? null;

            if ($record === null) {
                return;
            }

            $id = 'P'.++$projectNo;
            $map['items'][$id] = ['kind' => 'project', 'ulid' => $item['project'], 'label' => (string) $record['name'], 'official_title' => null, 'title_options' => []];
            $lines[] = sprintf('%s<project id="%s" name="%s">', $indent, $id, self::escape((string) $record['name']));
            $bullets($item, $record, $id);
            $lines[] = "{$indent}</project>";
        };

        foreach ($document['experience'] ?? [] as $job) {
            $record = $employments[$job['employment']] ?? null;

            if ($record === null) {
                continue;
            }

            $id = 'J'.++$jobNo;
            $options = array_values(array_unique([(string) $record['title'], ...($titleOptions[$job['employment']] ?? []), ...array_filter([$job['display_title'] ?? null])]));
            $map['items'][$id] = ['kind' => 'job', 'ulid' => $job['employment'], 'label' => "{$record['title']} · {$record['employer_name']}", 'official_title' => (string) $record['title'], 'title_options' => $options];
            $lines[] = sprintf(
                '<job id="%s" official_title="%s" employer="%s" dates="%s – %s" shown_as="%s" title_options="%s">',
                $id,
                self::escape((string) $record['title']),
                self::escape((string) $record['employer_name']),
                $record['start_date'] ?? '',
                $record['end_date'] ?? 'now',
                self::escape($job['display_title'] ?? $record['title']),
                self::escape(implode(' | ', $options)),
            );
            $bullets($job, $record, $id);

            foreach ($job['projects'] ?? [] as $item) {
                $project($item, '  ');
            }

            $lines[] = '</job>';
        }

        foreach ($document['projects'] ?? [] as $item) {
            $project($item, '');
        }

        return ['text' => implode("\n", $lines), 'map' => $map];
    }

    /**
     * @param  array<string, mixed>  $document  the current tailored document
     * @param  array<string, mixed>  $output  the model's validated output
     * @param  TailorMap  $map
     * @return array{document: array<string, mixed>, report: array{refused: list<array{item: string, what: string, reason: string, kept: ?string}>, notes: list<string>}}
     */
    public function apply(array $document, array $output, array $map, bool $mayChangeDisplayTitles): array
    {
        $refused = [];
        $proposals = [];

        foreach ((array) ($output['items'] ?? []) as $item) {
            if (is_array($item) && isset($map['items'][(string) ($item['id'] ?? '')])) {
                $proposals[(string) $item['id']] ??= $item;
            }
        }

        $byUlid = [];

        foreach ($map['items'] as $id => $info) {
            $byUlid[$info['kind'].':'.$info['ulid']] = $id;
        }

        $tailorBullets = function (array $current, string $id) use ($proposals, $map, &$refused): array {
            $proposal = $proposals[$id] ?? null;

            if ($proposal === null) {
                return $current;
            }

            $label = $map['items'][$id]['label'];
            $existing = [];

            foreach ($current as $bullet) {
                $existing[$bullet['claim']] = $bullet['text'] ?? null;
            }

            $result = [];
            $seen = [];

            foreach ((array) ($proposal['bullets'] ?? []) as $bullet) {
                $bulletId = (string) ($bullet['id'] ?? '');
                $info = $map['bullets'][$bulletId] ?? null;

                if ($info === null || $info['owner'] !== $id) {
                    $refused[] = ['item' => $label, 'what' => $bulletId, 'reason' => 'not_item_bullet', 'kept' => null];

                    continue;
                }

                if (isset($seen[$info['claim']])) {
                    continue;
                }
                $seen[$info['claim']] = true;
                $previous = $existing[$info['claim']] ?? null;
                $result[] = ['claim' => $info['claim'], 'text' => $this->wording((string) ($bullet['text'] ?? ''), $info, $previous, $label, $refused)];
            }

            if ($result === []) {
                $refused[] = ['item' => $label, 'what' => '', 'reason' => 'no_bullets_left', 'kept' => null];

                return $current;
            }

            return array_slice($result, 0, 30);
        };

        $tailorProject = function (array $item) use ($byUlid, $proposals, $tailorBullets): ?array {
            $id = $byUlid['project:'.$item['project']] ?? null;

            if ($id === null) {
                return $item;
            }

            if (($proposals[$id]['include'] ?? true) === false) {
                return null;
            }

            return [...$item, 'bullets' => $tailorBullets($item['bullets'] ?? [], $id)];
        };

        $experience = [];

        foreach ($document['experience'] ?? [] as $job) {
            $id = $byUlid['job:'.$job['employment']] ?? null;

            if ($id === null) {
                $experience[] = $job;

                continue;
            }

            $info = $map['items'][$id];

            if (($proposals[$id]['include'] ?? true) === false) {
                $refused[] = ['item' => $info['label'], 'what' => '', 'reason' => 'job_kept', 'kept' => null];
            }

            $job['display_title'] = $this->displayTitle($job['display_title'] ?? null, $proposals[$id]['display_title'] ?? null, $info, $mayChangeDisplayTitles, $refused);
            $job['bullets'] = $tailorBullets($job['bullets'] ?? [], $id);
            $job['projects'] = array_values(array_filter(array_map($tailorProject, $job['projects'] ?? [])));
            $experience[] = $job;
        }

        $document['experience'] = $experience;
        $document['projects'] = array_values(array_filter(array_map($tailorProject, $document['projects'] ?? [])));
        $document['title'] = self::text($output['title'] ?? null, 255) ?? ($document['title'] ?? null);
        $document['summary'] = $this->summary($document['summary'] ?? null, $output, $map, $refused);

        return [
            'document' => $document,
            'report' => [
                'refused' => $refused,
                'notes' => array_values(array_filter(array_map(fn (mixed $note): ?string => is_string($note) ? self::text($note, 300) : null, array_slice((array) ($output['notes'] ?? []), 0, 5)))),
            ],
        ];
    }

    /**
     * The bullet's wording on this resume: null (the shared wording) when the proposal matches
     * it, the proposal when it adds no unsupported numbers, otherwise the previous wording.
     *
     * @param  TailorBullet  $info
     * @param  list<array{item: string, what: string, reason: string, kept: ?string}>  $refused
     */
    private function wording(string $proposed, array $info, ?string $previous, string $label, array &$refused): ?string
    {
        $text = self::text($proposed, 1000);

        if ($text === null || mb_strtolower($text) === mb_strtolower($info['statement'])) {
            return $text === null ? $previous : null;
        }

        $evidence = ['B' => ['status' => 'verified', 'text' => trim($info['statement'].' '.($previous ?? ''))]];
        $checked = $this->grounding->check([['text' => $text, 'classification' => 'verified', 'evidence_ids' => ['B']]], $evidence)[0];

        if (in_array('metric_without_source', $checked['flags'], true)) {
            $refused[] = ['item' => $label, 'what' => $text, 'reason' => 'adds_number', 'kept' => $previous ?? $info['statement']];

            return $previous;
        }

        return $text;
    }

    /**
     * @param  TailorItem  $info
     * @param  list<array{item: string, what: string, reason: string, kept: ?string}>  $refused
     */
    private function displayTitle(?string $current, mixed $proposed, array $info, bool $mayChange, array &$refused): ?string
    {
        $proposed = is_string($proposed) ? self::text($proposed, 255) : null;

        if ($proposed === null || mb_strtolower($proposed) === mb_strtolower($current ?? $info['official_title'])) {
            return $current;
        }

        if (! $mayChange) {
            $refused[] = ['item' => $info['label'], 'what' => $proposed, 'reason' => 'title_candidate_only', 'kept' => $current];

            return $current;
        }

        foreach ($info['title_options'] as $option) {
            if (mb_strtolower($option) === mb_strtolower($proposed)) {
                return $option === $info['official_title'] ? null : $option;
            }
        }

        $refused[] = ['item' => $info['label'], 'what' => $proposed, 'reason' => 'title_not_in_use', 'kept' => $current];

        return $current;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  TailorMap  $map
     * @param  list<array{item: string, what: string, reason: string, kept: ?string}>  $refused
     */
    private function summary(?string $current, array $output, array $map, array &$refused): ?string
    {
        $proposed = self::text($output['summary'] ?? null, 2000, multiline: true);

        if ($proposed === null) {
            return $current;
        }

        $evidence = [];

        foreach ($map['bullets'] as $id => $bullet) {
            $evidence[$id] = ['status' => $bullet['status'], 'text' => $bullet['statement']];
        }

        if ($current !== null) {
            $evidence['SUMMARY'] = ['status' => 'verified', 'text' => $current];
        }

        // The summary may restate anything on the resume, including the current summary.
        $checked = $this->grounding->check([['text' => $proposed, 'classification' => 'inferred', 'evidence_ids' => array_keys($evidence)]], $evidence)[0];

        if (in_array('metric_without_source', $checked['flags'], true)) {
            $refused[] = ['item' => 'summary', 'what' => $proposed, 'reason' => 'summary_number', 'kept' => $current];

            return $current;
        }

        return $proposed;
    }

    /**
     * Display titles the candidate's resumes already use, per employment ULID.
     *
     * @return array<string, list<string>>
     */
    private static function displayTitlesInUse(): array
    {
        $titles = [];

        foreach (ResumeVariant::query()->active()->with('currentRevision')->get() as $variant) {
            foreach ($variant->document()['experience'] ?? [] as $job) {
                if (is_string($job['display_title'] ?? null) && $job['display_title'] !== '') {
                    $titles[(string) $job['employment']][] = $job['display_title'];
                }
            }
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $titles);
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array<string, array<string, mixed>>
     */
    private static function byUlid(array $records): array
    {
        $keyed = [];

        foreach ($records as $record) {
            if (is_array($record) && isset($record['ulid'])) {
                $keyed[(string) $record['ulid']] = $record;
            }
        }

        return $keyed;
    }

    private static function text(mixed $value, int $max, bool $multiline = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = PlainText::normalize($value, $multiline);

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

<?php

namespace App\Domain\Resumes;

use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateLink;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\CredentialType;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\Skill;
use App\Domain\Shared\PlainText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises a resume document (schema_version 1) against the library of the
 * workspace in context. The document only selects, orders and words library items: facts
 * (employer, official title, dates) are not part of it, and any unknown field is rejected.
 *
 * Shape:
 * {schema_version, title, summary,
 *  experience: [{employment, display_title, description, bullets: [{claim, text}], projects: [{project, description, bullets}]}],
 *  projects: [{project, description, bullets}],
 *  skills: [{label, items: [skill ulid]}], education: [{credential}], certifications: [{credential}],
 *  languages: [language ulid], links: [link ulid]}
 * Sections added after the first resumes were saved default to empty lists.
 */
class ResumeDocumentValidator
{
    public const MAX_JOBS = 50;

    public const MAX_PROJECTS = 30;

    public const MAX_BULLETS = 30;

    public const MAX_SKILL_GROUPS = 10;

    public const MAX_SKILLS_PER_GROUP = 40;

    public const MAX_CREDENTIALS = 20;

    private const DOCUMENT_KEYS = ['schema_version', 'title', 'summary', 'experience', 'projects', 'skills', 'education', 'certifications', 'languages', 'links'];

    private const SKILL_GROUP_KEYS = ['label', 'items'];

    private const CREDENTIAL_KEYS = ['credential'];

    private const JOB_KEYS = ['employment', 'display_title', 'description', 'bullets', 'projects'];

    private const PROJECT_KEYS = ['project', 'description', 'bullets'];

    private const BULLET_KEYS = ['claim', 'text'];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @return array<string, mixed>
     */
    public static function emptyDocument(): array
    {
        return [
            'schema_version' => 1,
            'title' => null,
            'summary' => null,
            'experience' => [],
            'projects' => [],
            'skills' => [],
            'education' => [],
            'certifications' => [],
            'languages' => [],
            'links' => [],
        ];
    }

    /**
     * @return array<string, mixed> the normalised document
     *
     * @throws ValidationException with keys like `document.experience.0.bullets.2.claim`
     */
    public function validate(mixed $document, string $prefix = 'document'): array
    {
        $this->errors = [];

        $structure = Validator::make(['document' => $document], [
            'document' => ['required', 'array'],
            'document.title' => ['nullable', 'string', 'max:255'],
            'document.summary' => ['nullable', 'string', 'max:2000'],
            'document.experience' => ['sometimes', 'array', 'max:'.self::MAX_JOBS],
            'document.experience.*.employment' => ['required', 'string'],
            'document.experience.*.display_title' => ['nullable', 'string', 'max:255'],
            'document.experience.*.description' => ['nullable', 'string', 'max:1000'],
            'document.experience.*.bullets' => ['sometimes', 'array', 'max:'.self::MAX_BULLETS],
            'document.experience.*.bullets.*.claim' => ['required', 'string'],
            'document.experience.*.bullets.*.text' => ['nullable', 'string', 'max:1000'],
            'document.experience.*.projects' => ['sometimes', 'array', 'max:'.self::MAX_PROJECTS],
            'document.experience.*.projects.*.project' => ['required', 'string'],
            'document.experience.*.projects.*.description' => ['nullable', 'string', 'max:1000'],
            'document.experience.*.projects.*.bullets' => ['sometimes', 'array', 'max:'.self::MAX_BULLETS],
            'document.experience.*.projects.*.bullets.*.claim' => ['required', 'string'],
            'document.experience.*.projects.*.bullets.*.text' => ['nullable', 'string', 'max:1000'],
            'document.projects' => ['sometimes', 'array', 'max:'.self::MAX_PROJECTS],
            'document.projects.*.project' => ['required', 'string'],
            'document.projects.*.description' => ['nullable', 'string', 'max:1000'],
            'document.projects.*.bullets' => ['sometimes', 'array', 'max:'.self::MAX_BULLETS],
            'document.projects.*.bullets.*.claim' => ['required', 'string'],
            'document.projects.*.bullets.*.text' => ['nullable', 'string', 'max:1000'],
            'document.skills' => ['sometimes', 'array', 'max:'.self::MAX_SKILL_GROUPS],
            'document.skills.*.label' => ['nullable', 'string', 'max:60'],
            'document.skills.*.items' => ['sometimes', 'array', 'max:'.self::MAX_SKILLS_PER_GROUP],
            'document.skills.*.items.*' => ['string'],
            'document.education' => ['sometimes', 'array', 'max:'.self::MAX_CREDENTIALS],
            'document.education.*.credential' => ['required', 'string'],
            'document.certifications' => ['sometimes', 'array', 'max:'.self::MAX_CREDENTIALS],
            'document.certifications.*.credential' => ['required', 'string'],
            'document.languages' => ['sometimes', 'array', 'max:20'],
            'document.languages.*' => ['string'],
            'document.links' => ['sometimes', 'array', 'max:20'],
            'document.links.*' => ['string'],
        ]);

        if ($structure->fails()) {
            $messages = [];

            foreach ($structure->errors()->messages() as $key => $keyMessages) {
                $messages[$prefix.substr($key, strlen('document'))] = $keyMessages;
            }

            throw ValidationException::withMessages($messages);
        }

        /** @var array<string, mixed> $document */
        $this->rejectUnknownKeys($document, self::DOCUMENT_KEYS, $prefix);

        $jobs = $this->listOf($document['experience'] ?? []);
        $standalone = $this->listOf($document['projects'] ?? []);

        $projectUlids = array_column($standalone, 'project');

        foreach ($jobs as $job) {
            array_push($projectUlids, ...array_column($this->listOf($job['projects'] ?? []), 'project'));
        }

        $employments = $this->keyByUlid(Employment::query()->notArchived()->whereIn('ulid', $this->strings(array_column($jobs, 'employment')))->get()->all());
        $projects = $this->keyByUlid(Project::query()->notArchived()->whereIn('ulid', $this->strings($projectUlids))->get()->all());
        $claims = $this->keyByUlid(Claim::query()
            ->whereIn('ulid', $this->allBulletClaimUlids($jobs, $standalone))
            ->whereIn('status', ClaimStatus::live())
            ->get()
            ->all());

        $seenEmployments = [];
        $seenProjects = [];
        $normalizedJobs = [];

        foreach ($jobs as $i => $job) {
            $path = "{$prefix}.experience.{$i}";
            $this->rejectUnknownKeys($job, self::JOB_KEYS, $path);
            $employment = $employments[$job['employment']] ?? null;

            if ($employment === null) {
                $this->errors["{$path}.employment"] = __('This experience is no longer in your library.');

                continue;
            }

            if (isset($seenEmployments[$employment->ulid])) {
                $this->errors["{$path}.employment"] = __('This experience is already on the resume.');

                continue;
            }
            $seenEmployments[$employment->ulid] = true;

            $jobProjects = [];

            foreach ($this->listOf($job['projects'] ?? []) as $j => $projectItem) {
                $projectPath = "{$path}.projects.{$j}";
                $project = $projects[$projectItem['project']] ?? null;

                if ($project === null || $project->employment_id !== $employment->id) {
                    $this->errors["{$projectPath}.project"] = __('This project is not part of that experience in your library.');

                    continue;
                }

                $jobProjects[] = $this->normalizeProject($projectItem, $project, $projectPath, $claims, $seenProjects);
            }

            $normalizedJobs[] = [
                'employment' => $employment->ulid,
                'display_title' => $this->text($job['display_title'] ?? null),
                'description' => $this->text($job['description'] ?? null, multiline: true),
                'bullets' => $this->normalizeBullets($this->listOf($job['bullets'] ?? []), $employment, "{$path}.bullets", $claims),
                'projects' => $jobProjects,
            ];
        }

        $normalizedStandalone = [];

        foreach ($standalone as $i => $projectItem) {
            $path = "{$prefix}.projects.{$i}";
            $project = $projects[$projectItem['project']] ?? null;

            if ($project === null || $project->employment_id !== null) {
                $this->errors["{$path}.project"] = __('This project is not a standalone project in your library.');

                continue;
            }

            $normalizedStandalone[] = $this->normalizeProject($projectItem, $project, $path, $claims, $seenProjects);
        }

        $skills = $this->normalizeSkills($this->listOf($document['skills'] ?? []), $prefix);
        $education = $this->normalizeCredentials($this->listOf($document['education'] ?? []), CredentialType::Education, "{$prefix}.education");
        $certifications = $this->normalizeCredentials($this->listOf($document['certifications'] ?? []), CredentialType::Certification, "{$prefix}.certifications");
        $languages = $this->normalizeRefs($document['languages'] ?? [], CandidateLanguage::class, "{$prefix}.languages", __('This language is no longer on your contact details.'));
        $links = $this->normalizeRefs($document['links'] ?? [], CandidateLink::class, "{$prefix}.links", __('This link is no longer on your contact details.'));

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return [
            'schema_version' => 1,
            'title' => $this->text($document['title'] ?? null),
            'summary' => $this->text($document['summary'] ?? null, multiline: true),
            'experience' => $normalizedJobs,
            'projects' => $normalizedStandalone,
            'skills' => $skills,
            'education' => $education,
            'certifications' => $certifications,
            'languages' => $languages,
            'links' => $links,
        ];
    }

    /**
     * Skill groups ("Backend: PHP, Laravel"). Each skill appears at most once on the resume;
     * empty groups are dropped.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array{label: string|null, items: list<string>}>
     */
    private function normalizeSkills(array $groups, string $prefix): array
    {
        $ulids = [];

        foreach ($groups as $group) {
            array_push($ulids, ...$this->strings(is_array($group['items'] ?? null) ? $group['items'] : []));
        }

        $known = array_flip(Skill::query()->whereIn('ulid', $ulids)->pluck('ulid')->all());
        $seen = [];
        $normalized = [];

        foreach ($groups as $i => $group) {
            $path = "{$prefix}.skills.{$i}";
            $this->rejectUnknownKeys($group, self::SKILL_GROUP_KEYS, $path);
            $items = [];

            foreach ($this->strings(is_array($group['items'] ?? null) ? $group['items'] : []) as $j => $ulid) {
                if (! isset($known[$ulid])) {
                    $this->errors["{$path}.items.{$j}"] = __('This skill is no longer in your library.');

                    continue;
                }

                if (! isset($seen[$ulid])) {
                    $seen[$ulid] = true;
                    $items[] = $ulid;
                }
            }

            if ($items !== []) {
                $normalized[] = ['label' => $this->text($group['label'] ?? null), 'items' => $items];
            }
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{credential: string}>
     */
    private function normalizeCredentials(array $items, CredentialType $type, string $path): array
    {
        $credentials = $this->keyByUlid(Credential::query()
            ->notArchived()
            ->where('type', $type)
            ->whereIn('ulid', $this->strings(array_column($items, 'credential')))
            ->get()
            ->all());
        $normalized = [];
        $seen = [];

        foreach ($items as $i => $item) {
            $this->rejectUnknownKeys($item, self::CREDENTIAL_KEYS, "{$path}.{$i}");
            $credential = $credentials[$item['credential']] ?? null;

            if ($credential === null) {
                $this->errors["{$path}.{$i}.credential"] = $type === CredentialType::Education
                    ? __('This education is no longer in your library.')
                    : __('This certification is no longer in your library.');

                continue;
            }

            if (! isset($seen[$credential->ulid])) {
                $seen[$credential->ulid] = true;
                $normalized[] = ['credential' => $credential->ulid];
            }
        }

        return $normalized;
    }

    /**
     * An ordered selection of languages or links (plain ULID lists).
     *
     * @param  class-string<CandidateLanguage|CandidateLink>  $model
     * @return list<string>
     */
    private function normalizeRefs(mixed $ulids, string $model, string $path, string $missingMessage): array
    {
        $ulids = $this->strings(is_array($ulids) ? array_values($ulids) : []);
        $known = array_flip($model::query()->whereIn('ulid', $ulids)->pluck('ulid')->all());
        $normalized = [];

        foreach ($ulids as $i => $ulid) {
            if (! isset($known[$ulid])) {
                $this->errors["{$path}.{$i}"] = $missingMessage;

                continue;
            }

            if (! in_array($ulid, $normalized, true)) {
                $normalized[] = $ulid;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, Claim>  $claims
     * @param  array<string, true>  $seenProjects
     * @return array<string, mixed>
     */
    private function normalizeProject(array $item, Project $project, string $path, array $claims, array &$seenProjects): array
    {
        $this->rejectUnknownKeys($item, self::PROJECT_KEYS, $path);

        if (isset($seenProjects[$project->ulid])) {
            $this->errors["{$path}.project"] = __('This project is already on the resume.');
        }
        $seenProjects[$project->ulid] = true;

        return [
            'project' => $project->ulid,
            'description' => $this->text($item['description'] ?? null, multiline: true),
            'bullets' => $this->normalizeBullets($this->listOf($item['bullets'] ?? []), $project, "{$path}.bullets", $claims),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $bullets
     * @param  array<string, Claim>  $claims
     * @return list<array{claim: string, text: string|null}>
     */
    private function normalizeBullets(array $bullets, Employment|Project $subject, string $path, array $claims): array
    {
        $normalized = [];
        $seen = [];

        foreach ($bullets as $k => $bullet) {
            $this->rejectUnknownKeys($bullet, self::BULLET_KEYS, "{$path}.{$k}");
            $claim = $claims[$bullet['claim']] ?? null;

            if ($claim === null || $claim->subject_type !== $subject->getMorphClass() || $claim->subject_id !== $subject->id) {
                $this->errors["{$path}.{$k}.claim"] = __('This bullet point does not belong to that item.');

                continue;
            }

            if (isset($seen[$claim->ulid])) {
                continue;
            }
            $seen[$claim->ulid] = true;

            $normalized[] = ['claim' => $claim->ulid, 'text' => $this->text($bullet['text'] ?? null)];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $item, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($item), $allowed) as $key) {
            $this->errors["{$path}.{$key}"] = __('":field" cannot be set on a resume. Facts such as employer, title and dates come from your library.', ['field' => $key]);
        }
    }

    /**
     * @template TModel of Employment|Project|Claim|Credential
     *
     * @param  array<int, TModel>  $models
     * @return array<string, TModel>
     */
    private function keyByUlid(array $models): array
    {
        $keyed = [];

        foreach ($models as $model) {
            $keyed[$model->ulid] = $model;
        }

        return $keyed;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @param  list<array<string, mixed>>  $standalone
     * @return list<string>
     */
    private function allBulletClaimUlids(array $jobs, array $standalone): array
    {
        $ulids = [];
        $collect = function (array $item) use (&$ulids): void {
            foreach ($this->listOf($item['bullets'] ?? []) as $bullet) {
                $ulids[] = $bullet['claim'];
            }
        };

        foreach ($jobs as $job) {
            $collect($job);
            array_map($collect, $this->listOf($job['projects'] ?? []));
        }
        array_map($collect, $standalone);

        return array_values(array_filter($ulids, is_string(...)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private function text(mixed $value, bool $multiline = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = PlainText::normalize($value, $multiline);

        return $clean === '' ? null : $clean;
    }
}

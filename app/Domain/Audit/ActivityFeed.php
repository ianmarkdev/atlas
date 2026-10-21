<?php

namespace App\Domain\Audit;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * The workspace's recent audit events as readable sentences for the dashboard
 * ("Vera Assistant suggested a change to a bullet point: “Led a team of four”").
 */
class ActivityFeed
{
    /**
     * Subject nouns by the prefix of the audit action.
     */
    private const NOUNS = [
        'answer' => 'an answer',
        'application' => 'an application',
        'application_resume' => 'a tailored resume',
        'claim' => 'a bullet point',
        'employment' => 'an experience',
        'project' => 'a project',
        'credential' => 'a credential',
        'resume' => 'a resume',
        'candidate_profile' => 'the contact details',
        'member' => 'a team member',
        'skill' => 'a skill',
        'skills' => 'skills',
    ];

    private const VERBS = [
        'created' => 'added',
        'updated' => 'updated',
        'revised' => 'updated',
        'saved' => 'saved',
        'verified' => 'verified',
        'rejected' => 'rejected',
        'archived' => 'archived',
        'restored' => 'restored',
        'reopened' => 'reopened',
        'copied' => 'recopied',
        'approved' => 'approved',
        'deleted' => 'removed',
        'added' => 'added',
        'deactivated' => 'deactivated',
        'reactivated' => 'reactivated',
        'password_link_created' => 'created a sign-in link for',
    ];

    /**
     * @return list<array{id: int, actor: string, is_you: bool, sentence: string, subject: string|null, at: string}>
     */
    public static function recent(User $viewer, int $limit = 8): array
    {
        $events = AuditEvent::query()->latest('id')->limit($limit)->get();

        $actors = User::query()->whereIn('id', $events->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');
        $subjects = self::subjectLabels(array_values($events->all()));

        return array_values($events->map(fn (AuditEvent $event): array => [
            'id' => $event->id,
            'actor' => $event->actor_user_id === null ? 'System' : (string) ($actors[$event->actor_user_id] ?? 'A former member'),
            'is_you' => $event->actor_user_id === $viewer->id,
            'sentence' => self::sentence($event),
            'subject' => $subjects["{$event->subject_type}:{$event->subject_id}"] ?? null,
            'at' => $event->created_at->toIso8601String(),
        ])->all());
    }

    private static function sentence(AuditEvent $event): string
    {
        [$prefix, $verb] = array_pad(explode('.', $event->action, 2), 2, '');

        return match (true) {
            $event->action === 'workspace.installed' => __('set up the workspace'),
            $event->action === 'proposal.created' => __('suggested a change'),
            $event->action === 'resume.made_default' => __('made a resume the default'),
            $event->action === 'application.stage_changed' => __('moved an application to :stage', [
                'stage' => ApplicationStage::tryFrom((string) ($event->metadata['to'] ?? ''))?->label() ?? __('another stage'),
            ]),
            $prefix === 'proposal' => __(':verb a suggested change', ['verb' => self::VERBS[$verb] ?? Str::headline($verb)]),
            $event->action === 'skills.added' => trans_choice('{1} added a skill|[2,*] added :count skills', (int) ($event->metadata['count'] ?? 0), ['count' => (int) ($event->metadata['count'] ?? 0)]),
            default => trim((self::VERBS[$verb] ?? Str::lower(Str::headline($verb))).' '.(self::NOUNS[$prefix] ?? Str::lower(Str::headline($prefix)))),
        };
    }

    /**
     * Short labels for the events' subjects, keyed by `type:id`. Subjects that were deleted, or
     * whose type has no useful label, are left out.
     *
     * @param  list<AuditEvent>  $events
     * @return array<string, string>
     */
    private static function subjectLabels(array $events): array
    {
        $labels = [];
        $idsByType = [];

        foreach ($events as $event) {
            if ($event->subject_type !== null && $event->subject_id !== null) {
                $idsByType[$event->subject_type][] = $event->subject_id;
            }
        }

        foreach ($idsByType as $type => $ids) {
            $class = Relation::getMorphedModel($type);

            if ($class === null || ! in_array($class, [Claim::class, AnswerLibraryEntry::class, JobApplication::class, Employment::class, Project::class, Credential::class, ResumeVariant::class, User::class], true)) {
                continue;
            }

            // Deleted applications still get a label: the activity happened.
            $query = ($class === JobApplication::class ? JobApplication::withTrashed() : $class::query())
                ->whereIn('id', array_unique($ids));

            if ($class === Claim::class || $class === AnswerLibraryEntry::class) {
                $query->with('currentRevision');
            }

            foreach ($query->get() as $model) {
                $labels["{$type}:{$model->getKey()}"] = Str::limit(self::labelOf($model), 80);
            }
        }

        return $labels;
    }

    private static function labelOf(Model $model): string
    {
        return match (true) {
            $model instanceof Claim => $model->statement(),
            $model instanceof AnswerLibraryEntry => $model->question,
            $model instanceof Employment => "{$model->title} · {$model->employer_name}",
            $model instanceof JobApplication => "{$model->role_title} · {$model->company_name}",
            $model instanceof Project, $model instanceof ResumeVariant, $model instanceof Credential, $model instanceof User => (string) $model->getAttribute('name'),
            default => '',
        };
    }
}

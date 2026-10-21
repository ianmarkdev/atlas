<?php

namespace App\Http\Controllers;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Audit\ActivityFeed;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Knowledge\Skill;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace overview: headline numbers, what needs attention, a setup checklist for the
 * candidate, the active resumes and recent activity.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $profile = CandidateProfile::current();

        $claimCounts = Claim::query()->whereIn('status', ClaimStatus::live())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $answerCounts = AnswerLibraryEntry::query()->where('status', '!=', AnswerStatus::Archived)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $resumes = ResumeVariant::query()->active()->with('currentRevision')
            ->orderByDesc('is_default')->latest('updated_at')->get();

        $stats = [
            'resumes' => $resumes->count(),
            'jobs' => Employment::query()->where('status', '!=', KnowledgeStatus::Archived)->count(),
            'projects' => Project::query()->where('status', '!=', KnowledgeStatus::Archived)->count(),
            'bullets' => (int) $claimCounts->sum(),
            'bulletsProposed' => (int) ($claimCounts[ClaimStatus::Proposed->value] ?? 0),
            'skills' => Skill::query()->count(),
            'answers' => (int) ($answerCounts[AnswerStatus::Approved->value] ?? 0),
            'answersDraft' => (int) ($answerCounts[AnswerStatus::Draft->value] ?? 0),
        ];

        return Inertia::render('dashboard', [
            'firstName' => strtok($user->name, ' ') ?: $user->name,
            'candidateName' => $profile->display_name,
            'isCandidate' => $user->isCandidate(),
            'stats' => $stats,
            'attention' => $this->attention($user, $stats),
            'checklist' => $user->isCandidate() ? $this->checklist($user, $profile, $stats, array_values($resumes->all())) : null,
            'resumes' => $resumes->take(4)->map(fn (ResumeVariant $resume): array => [
                'ulid' => $resume->ulid,
                'name' => $resume->name,
                'title' => $resume->document()['title'] ?: null,
                'jobs' => count($resume->document()['experience']),
                'is_default' => $resume->is_default,
                'updated_at' => $resume->updated_at?->toIso8601String(),
            ])->values()->all(),
            'activity' => ActivityFeed::recent($user),
            'pipeline' => $this->pipeline(),
        ]);
    }

    /**
     * Work waiting for the signed-in user; only non-zero items.
     *
     * @param  array<string, int>  $stats
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function attention(User $user, array $stats): array
    {
        if ($user->isCandidate()) {
            $unverified = $stats['bulletsProposed']
                + Employment::query()->where('status', KnowledgeStatus::Proposed)->count()
                + Project::query()->where('status', KnowledgeStatus::Proposed)->count()
                + Credential::query()->where('status', KnowledgeStatus::Proposed)->count();

            $items = [
                ['key' => 'suggestions', 'label' => __('Suggested changes to review'), 'count' => KnowledgeProposal::query()->where('status', ProposalStatus::Pending)->count(), 'href' => route('knowledge.proposals.index')],
                ['key' => 'unverified', 'label' => __('Library items to verify'), 'count' => $unverified, 'href' => route('knowledge.index', ['show' => 'unverified'])],
                ['key' => 'drafts', 'label' => __('Draft answers to approve'), 'count' => $stats['answersDraft'], 'href' => route('knowledge.answers.index')],
            ];
        } else {
            $items = [
                ['key' => 'suggestions', 'label' => __('Your suggestions awaiting the candidate'), 'count' => KnowledgeProposal::query()->where('status', ProposalStatus::Pending)->where('proposed_by_user_id', $user->id)->count(), 'href' => route('knowledge.proposals.index')],
                ['key' => 'drafts', 'label' => __('Draft answers awaiting approval'), 'count' => $stats['answersDraft'], 'href' => route('knowledge.answers.index')],
            ];
        }

        $today = CarbonImmutable::today();
        $notYetSent = JobApplication::query()->whereIn('stage', [ApplicationStage::Saved, ApplicationStage::Preparing]);

        array_unshift(
            $items,
            ['key' => 'overdue', 'label' => __('Applications past their deadline'), 'count' => (clone $notYetSent)->where('deadline_on', '<', $today)->count(), 'href' => route('applications.index')],
            ['key' => 'closing', 'label' => __('Applications closing within 3 days'), 'count' => (clone $notYetSent)->whereBetween('deadline_on', [$today, $today->addDays(3)])->count(), 'href' => route('applications.index')],
        );

        return array_values(array_filter($items, fn (array $item): bool => $item['count'] > 0));
    }

    /**
     * Applications per stage, for the pipeline card.
     *
     * @return array<string, int>
     */
    private function pipeline(): array
    {
        $counts = JobApplication::query()->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        $pipeline = [];

        foreach (ApplicationStage::cases() as $stage) {
            $pipeline[$stage->value] = (int) ($counts[$stage->value] ?? 0);
        }

        return $pipeline;
    }

    /**
     * Getting-started steps for the candidate, in the order that makes the product useful.
     *
     * @param  array<string, int>  $stats
     * @param  list<ResumeVariant>  $resumes
     * @return list<array{key: string, label: string, description: string, done: bool, href: string}>
     */
    private function checklist(User $user, CandidateProfile $profile, array $stats, array $resumes): array
    {
        $hasBuiltResume = array_any($resumes, fn (ResumeVariant $resume): bool => $resume->document()['experience'] !== []);

        return [
            ['key' => 'contact', 'label' => __('Add your contact details'), 'description' => __('Email, phone and location, shared by every resume.'), 'done' => filled($profile->email) && filled($profile->phone) && filled($profile->location), 'href' => route('contact.edit')],
            ['key' => 'experience', 'label' => __('Add your experience'), 'description' => __('Your jobs, starting with the current one.'), 'done' => $stats['jobs'] > 0, 'href' => route('knowledge.employments.create')],
            ['key' => 'bullets', 'label' => __('Write five bullet points'), 'description' => __('Achievements with numbers you can stand behind.'), 'done' => $stats['bullets'] >= 5, 'href' => route('knowledge.index')],
            ['key' => 'skills', 'label' => __('List your skills'), 'description' => __('Each resume picks the ones it shows.'), 'done' => $stats['skills'] >= 3, 'href' => route('knowledge.index')],
            ['key' => 'resume', 'label' => __('Build a resume'), 'description' => __('Choose the jobs and bullet points it shows.'), 'done' => $hasBuiltResume, 'href' => route('resumes.index')],
            ['key' => 'answers', 'label' => __('Save three standard answers'), 'description' => __('“Why us?”, notice period, salary — ready to copy.'), 'done' => $stats['answers'] >= 3, 'href' => route('knowledge.answers.create')],
            ['key' => 'security', 'label' => __('Turn on two-factor authentication'), 'description' => __('Protects your career data and your team.'), 'done' => $user->two_factor_confirmed_at !== null, 'href' => route('security.edit')],
        ];
    }
}

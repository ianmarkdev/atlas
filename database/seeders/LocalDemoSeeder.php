<?php

namespace Database\Seeders;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateLink;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\Skill;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo accounts and a little career data for clicking through the UI locally.
 * Every account's password is `password`. Refuses to run outside local/testing.
 *
 * Not `WithoutModelEvents`: ULIDs, the tenant `workspace_id` fill and employer
 * normalisation all rely on model events.
 */
class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('LocalDemoSeeder only runs in the local or testing environment.');
        }

        $candidate = User::factory()->candidate()->create([
            'name' => 'Alex Morgan',
            'email' => 'candidate@example.com',
        ]);

        $workspace = $candidate->membership->workspace;
        $workspace->update(['name' => "Alex's applications"]);

        $profile = CandidateProfile::withoutGlobalScopes()->where('user_id', $candidate->id)->sole();
        $profile->update([
            'display_name' => 'Alex Morgan',
            'email' => 'alex.morgan@example.com',
            'phone' => '+44 20 7946 0000',
            'location' => 'London, UK (open to remote)',
        ]);

        $va = User::factory()->va($workspace)->create(['name' => 'Vera Assistant', 'email' => 'va@example.com']);
        User::factory()->va($workspace)->create(['name' => 'Sam Helper', 'email' => 'va2@example.com']);
        User::factory()->inactive()->va($workspace)->create(['name' => 'Dee Former', 'email' => 'va-inactive@example.com']);

        $current = Employment::factory()->forProfile($profile)->verified($candidate)->create([
            'employer_name' => 'Northwind Payments Ltd',
            'title' => 'Senior Backend Developer',
            'employment_type' => EmploymentType::FullTime,
            'location' => 'London (hybrid)',
            'start_date' => '2022-04-01',
            'end_date' => null,
            'summary' => "Backend lead for the merchant billing platform.\nLaravel, PostgreSQL, Redis, AWS.",
            'created_by' => $candidate->id,
        ]);

        $previous = Employment::factory()->forProfile($profile)->verified($candidate)->create([
            'employer_name' => 'Brightside Digital',
            'title' => 'PHP Developer',
            'employment_type' => EmploymentType::FullTime,
            'location' => 'Manchester',
            'start_date' => '2018-09-01',
            'end_date' => '2022-03-01',
            'summary' => 'Agency work: e-commerce builds and integrations for retail clients.',
            'created_by' => $candidate->id,
        ]);

        Employment::factory()->forProfile($profile)->create([
            'employer_name' => 'Freelance',
            'title' => 'Web Developer',
            'employment_type' => EmploymentType::Freelance,
            'start_date' => '2017-01-01',
            'end_date' => '2018-08-01',
            'summary' => 'Added by a VA from the old CV. Waiting for the candidate to verify.',
            'created_by' => $va->id,
        ]);

        $verifiedClaim = fn (Model $subject, string $statement): Claim => Claim::factory()->forProfile($profile)
            ->about($subject)->stating($statement)->verified($candidate)->create(['created_by' => $candidate->id]);

        $lead = $verifiedClaim($current, 'Lead backend developer for the merchant billing platform (40k merchants).');
        $invoiceRun = $verifiedClaim($current, 'Cut the nightly invoice run from 4 hours to 20 minutes by moving it to queued, idempotent jobs.');
        $mentoring = $verifiedClaim($current, 'Mentored two junior developers through their first production releases.');
        $dashboard = $verifiedClaim($current, 'Built the merchant dashboard in React and TypeScript on top of the billing API.');
        $pricing = $verifiedClaim($current, 'Rebuilt the public pricing pages in Next.js with server-side rendering; Lighthouse performance went from 62 to 95.');
        $rails = $verifiedClaim($previous, 'Maintained a Ruby on Rails order-management app for a retail client.');

        $integrations = Claim::factory()->forProfile($profile)->about($previous)
            ->stating('Built Shopify and Magento integrations for six retail clients.')
            ->create(['created_by' => $va->id]);

        foreach (['PostgreSQL query tuning and indexing', 'Laravel, PHP 8, Pest'] as $statement) {
            Claim::factory()->forProfile($profile)->stating($statement)->verified($candidate)
                ->create(['kind' => ClaimKind::Skill, 'created_by' => $candidate->id]);
        }

        $invoiceProject = Project::factory()->forProfile($profile)->verified($candidate)->create([
            'employment_id' => $current->id,
            'name' => 'Invoice engine rewrite',
            'role' => 'Tech lead',
            'start_date' => '2023-01-01',
            'end_date' => '2023-10-01',
            'summary' => 'Replaced the legacy invoicing cron jobs with queued, idempotent jobs.',
            'created_by' => $candidate->id,
        ]);

        Project::factory()->forProfile($profile)->create([
            'employment_id' => $previous->id,
            'name' => 'Shopify ERP integration',
            'role' => 'Developer',
            'client_name' => null,
            'summary' => 'Proposed by a VA: order sync between Shopify and the client ERP.',
            'created_by' => $va->id,
        ]);

        $auditTrail = Project::factory()->forProfile($profile)->verified($candidate)->create([
            'name' => 'laravel-audit-trail (open source)',
            'role' => 'Maintainer',
            'url' => 'https://example.com/laravel-audit-trail',
            'summary' => 'Small package for append-only audit logs.',
            'created_by' => $candidate->id,
        ]);

        Project::factory()->forProfile($profile)->archived()->create([
            'name' => 'Old portfolio site',
            'created_by' => $candidate->id,
        ]);

        $retries = $verifiedClaim($invoiceProject, 'Designed the retry and dead-letter handling for failed invoice jobs.');
        $installs = $verifiedClaim($auditTrail, 'Package with over 1,000 monthly installs on Packagist.');

        $skill = [];

        foreach (['PHP', 'Laravel', 'PostgreSQL', 'Redis', 'Pest', 'React', 'TypeScript', 'Next.js', 'Ruby', 'Rails', 'Docker', 'AWS'] as $name) {
            $skill[$name] = Skill::factory()->inWorkspace($workspace)->create(['name' => $name, 'created_by' => $candidate->id])->ulid;
        }

        $degree = Credential::factory()->forProfile($profile)->verified($candidate)->create([
            'institution' => 'University of Leeds',
            'name' => 'BSc',
            'field_of_study' => 'Computer Science',
            'start_date' => '2013-09-01',
            'end_date' => '2016-07-01',
            'created_by' => $candidate->id,
        ]);
        $aws = Credential::factory()->forProfile($profile)->certification()->verified($candidate)->create(['created_by' => $candidate->id]);

        $english = CandidateLanguage::create(['workspace_id' => $workspace->id, 'candidate_profile_id' => $profile->id, 'language' => 'English', 'proficiency' => 'native', 'position' => 1]);
        $german = CandidateLanguage::create(['workspace_id' => $workspace->id, 'candidate_profile_id' => $profile->id, 'language' => 'German', 'proficiency' => 'professional', 'position' => 2]);
        $github = CandidateLink::create(['workspace_id' => $workspace->id, 'candidate_profile_id' => $profile->id, 'kind' => 'github', 'url' => 'https://github.com/alex-morgan-dev', 'position' => 1]);
        $portfolio = CandidateLink::create(['workspace_id' => $workspace->id, 'candidate_profile_id' => $profile->id, 'kind' => 'portfolio', 'url' => 'https://alexmorgan.example.com', 'position' => 2]);

        $shared = [
            'education' => [['credential' => $degree->ulid]],
            'languages' => [$english->ulid, $german->ulid],
            'links' => [$github->ulid, $portfolio->ulid],
        ];

        $this->seedResumes($profile, [
            'PHP focused' => [
                'default' => true,
                'title' => 'Senior PHP Engineer',
                'summary' => 'PHP and Laravel engineer with eight years of building billing and e-commerce backends. I like making slow, fragile jobs fast and boring.',
                'skills' => [
                    ['label' => 'Backend', 'items' => [$skill['PHP'], $skill['Laravel'], $skill['PostgreSQL'], $skill['Redis']]],
                    ['label' => 'Testing & ops', 'items' => [$skill['Pest'], $skill['Docker'], $skill['AWS']]],
                ],
                'experience' => [
                    $this->job($current, 'Senior PHP Developer', [$lead, $invoiceRun, $mentoring], [$this->project($invoiceProject, [$retries])]),
                    $this->job($previous, null, [$integrations]),
                ],
                'projects' => [$this->project($auditTrail, [$installs])],
                'certifications' => [['credential' => $aws->ulid]],
                ...$shared,
            ],
            'React focused' => [
                'title' => 'Senior Full-stack Engineer (React)',
                'summary' => 'Full-stack engineer who builds React and TypeScript front ends on top of solid Laravel APIs.',
                'skills' => [
                    ['label' => 'Frontend', 'items' => [$skill['React'], $skill['TypeScript']]],
                    ['label' => 'Backend', 'items' => [$skill['Laravel'], $skill['PHP'], $skill['PostgreSQL']]],
                ],
                'experience' => [
                    $this->job($current, 'Senior Full-stack Developer', [$dashboard, $pricing, $lead]),
                    $this->job($previous),
                ],
                ...$shared,
            ],
            'Next.js focused' => [
                'title' => 'Senior Full-stack Engineer (Next.js)',
                'summary' => 'Builds fast, server-rendered Next.js sites backed by well-tested APIs.',
                'skills' => [
                    ['label' => null, 'items' => [$skill['Next.js'], $skill['React'], $skill['TypeScript'], $skill['AWS']]],
                ],
                'experience' => [
                    $this->job($current, 'Senior Full-stack Developer', [
                        ['claim' => $pricing->ulid, 'text' => 'Rebuilt the public pricing pages in Next.js (SSR), raising Lighthouse performance from 62 to 95.'],
                        $dashboard,
                    ]),
                ],
                ...$shared,
            ],
            'Ruby focused' => [
                'title' => 'Senior Backend Engineer (Ruby & PHP)',
                'summary' => 'Backend engineer comfortable in Ruby on Rails and Laravel, focused on reliable background processing.',
                'skills' => [
                    ['label' => 'Languages', 'items' => [$skill['Ruby'], $skill['PHP']]],
                    ['label' => 'Frameworks', 'items' => [$skill['Rails'], $skill['Laravel']]],
                ],
                'experience' => [
                    $this->job($current, 'Senior Backend Developer', [$invoiceRun, $mentoring]),
                    $this->job($previous, 'Ruby & PHP Developer', [$rails]),
                ],
                ...$shared,
            ],
        ]);

        $this->seedAnswers($profile, $candidate, [
            [AnswerCategory::Motivation, 'Why do you want to work here?', 'I want to work on payments products where reliability matters. Your focus on small merchants matches what I have built at Northwind for the last four years.', [], true],
            [AnswerCategory::Behavioural, 'Tell us about a time you improved performance.', 'At Northwind the nightly invoice run took four hours and often overlapped the morning peak. I split it into queued, idempotent jobs and it now finishes in twenty minutes.', [$invoiceRun->ulid], true],
            [AnswerCategory::Strengths, 'What is your greatest strength?', 'Turning slow, fragile processes into simple, well-tested ones, and helping juniors do the same.', [$invoiceRun->ulid, $mentoring->ulid], true],
            [AnswerCategory::Availability, 'What is your notice period?', 'One month.', [], true],
            [AnswerCategory::Salary, 'What are your salary expectations?', 'Around £85,000, depending on the overall package.', [], false],
        ]);

        $this->seedApplications($profile, $candidate, $va);
    }

    /**
     * A small pipeline: one per open stage plus a rejection, with deadlines around today.
     */
    private function seedApplications(CandidateProfile $profile, User $candidate, User $va): void
    {
        $jobDescription = fn (string $company, string $stack): string => "{$company} is hiring a senior engineer to join our platform team.\n\n"
            ."What you will do:\n- Design and build {$stack} services used by thousands of customers\n- Improve reliability, observability and performance\n- Mentor other engineers and review code\n\n"
            ."What we are looking for:\n- 6+ years of professional software development\n- Strong {$stack} experience\n- Experience with PostgreSQL and queues\n- Clear written communication\n\n"
            ."Nice to have:\n- Payments or fintech background\n- AWS\n\nBenefits: hybrid working, 30 days holiday, learning budget.";

        $applications = [
            ['Fabrikam Pay', 'Senior Backend Engineer (PHP)', 'London (hybrid)', ApplicationStage::Saved, now()->addDays(2), 'PHP and Laravel', $candidate],
            ['Contoso Commerce', 'Staff Engineer, Payments', 'Remote (UK)', ApplicationStage::Preparing, now()->addDays(6), 'PHP', $va],
            ['Tailspin Labs', 'Senior Full-stack Engineer', 'Manchester (hybrid)', ApplicationStage::Applied, null, 'React and TypeScript', $candidate],
            ['Adatum Health', 'Senior Software Engineer', 'Remote (EU)', ApplicationStage::Interviewing, null, 'Ruby on Rails', $va],
            ['Litware', 'Backend Engineer', 'London', ApplicationStage::Rejected, null, 'Go', $candidate],
        ];

        foreach ($applications as [$company, $role, $location, $stage, $deadline, $stack, $owner]) {
            JobApplication::factory()
                ->forProfile($profile)
                ->inStage($stage)
                ->withJobDescription($jobDescription($company, $stack))
                ->create([
                    'company_name' => $company,
                    'role_title' => $role,
                    'location' => $location,
                    'employment_type' => EmploymentType::FullTime,
                    'deadline_on' => $deadline,
                    'source_url' => 'https://jobs.example.com/'.str($company)->slug(),
                    'owner_user_id' => $owner->id,
                ]);
        }
    }

    /**
     * Approved answers, plus drafts as a VA would leave them.
     *
     * @param  list<array{AnswerCategory, string, string, list<string>, bool}>  $answers
     */
    private function seedAnswers(CandidateProfile $profile, User $candidate, array $answers): void
    {
        foreach ($answers as [$category, $question, $body, $claimUlids, $approved]) {
            $factory = AnswerLibraryEntry::factory()->forProfile($profile)->answering($body, $claimUlids);

            ($approved ? $factory->approved($candidate) : $factory)->create(['question' => $question, 'category' => $category]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $resumes
     */
    private function seedResumes(CandidateProfile $profile, array $resumes): void
    {
        foreach ($resumes as $name => $resume) {
            $factory = ResumeVariant::factory()->forProfile($profile)->withDocument(array_intersect_key(
                $resume,
                array_flip(['title', 'summary', 'experience', 'projects', 'skills', 'education', 'certifications', 'languages', 'links']),
            ));

            ($resume['default'] ?? false ? $factory->default() : $factory)
                ->create(['name' => $name, 'created_by' => $profile->user_id]);
        }
    }

    /**
     * @param  list<Claim|array{claim: string, text: string}>  $bullets
     * @param  list<array<string, mixed>>  $projects
     * @return array<string, mixed>
     */
    private function job(Employment $employment, ?string $displayTitle = null, array $bullets = [], array $projects = []): array
    {
        return [
            'employment' => $employment->ulid,
            'display_title' => $displayTitle,
            'description' => null,
            'bullets' => $this->bullets($bullets),
            'projects' => $projects,
        ];
    }

    /**
     * @param  list<Claim>  $bullets
     * @return array<string, mixed>
     */
    private function project(Project $project, array $bullets = []): array
    {
        return ['project' => $project->ulid, 'description' => null, 'bullets' => $this->bullets($bullets)];
    }

    /**
     * @param  list<Claim|array{claim: string, text: string}>  $bullets
     * @return list<array{claim: string, text: string|null}>
     */
    private function bullets(array $bullets): array
    {
        return array_map(
            fn (Claim|array $bullet): array => $bullet instanceof Claim ? ['claim' => $bullet->ulid, 'text' => null] : $bullet,
            $bullets,
        );
    }
}

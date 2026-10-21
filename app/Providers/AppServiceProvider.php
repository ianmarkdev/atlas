<?php

namespace App\Providers;

use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\Skill;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();

        // Short, stable type names in polymorphic columns (audit_events.subject_type, later claim subjects).
        Relation::morphMap([
            'user' => User::class,
            'workspace' => Workspace::class,
            'candidate_profile' => CandidateProfile::class,
            'employment' => Employment::class,
            'project' => Project::class,
            'claim' => Claim::class,
            'credential' => Credential::class,
            'skill' => Skill::class,
            'knowledge_proposal' => KnowledgeProposal::class,
            'answer_library_entry' => AnswerLibraryEntry::class,
            'resume_variant' => ResumeVariant::class,
            'job_application' => JobApplication::class,
            'application_resume' => ApplicationResume::class,
            'application_question' => ApplicationQuestion::class,
        ]);

        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->save();
            }
        });
    }

    /**
     * Candidate-only abilities. Later milestones add `verify-claim` and `approve-proposal`.
     */
    protected function configureGates(): void
    {
        Gate::define('manage-vas', fn (User $user): bool => $user->isCandidate());
        Gate::define('manage-budget', fn (User $user): bool => $user->isCandidate());
        Gate::define('edit-candidate-profile', fn (User $user): bool => $user->isCandidate());
        Gate::define('edit-resumes', fn (User $user): bool => $user->isCandidate());
        Gate::define('approve-proposals', fn (User $user): bool => $user->isCandidate());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

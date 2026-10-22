<?php

use App\Http\Controllers\Applications\ApplicationQuestionController;
use App\Http\Controllers\Applications\ApplicationResumeController;
use App\Http\Controllers\Applications\ApplicationReviewController;
use App\Http\Controllers\Applications\ClarificationController;
use App\Http\Controllers\Applications\JdCollectionController;
use App\Http\Controllers\Applications\JobAnalysisController;
use App\Http\Controllers\Applications\JobApplicationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Knowledge\AnswerLibraryController;
use App\Http\Controllers\Knowledge\ClaimController;
use App\Http\Controllers\Knowledge\CredentialController;
use App\Http\Controllers\Knowledge\EmploymentController;
use App\Http\Controllers\Knowledge\KnowledgeController;
use App\Http\Controllers\Knowledge\ProjectController;
use App\Http\Controllers\Knowledge\ProposalController;
use App\Http\Controllers\Knowledge\SearchPlaygroundController;
use App\Http\Controllers\Knowledge\SkillController;
use App\Http\Controllers\Profile\ContactController;
use App\Http\Controllers\Profile\LanguageController;
use App\Http\Controllers\Profile\LinkController;
use App\Http\Controllers\Profile\ResumeController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'tenant', 'candidate.two-factor'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Profile: contact details + resumes (resume-first authoring, D9).
    Route::prefix('profile')->group(function () {
        Route::get('/', [ResumeController::class, 'index'])->name('resumes.index');

        Route::middleware('can:edit-candidate-profile')->group(function () {
            Route::get('contact', [ContactController::class, 'edit'])->name('contact.edit');
            Route::patch('contact', [ContactController::class, 'update'])->name('contact.update');
            Route::post('contact/languages', [LanguageController::class, 'store'])->name('contact.languages.store');
            Route::delete('contact/languages/{language}', [LanguageController::class, 'destroy'])->name('contact.languages.destroy');
            Route::post('contact/links', [LinkController::class, 'store'])->name('contact.links.store');
            Route::delete('contact/links/{link}', [LinkController::class, 'destroy'])->name('contact.links.destroy');
        });

        Route::get('resumes/{resume}', [ResumeController::class, 'show'])->name('resumes.show');

        Route::middleware('can:edit-resumes')->group(function () {
            Route::post('resumes', [ResumeController::class, 'store'])->name('resumes.store');
            Route::put('resumes/{resume}', [ResumeController::class, 'update'])->name('resumes.update');
            Route::patch('resumes/{resume}/default', [ResumeController::class, 'makeDefault'])->name('resumes.default');
            Route::patch('resumes/{resume}/archive', [ResumeController::class, 'archive'])->name('resumes.archive');
            Route::patch('resumes/{resume}/unarchive', [ResumeController::class, 'unarchive'])->name('resumes.unarchive');
            Route::post('resumes/{resume}/revisions/{revision}/restore', [ResumeController::class, 'restoreRevision'])->name('resumes.revisions.restore');
            Route::post('resumes/{resume}/claims/{claim}/apply', [ResumeController::class, 'applyBullet'])->name('resumes.apply-bullet');
        });
    });

    // Applications tracker (M7a, D10): candidate and VAs prepare applications by hand.
    Route::get('applications', [JobApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/collections', [JdCollectionController::class, 'index'])->name('collections.index');
    Route::post('applications/collections', [JdCollectionController::class, 'store'])->name('collections.store');
    Route::put('applications/collections/{collection}', [JdCollectionController::class, 'update'])->name('collections.update');
    Route::delete('applications/collections/{collection}', [JdCollectionController::class, 'destroy'])->name('collections.destroy');
    Route::patch('applications/{application}/collections', [JdCollectionController::class, 'toggle'])->name('applications.collections.toggle');
    Route::get('applications/create', [JobApplicationController::class, 'create'])->name('applications.create');
    Route::post('applications', [JobApplicationController::class, 'store'])->name('applications.store');
    Route::get('applications/{application}', [JobApplicationController::class, 'show'])->name('applications.show');
    Route::get('applications/{application}/edit', [JobApplicationController::class, 'edit'])->name('applications.edit');
    Route::put('applications/{application}', [JobApplicationController::class, 'update'])->name('applications.update');
    Route::patch('applications/{application}/stage', [JobApplicationController::class, 'stage'])->name('applications.stage');
    Route::delete('applications/{application}', [JobApplicationController::class, 'destroy'])->name('applications.destroy');
    Route::patch('applications/{application}/restore', [JobApplicationController::class, 'restore'])
        ->withTrashed()->name('applications.restore');

    // AI analysis of the job description (M7).
    Route::prefix('applications/{application}/analysis')->name('applications.analysis.')->scopeBindings()->group(function () {
        Route::get('/', [JobAnalysisController::class, 'show'])->name('show');
        Route::post('/', [JobAnalysisController::class, 'store'])->name('store');
        Route::post('{analysis}/refresh', [JobAnalysisController::class, 'refresh'])->name('refresh');
        Route::post('{analysis}/questions', [JobAnalysisController::class, 'addQuestions'])->name('questions');
    });
    Route::prefix('applications/{application}/clarifications')->name('applications.clarifications.')->scopeBindings()->group(function () {
        Route::post('{clarification}/answer', [ClarificationController::class, 'answer'])->name('answer');
        Route::post('{clarification}/dismiss', [ClarificationController::class, 'dismiss'])->name('dismiss');
    });

    // The VA workflow of one application (M7a slice D).
    Route::prefix('applications/{application}/review')->name('applications.review.')->group(function () {
        Route::patch('assign', [ApplicationReviewController::class, 'assign'])->name('assign');
        Route::patch('start', [ApplicationReviewController::class, 'start'])->name('start');
        Route::patch('submit', [ApplicationReviewController::class, 'submit'])->name('submit');
        Route::patch('request-changes', [ApplicationReviewController::class, 'requestChanges'])->name('request-changes');
        Route::patch('approve', [ApplicationReviewController::class, 'approve'])->name('approve');
        Route::patch('reopen', [ApplicationReviewController::class, 'reopen'])->name('reopen');
    });

    // The questions of one application form and their answers (M7a slice C).
    Route::prefix('applications/{application}/questions')->name('applications.questions.')->scopeBindings()->group(function () {
        Route::get('/', [ApplicationQuestionController::class, 'index'])->name('index');
        Route::post('/', [ApplicationQuestionController::class, 'store'])->name('store');
        Route::post('bulk', [ApplicationQuestionController::class, 'bulkStore'])->name('bulk');
        Route::post('cover-letter', [ApplicationQuestionController::class, 'coverLetter'])->name('cover-letter');
        Route::put('{question}', [ApplicationQuestionController::class, 'update'])->name('update');
        Route::delete('{question}', [ApplicationQuestionController::class, 'destroy'])->name('destroy');
        Route::patch('{question}/move', [ApplicationQuestionController::class, 'move'])->name('move');
        Route::put('{question}/answer', [ApplicationQuestionController::class, 'saveAnswer'])->name('answer');
        Route::patch('{question}/answer/approve', [ApplicationQuestionController::class, 'approve'])->name('approve');
        Route::patch('{question}/answer/reopen', [ApplicationQuestionController::class, 'reopen'])->name('reopen');
        Route::post('{question}/answer/library', [ApplicationQuestionController::class, 'saveToLibrary'])->name('save-to-library');
    });

    // The resume tailored for one application (M7a slice B).
    Route::prefix('applications/{application}/resume')->name('applications.resume.')->group(function () {
        Route::get('/', [ApplicationResumeController::class, 'show'])->name('show');
        Route::post('/', [ApplicationResumeController::class, 'store'])->name('store');
        Route::put('/', [ApplicationResumeController::class, 'update'])->name('update');
        Route::post('copy', [ApplicationResumeController::class, 'copy'])->name('copy');
        Route::patch('approve', [ApplicationResumeController::class, 'approve'])->name('approve');
        Route::patch('reopen', [ApplicationResumeController::class, 'reopen'])->name('reopen');
        Route::post('save-back', [ApplicationResumeController::class, 'saveBack'])->name('save-back');
        Route::post('claims/{claim}/apply', [ApplicationResumeController::class, 'applyBullet'])->name('apply-bullet');
    });

    // Library: every job, project and bullet point (the shared facts behind all resumes).
    Route::prefix('library')->name('knowledge.')->group(function () {
        Route::get('/', [KnowledgeController::class, 'index'])->name('index');

        // Retrieval debug page (M6), candidate only.
        Route::middleware('can:edit-candidate-profile')->group(function () {
            Route::get('search', [SearchPlaygroundController::class, 'index'])->name('search');
            Route::post('search/rebuild', [SearchPlaygroundController::class, 'rebuild'])->name('search.rebuild');
        });

        Route::resource('employments', EmploymentController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('employments/{employment}/verify', [EmploymentController::class, 'verify'])->name('employments.verify');
        Route::patch('employments/{employment}/archive', [EmploymentController::class, 'archive'])->name('employments.archive');
        Route::patch('employments/{employment}/restore', [EmploymentController::class, 'restore'])->name('employments.restore');

        Route::resource('projects', ProjectController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('projects/{project}/verify', [ProjectController::class, 'verify'])->name('projects.verify');
        Route::patch('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::patch('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

        Route::resource('credentials', CredentialController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('credentials/{credential}/verify', [CredentialController::class, 'verify'])->name('credentials.verify');
        Route::patch('credentials/{credential}/archive', [CredentialController::class, 'archive'])->name('credentials.archive');
        Route::patch('credentials/{credential}/restore', [CredentialController::class, 'restore'])->name('credentials.restore');

        Route::middleware('can:edit-candidate-profile')->group(function () {
            Route::post('skills', [SkillController::class, 'store'])->name('skills.store');
            Route::patch('skills/{skill}', [SkillController::class, 'update'])->name('skills.update');
            Route::delete('skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');
        });

        Route::get('answers', [AnswerLibraryController::class, 'index'])->name('answers.index');
        Route::get('answers/bulk', [AnswerLibraryController::class, 'bulkCreate'])->name('answers.bulk.create');
        Route::post('answers/bulk', [AnswerLibraryController::class, 'bulkStore'])->name('answers.bulk.store');
        Route::resource('answers', AnswerLibraryController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('answers/{answer}/approve', [AnswerLibraryController::class, 'approve'])->name('answers.approve');
        Route::patch('answers/{answer}/archive', [AnswerLibraryController::class, 'archive'])->name('answers.archive');
        Route::patch('answers/{answer}/restore', [AnswerLibraryController::class, 'restore'])->name('answers.restore');

        Route::get('proposals', [ProposalController::class, 'index'])->name('proposals.index');
        Route::middleware('can:approve-proposals')->group(function () {
            Route::patch('proposals/{proposal}/approve', [ProposalController::class, 'approve'])->name('proposals.approve');
            Route::patch('proposals/{proposal}/reject', [ProposalController::class, 'reject'])->name('proposals.reject');
        });

        Route::get('claims/bulk', [ClaimController::class, 'bulkCreate'])->name('claims.bulk.create');
        Route::post('claims/bulk', [ClaimController::class, 'bulkStore'])->name('claims.bulk.store');
        Route::resource('claims', ClaimController::class)->only(['create', 'store', 'edit', 'update']);
        Route::patch('claims/{claim}/verify', [ClaimController::class, 'verify'])->name('claims.verify');
        Route::patch('claims/{claim}/reject', [ClaimController::class, 'reject'])->name('claims.reject');
        Route::patch('claims/{claim}/archive', [ClaimController::class, 'archive'])->name('claims.archive');
        Route::patch('claims/{claim}/restore', [ClaimController::class, 'restore'])->name('claims.restore');
    });

    Route::middleware('can:manage-vas')->group(function () {
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('team/{user:ulid}/deactivate', [TeamController::class, 'deactivate'])->name('team.deactivate');
        Route::patch('team/{user:ulid}/reactivate', [TeamController::class, 'reactivate'])->name('team.reactivate');
        Route::post('team/{user:ulid}/password-link', [TeamController::class, 'passwordLink'])->name('team.password-link');
    });
});

if (app()->isLocal()) {
    Route::inertia('styleguide', 'styleguide/index')->name('styleguide');
    Route::inertia('styleguide/components', 'styleguide/components')->name('styleguide.components');
}

require __DIR__.'/settings.php';

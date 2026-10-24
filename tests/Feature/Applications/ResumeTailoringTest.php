<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\GenerationRun;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\PromptBudget;
use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\ApplicationResumeService;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Employment;
use App\Domain\Resumes\ResumeVariant;
use App\Jobs\TailorApplicationResume;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A candidate with one job, two bullets on the resume, one more verified bullet only in the
 * library, a second resume that shows the job as "Backend Lead", and a tailored resume copied
 * from the first.
 *
 * @return array{candidate: User, employment: Employment, onResume: Claim, second: Claim, libraryOnly: Claim, application: JobApplication}
 */
function aiTailoringSetup(string $jobDescription = 'Payments engineer. PostgreSQL a must.'): array
{
    $candidate = candidateWithTwoFactor();
    $employment = employmentOf($candidate);
    $onResume = claimOf($candidate, 'Cut the nightly invoice run from 4 hours to 20 minutes', $employment);
    $second = claimOf($candidate, 'Mentored two junior engineers', $employment);
    $libraryOnly = claimOf($candidate, 'Designed the PostgreSQL schema for merchant payouts', $employment);
    $job = fn (?string $title) => ['employment' => $employment->ulid, 'display_title' => $title, 'description' => null, 'projects' => [], 'bullets' => [
        ['claim' => $onResume->ulid, 'text' => null], ['claim' => $second->ulid, 'text' => null],
    ]];
    $variant = ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument(['title' => 'Senior PHP Engineer', 'summary' => 'Backend engineer.', 'experience' => [$job(null)]])->create(['name' => 'PHP focused']);
    ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument(['experience' => [$job('Backend Lead')]])->create(['name' => 'Lead focused']);
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->withJobDescription($jobDescription)->create(['company_name' => 'Fabrikam', 'role_title' => 'Payments Engineer']);
    test()->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

    return compact('candidate', 'employment', 'onResume', 'second', 'libraryOnly', 'application');
}

function aiTailoredResumeOf(User $candidate): ApplicationResume
{
    return inWorkspaceOf($candidate, fn () => ApplicationResume::query()->with('currentVersion')->sole());
}

/**
 * The model's proposal: B1/B2 are the resume's bullets, B3 the library-only one (see present()).
 *
 * @return array<string, mixed>
 */
function tailoringOutput(array $overrides = []): array
{
    return [
        'title' => 'Senior Payments Engineer',
        'summary' => 'Backend engineer who designs PostgreSQL schemas for payments.',
        'summary_evidence_ids' => ['B3'],
        'items' => [[
            'id' => 'J1',
            'display_title' => 'Backend Lead',
            'bullets' => [
                ['id' => 'B3', 'text' => 'Designed the PostgreSQL schema behind merchant payouts'],
                ['id' => 'B1', 'text' => 'Cut the nightly invoice run from 4 hours to 20 minutes, saving 30% of compute'],
            ],
        ]],
        'notes' => ['Nothing on the resume shows Kafka.'],
        ...$overrides,
    ];
}

test('AI tailoring stores a checked new version: rewordings, library bullets and titles it may use, refusals for the rest', function () {
    ['candidate' => $candidate, 'onResume' => $onResume, 'libraryOnly' => $libraryOnly, 'application' => $application] = aiTailoringSetup();
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([tailoringOutput()]));

    $this->actingAs($candidate)
        ->post(route('applications.resume.tailor', $application))
        ->assertInertiaFlash('toast.message', 'Tailoring the resume for this job. The new version appears here when ready.');

    $resume = aiTailoredResumeOf($candidate);
    $version = $resume->currentVersion;
    $job = $version->document['experience'][0];
    expect($fake->requests[0]->messages[0]['content'])
        ->toContain('<bullet id="B3" status="verified" on_resume="no">Designed the PostgreSQL schema for merchant payouts</bullet>')
        ->and($version->version_no)->toBe(2)
        ->and($version->author_type)->toBe('ai')
        ->and($version->generation_run_id)->not->toBeNull()
        ->and($resume->lock_version)->toBe(1)
        ->and($resume->generation_status)->toBeNull()
        ->and($version->document['title'])->toBe('Senior Payments Engineer')
        ->and($version->document['summary'])->toBe('Backend engineer who designs PostgreSQL schemas for payments.')
        ->and($job['display_title'])->toBe('Backend Lead')
        // The library bullet is added and reworded; the invented "30%" is refused and the shared wording kept.
        ->and($job['bullets'])->toEqual([
            ['claim' => $libraryOnly->ulid, 'text' => 'Designed the PostgreSQL schema behind merchant payouts'],
            ['claim' => $onResume->ulid, 'text' => null],
        ])
        ->and($version->ai_report['refused'])->toHaveCount(1)
        ->and($version->ai_report['refused'][0]['reason'])->toBe('adds_number')
        ->and($version->ai_report['notes'])->toBe(['Nothing on the resume shows Kafka.'])
        ->and(collect($version->facts_snapshot['library']['employments'][0]['claims'])->pluck('ulid')->all())->toContain($libraryOnly->ulid);

    $this->actingAs($candidate)
        ->get(route('applications.resume.show', $application))
        ->assertInertia(fn (Assert $page) => $page->where('resume.ai_report.refused.0.reason', 'adds_number'));
});

test('a job description too long for the window is shortened to what the resume leaves', function () {
    config(['ai.tasks.resume_tailoring.context' => 7000]);
    ['candidate' => $candidate, 'application' => $application] = aiTailoringSetup(implode("\n", array_map(fn (int $n): string => "Line {$n} of a very long job description.", range(1, 300))));
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([tailoringOutput()]));

    $this->actingAs($candidate)->post(route('applications.resume.tailor', $application));

    $request = $fake->requests[0];
    expect($request->messages[0]['content'])->toContain('Cut the nightly invoice run from 4 hours to 20 minutes')
        ->and($request->messages[0]['content'])->toContain('Line 1 of a very long job description.')
        ->and($request->messages[0]['content'])->not->toContain('Line 300 of')
        ->and(mb_strlen($request->system) + mb_strlen($request->messages[0]['content']))->toBeLessThanOrEqual((int) ((7000 - 4000) * (1 - PromptBudget::MARGIN)) * PromptBudget::CHARACTERS_PER_TOKEN)
        ->and(aiTailoredResumeOf($candidate)->generation_status)->toBeNull();
});

test('a resume too long for the window is not sent, and the failure says why', function () {
    config(['ai.tasks.resume_tailoring.context' => 4200]);
    ['candidate' => $candidate, 'application' => $application] = aiTailoringSetup();
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([tailoringOutput()]));

    $this->actingAs($candidate)->post(route('applications.resume.tailor', $application));

    $resume = aiTailoredResumeOf($candidate);
    expect($fake->requests)->toBeEmpty()
        ->and($resume->generation_status)->toBe('failed')
        ->and($resume->generation_error)->toContain("too long for the AI model's window");
});

test('a tailored version records the job description and analysis it was tailored to', function () {
    ['candidate' => $candidate, 'application' => $application] = aiTailoringSetup();
    $analysis = inWorkspaceOf($candidate, fn () => tap((new JobAnalysis)->forceFill([
        'job_application_id' => $application->id,
        'job_posting_id' => $application->posting->id,
        'status' => 'succeeded',
        'brief' => ['summary' => 'Payments engineer', 'must_haves' => [['requirement' => 'PostgreSQL']]],
    ]))->save());
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([tailoringOutput()]));

    $this->actingAs($candidate)->post(route('applications.resume.tailor', $application));

    $version = aiTailoredResumeOf($candidate)->currentVersion;
    expect($fake->requests[0]->messages[0]['content'])->toContain('- Must have: PostgreSQL')
        ->and($version->job_posting_id)->toBe($application->posting->id)
        ->and($version->job_analysis_id)->toBe($analysis->id);
});

test('the AI cannot drop a job, invent a display title or a summary number, or cite another item\'s bullets', function () {
    ['candidate' => $candidate, 'onResume' => $onResume, 'second' => $second, 'application' => $application] = aiTailoringSetup();
    app(LlmRouter::class)->fake(new FakeLlmClient([tailoringOutput([
        'summary' => 'Engineer with 12 years of payments experience.',
        'items' => [
            ['id' => 'J1', 'include' => false, 'display_title' => 'Head of Payments', 'bullets' => [['id' => 'B9', 'text' => 'Invented']]],
        ],
    ])]));

    $this->actingAs($candidate)->post(route('applications.resume.tailor', $application));

    $version = aiTailoredResumeOf($candidate)->currentVersion;
    $job = $version->document['experience'][0];
    expect(collect($version->ai_report['refused'])->pluck('reason')->all())->toEqualCanonicalizing(['job_kept', 'title_not_in_use', 'not_item_bullet', 'no_bullets_left', 'summary_number'])
        ->and($job['display_title'])->toBeNull()
        ->and(array_column($job['bullets'], 'claim'))->toBe([$onResume->ulid, $second->ulid])
        ->and($version->document['summary'])->toBe('Backend engineer.');
});

test('a VA can tailor, but the AI cannot change display titles for them', function () {
    ['candidate' => $candidate, 'application' => $application] = aiTailoringSetup();
    app(LlmRouter::class)->fake(new FakeLlmClient([tailoringOutput()]));

    $this->actingAs(vaOf($candidate))->post(route('applications.resume.tailor', $application));

    $version = aiTailoredResumeOf($candidate)->currentVersion;
    expect($version->document['experience'][0]['display_title'])->toBeNull()
        ->and(collect($version->ai_report['refused'])->pluck('reason')->all())->toContain('title_candidate_only');
});

test('tailoring is refused on an approved resume, and an unusable answer is recorded as a failure', function () {
    ['candidate' => $candidate, 'application' => $application] = aiTailoringSetup();
    app(LlmRouter::class)->fake(new FakeLlmClient(['not json', '{"title": 1}']));

    $this->actingAs($candidate)->post(route('applications.resume.tailor', $application));

    $resume = aiTailoredResumeOf($candidate);
    expect($resume->generation_status)->toBe('failed')
        ->and($resume->generation_error)->toContain('not usable')
        ->and($resume->currentVersion->version_no)->toBe(1);

    $this->actingAs($candidate)->patch(route('applications.resume.approve', $application))->assertSessionHasNoErrors();

    $this->actingAs($candidate)
        ->post(route('applications.resume.tailor', $application))
        ->assertForbidden();
});

test('an AI version is discarded when the resume was saved while the AI worked', function () {
    ['candidate' => $candidate] = aiTailoringSetup();
    $resume = aiTailoredResumeOf($candidate);
    $staleVersionId = $resume->current_version_id - 1;

    inWorkspaceOf($candidate, function () use ($resume, $staleVersionId, $candidate): void {
        $run = GenerationRun::query()->forceCreate([
            'task' => 'resume_tailoring', 'provider' => 'fake', 'model' => 'fake', 'prompt_key' => 'resume_tailoring', 'prompt_version' => 1, 'status' => 'succeeded', 'request_hash' => str_repeat('0', 64),
        ]);

        app(ApplicationResumeService::class)->storeTailored($resume, $staleVersionId, $resume->document(), [], $run, ['refused' => [], 'notes' => []], $candidate);
    });

    $resume = aiTailoredResumeOf($candidate);
    expect($resume->currentVersion->version_no)->toBe(1)
        ->and($resume->generation_status)->toBe('failed')
        ->and($resume->generation_error)->toContain('discarded');
});

test('tailoring whose job the queue gave up on is failed instead of left running', function () {
    Queue::fake();
    ['candidate' => $candidate, 'application' => $application] = aiTailoringSetup();
    $this->actingAs($candidate)->post(route('applications.resume.tailor', $application));

    (new TailorApplicationResume($application->workspace_id, aiTailoredResumeOf($candidate)->id))->failed();

    expect(aiTailoredResumeOf($candidate)->only(['generation_status', 'generation_error']))
        ->toBe(['generation_status' => 'failed', 'generation_error' => 'Tailoring stopped unexpectedly. Try again.']);
});

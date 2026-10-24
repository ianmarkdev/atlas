<?php

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobApplication;
use App\Jobs\AnalyseJobApplication;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * An application as Career VA saves a capture: owned by the VA who captured it.
 *
 * @param  array<string, mixed>  $attributes
 */
function capturedApplicationOf(User $candidate, User $owner, array $attributes = [], ?string $jobDescription = 'Senior engineer. PostgreSQL.', ?ApplicationStage $stage = null): JobApplication
{
    $factory = JobApplication::factory()->forProfile(profileOf($candidate));
    $factory = $stage === null ? $factory : $factory->inStage($stage);
    $factory = $jobDescription === null ? $factory : $factory->withJobDescription($jobDescription);

    return $factory->create(['owner_user_id' => $owner->id, ...$attributes]);
}

test('the scheduler queues an analysis for each new open application with a job description, once', function () {
    Queue::fake();
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    $new = capturedApplicationOf($candidate, $va);
    capturedApplicationOf($candidate, $va, jobDescription: null);
    capturedApplicationOf($candidate, $va, ['created_at' => now()->subDays(15)]);
    capturedApplicationOf($candidate, $va, stage: ApplicationStage::Rejected);
    capturedApplicationOf($candidate, $va)->delete();
    $analysedBefore = capturedApplicationOf($candidate, $va);
    inWorkspaceOf($candidate, fn () => JobAnalysis::query()->forceCreate([
        'job_application_id' => $analysedBefore->id, 'job_posting_id' => $analysedBefore->posting->id, 'status' => 'failed',
    ]));

    $this->artisan('app:analyse-new-applications')->assertSuccessful();
    $this->artisan('app:analyse-new-applications')->assertSuccessful();

    $queued = inWorkspaceOf($candidate, fn () => JobAnalysis::query()->where('status', 'queued')->get());
    expect($queued)->toHaveCount(1)
        ->and($queued->sole()->job_application_id)->toBe($new->id)
        ->and($queued->sole()->requested_by)->toBe($va->id);
    Queue::assertPushed(AnalyseJobApplication::class, 1);
});

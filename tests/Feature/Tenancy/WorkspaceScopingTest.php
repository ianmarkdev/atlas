<?php

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Database\QueryException;

test('tenant models only return rows of the workspace in context', function () {
    $alexWorkspace = Workspace::factory()->create();
    $samWorkspace = Workspace::factory()->create();
    $alex = CandidateProfile::factory()->create(['workspace_id' => $alexWorkspace->id, 'display_name' => 'Alex Morgan']);
    CandidateProfile::factory()->create(['workspace_id' => $samWorkspace->id, 'display_name' => 'Sam Lee']);

    $visible = app(TenantContext::class)->run($alexWorkspace, fn () => CandidateProfile::query()->pluck('id')->all());

    expect($visible)->toBe([$alex->id]);
});

test('querying a tenant model without a workspace in context throws instead of returning rows', function () {
    CandidateProfile::factory()->create();

    CandidateProfile::query()->get();
})->throws(LogicException::class, 'No workspace is set in the tenant context.');

test('a tenant model created without a workspace id takes the workspace in context', function () {
    $workspace = Workspace::factory()->create();

    $event = app(TenantContext::class)->run($workspace, fn () => app(AuditLogger::class)->record('test.recorded'));

    expect($event->workspace_id)->toBe($workspace->id)
        ->and($event->actor_type)->toBe('system');
});

test('the job middleware runs the job inside its workspace and restores the context afterwards', function () {
    $workspace = Workspace::factory()->create();
    $context = app(TenantContext::class);

    $seenWorkspaceId = (new WithWorkspaceContext($workspace->id))
        ->handle(new stdClass, fn () => $context->workspaceId());

    expect($seenWorkspaceId)->toBe($workspace->id)
        ->and($context->hasWorkspace())->toBeFalse();
});

test('audit events cannot be edited once written', function () {
    $workspace = Workspace::factory()->create();
    $event = app(TenantContext::class)->run($workspace, fn () => app(AuditLogger::class)->record('test.recorded'));

    app(TenantContext::class)->run($workspace, fn () => AuditEvent::query()->whereKey($event->id)->update(['action' => 'tampered']));
})->throws(QueryException::class, 'audit_events is append-only');

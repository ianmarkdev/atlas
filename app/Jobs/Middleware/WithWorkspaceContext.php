<?php

namespace App\Jobs\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Closure;

/**
 * Restores the tenant context inside a queued job. Every tenant job passes the workspace
 * it was dispatched for: `public function middleware(): array { return [new WithWorkspaceContext($this->workspaceId)]; }`
 */
class WithWorkspaceContext
{
    public function __construct(public int $workspaceId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);

        return app(TenantContext::class)->run($workspace, fn () => $next($job));
    }
}

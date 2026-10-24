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
        return self::run($this->workspaceId, fn () => $next($job));
    }

    /**
     * Run in the workspace's tenant context outside the middleware, e.g. in a job's `failed()`,
     * which the queue calls without it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function run(int $workspaceId, callable $callback): mixed
    {
        $workspace = Workspace::query()->findOrFail($workspaceId);

        return app(TenantContext::class)->run($workspace, $callback);
    }
}

<?php

namespace App\Domain\Tenancy;

use LogicException;

/**
 * Holds the workspace of the current request or job. Registered as a scoped
 * singleton, so it is reset between requests and queued jobs.
 */
class TenantContext
{
    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function clear(): void
    {
        $this->workspace = null;
    }

    public function hasWorkspace(): bool
    {
        return $this->workspace !== null;
    }

    /**
     * @throws LogicException when no workspace has been set (fail closed).
     */
    public function workspace(): Workspace
    {
        return $this->workspace ?? throw new LogicException('No workspace is set in the tenant context.');
    }

    public function workspaceId(): int
    {
        return $this->workspace()->id;
    }

    /**
     * Run a callback with the given workspace as context, restoring the previous one afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}

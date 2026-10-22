<?php

namespace App\Http\Middleware;

use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'role' => $request->user()?->role()?->value,
                'can' => [
                    'manageVas' => $request->user()?->can('manage-vas') ?? false,
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Lazy: resolved when the page renders, after the tenant context is set. What the user decides.
            'pendingProposals' => fn (): int => $request->user() !== null && app(TenantContext::class)->hasWorkspace()
                ? KnowledgeProposal::query()
                    ->where('status', ProposalStatus::Pending)
                    ->when(
                        $request->user()->can('approve-proposals'),
                        fn ($query) => $query->where(fn ($mine) => $mine->whereNull('reviewer_user_id')->orWhere('reviewer_user_id', $request->user()->id)),
                        fn ($query) => $query->where('reviewer_user_id', $request->user()->id),
                    )
                    ->count()
                : 0,
        ];
    }
}

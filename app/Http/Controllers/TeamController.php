<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\MembershipService;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\Team\StoreVaRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Candidate-only management of VA accounts (route middleware `can:manage-vas`).
 */
class TeamController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private MembershipService $memberships,
        private AuditLogger $audit,
    ) {}

    /**
     * Show the VA accounts and seat usage.
     */
    public function index(): Response
    {
        $workspace = $this->context->workspace();

        $vas = $this->vaQuery()
            ->orderBy('name')
            ->get()
            ->map(fn (User $va): array => [
                'ulid' => $va->ulid,
                'name' => $va->name,
                'email' => $va->email,
                'is_active' => $va->is_active,
                'last_login_at' => $va->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('team/index', [
            'vas' => $vas,
            'seatLimit' => (int) config('app.max_vas'),
            'activeVaCount' => $this->memberships->activeVaCount($workspace),
        ]);
    }

    /**
     * Create a VA account and show the candidate a link to share for setting a password.
     */
    public function store(StoreVaRequest $request): RedirectResponse
    {
        $va = $this->memberships->createVa(
            $this->context->workspace(),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->user(),
        );

        $this->flashPasswordLink($va, __('VA account created.'));

        return to_route('team.index');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->ensureVaInWorkspace($user);

        $this->memberships->deactivateVa($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can no longer sign in.', ['name' => $user->name])]);

        return to_route('team.index');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->ensureVaInWorkspace($user);

        $this->memberships->reactivateVa($this->context->workspace(), $user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can sign in again.', ['name' => $user->name])]);

        return to_route('team.index');
    }

    /**
     * Create a fresh set-password link, e.g. when the first one expired.
     */
    public function passwordLink(Request $request, User $user): RedirectResponse
    {
        $this->ensureVaInWorkspace($user);

        $this->audit->record('member.password_link_created', $user, [], $request->user());

        $this->flashPasswordLink($user, __('New set-password link created.'));

        return to_route('team.index');
    }

    /**
     * Responds 404 (not 403) for anyone who is not a VA of this workspace, so the response
     * does not confirm that the account exists.
     */
    private function ensureVaInWorkspace(User $user): void
    {
        abort_unless($this->vaQuery()->whereKey($user->id)->exists(), HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * @return Builder<User>
     */
    private function vaQuery(): Builder
    {
        $workspaceId = $this->context->workspaceId();

        return User::query()->whereHas('membership', fn ($query) => $query
            ->where('workspace_id', $workspaceId)
            ->where('role', Role::Va));
    }

    private function flashPasswordLink(User $va, string $message): void
    {
        $token = Password::broker()->createToken($va);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        Inertia::flash('passwordLink', [
            'email' => $va->email,
            'url' => route('password.reset', ['token' => $token, 'email' => $va->email]),
            'expiresInMinutes' => (int) config('auth.passwords.users.expire'),
        ]);
    }
}

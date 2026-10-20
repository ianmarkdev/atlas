<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates and (de)activates VA accounts. The seat limit (`app.max_vas`) is checked under a
 * row lock on the workspace, so two concurrent requests cannot both take the last seat.
 */
class MembershipService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Create a VA account. The random password is never shown; the VA sets their own via a
     * reset link the candidate shares. The email counts as verified because the candidate vouches for it.
     *
     * @throws ValidationException when every VA seat is taken
     */
    public function createVa(Workspace $workspace, string $name, string $email, User $createdBy): User
    {
        return DB::transaction(function () use ($workspace, $name, $email, $createdBy): User {
            $this->lockWorkspaceAndEnsureFreeSeat($workspace, 'email');

            $user = User::create([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => Str::password(32),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $workspace->memberships()->create([
                'user_id' => $user->id,
                'role' => Role::Va,
                'created_by' => $createdBy->id,
            ]);

            $this->audit->record('member.created', $user, ['role' => Role::Va->value], $createdBy);

            return $user;
        });
    }

    public function deactivateVa(User $va, User $actor): void
    {
        DB::transaction(function () use ($va, $actor): void {
            $va->forceFill(['is_active' => false])->save();

            $this->audit->record('member.deactivated', $va, [], $actor);
        });
    }

    /**
     * @throws ValidationException when every VA seat is taken
     */
    public function reactivateVa(Workspace $workspace, User $va, User $actor): void
    {
        DB::transaction(function () use ($workspace, $va, $actor): void {
            $this->lockWorkspaceAndEnsureFreeSeat($workspace, 'seats');

            $va->forceFill(['is_active' => true])->save();

            $this->audit->record('member.reactivated', $va, [], $actor);
        });
    }

    public function activeVaCount(Workspace $workspace): int
    {
        return $workspace->memberships()
            ->where('role', Role::Va)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->count();
    }

    /**
     * @throws ValidationException
     */
    private function lockWorkspaceAndEnsureFreeSeat(Workspace $workspace, string $errorKey): void
    {
        Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();

        $seatLimit = (int) config('app.max_vas');

        if ($this->activeVaCount($workspace) >= $seatLimit) {
            throw ValidationException::withMessages([
                $errorKey => __('All :count VA seats are in use. Deactivate a VA first.', ['count' => $seatLimit]),
            ]);
        }
    }
}

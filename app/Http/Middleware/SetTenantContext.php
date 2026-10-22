<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the signed-in user's workspace into the tenant context. Signs out deactivated
 * users (this also covers passkey logins, which bypass the password check) and rejects
 * users without a membership.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            // API tokens have no session to end: refuse the request instead.
            abort_unless($request->hasSession(), Response::HTTP_FORBIDDEN, __('This account has been deactivated.'));

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $membership = $user?->membership;

        abort_if($membership === null, Response::HTTP_FORBIDDEN);

        $this->context->set($membership->workspace);

        return $next($request);
    }
}

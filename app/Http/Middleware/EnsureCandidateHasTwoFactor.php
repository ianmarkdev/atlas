<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The candidate approves facts and controls the whole workspace, so their account must use
 * two-factor authentication before reaching the app (`app.require_candidate_two_factor`).
 */
class EnsureCandidateHasTwoFactor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('app.require_candidate_two_factor')
            && $user?->isCandidate()
            && ! $user->hasEnabledTwoFactorAuthentication()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Set up two-factor authentication to continue.')]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}

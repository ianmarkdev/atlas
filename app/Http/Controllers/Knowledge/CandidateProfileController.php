<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\CandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\UpdateCandidateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The candidate's display name and headline (route middleware `can:edit-candidate-profile`).
 */
class CandidateProfileController extends Controller
{
    public function edit(): Response
    {
        $profile = CandidateProfile::current();

        return Inertia::render('knowledge/profile', [
            'profile' => [
                'display_name' => $profile->display_name,
                'headline' => $profile->headline,
            ],
        ]);
    }

    public function update(UpdateCandidateProfileRequest $request, AuditLogger $audit): RedirectResponse
    {
        $profile = CandidateProfile::current();
        $profile->update($request->validated());

        $audit->record('candidate_profile.updated', $profile, ['fields' => array_keys($profile->getChanges())], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('knowledge.index');
    }
}

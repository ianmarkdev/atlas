<?php

namespace App\Http\Controllers\Profile;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateLink;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\LanguageProficiency;
use App\Domain\Knowledge\LinkKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateContactRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The candidate's name and contact details, shared by every resume
 * (route middleware `can:edit-candidate-profile`).
 */
class ContactController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('profile/contact', [
            'contact' => self::contactOf(CandidateProfile::current()),
            'languages' => array_values(CandidateLanguage::query()->orderBy('position')->get()->map(LanguageController::present(...))->all()),
            'links' => array_values(CandidateLink::query()->orderBy('position')->get()->map(LinkController::present(...))->all()),
            'proficiencies' => array_map(fn (LanguageProficiency $proficiency): array => [
                'value' => $proficiency->value,
                'label' => ucfirst($proficiency->value),
            ], LanguageProficiency::cases()),
            'linkKinds' => array_map(fn (LinkKind $kind): array => [
                'value' => $kind->value,
                'label' => match ($kind) {
                    LinkKind::Github => 'GitHub',
                    LinkKind::Linkedin => 'LinkedIn',
                    default => ucfirst($kind->value),
                },
            ], LinkKind::cases()),
        ]);
    }

    public function update(UpdateContactRequest $request, AuditLogger $audit): RedirectResponse
    {
        $profile = CandidateProfile::current();
        $profile->update($request->validated());

        $audit->record('candidate_profile.updated', $profile, ['fields' => array_keys($profile->getChanges())], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact details updated.')]);

        return to_route('resumes.index');
    }

    /**
     * @return array{display_name: string, email: string|null, phone: string|null, location: string|null}
     */
    public static function contactOf(CandidateProfile $profile): array
    {
        return [
            'display_name' => $profile->display_name,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'location' => $profile->location,
        ];
    }
}

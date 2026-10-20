<?php

namespace App\Http\Controllers\Profile;

use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\LanguageProficiency;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spoken languages on the contact page (route middleware `can:edit-candidate-profile`).
 */
class LanguageController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $profile = CandidateProfile::current();
        $request->merge(['language' => PlainText::normalize((string) $request->input('language'))]);

        $validated = $request->validate([
            'language' => [
                'required', 'string', 'max:100',
                Rule::unique('candidate_languages', 'language')->where('candidate_profile_id', $profile->id),
            ],
            'proficiency' => ['required', Rule::enum(LanguageProficiency::class)],
        ], ['language.unique' => __('This language is already on your list.')]);

        CandidateLanguage::create([
            ...$validated,
            'candidate_profile_id' => $profile->id,
            'position' => (int) CandidateLanguage::query()->max('position') + 1,
        ]);

        return back();
    }

    public function destroy(CandidateLanguage $language): RedirectResponse
    {
        $language->delete();

        return back();
    }

    /**
     * @return array{ulid: string, language: string, proficiency: string}
     */
    public static function present(CandidateLanguage $language): array
    {
        return ['ulid' => $language->ulid, 'language' => $language->language, 'proficiency' => $language->proficiency->value];
    }
}

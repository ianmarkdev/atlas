<?php

namespace App\Http\Controllers\Profile;

use App\Domain\Knowledge\CandidateLink;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\LinkKind;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Portfolio, GitHub, LinkedIn and other links on the contact page
 * (route middleware `can:edit-candidate-profile`).
 */
class LinkController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'url' => PlainText::normalize((string) $request->input('url')),
            'label' => PlainText::normalize((string) $request->input('label')) ?: null,
        ]);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(LinkKind::class)],
            'label' => ['nullable', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:2048'],
        ]);

        CandidateLink::create([
            ...$validated,
            'candidate_profile_id' => CandidateProfile::current()->id,
            'position' => (int) CandidateLink::query()->max('position') + 1,
        ]);

        return back();
    }

    public function destroy(CandidateLink $link): RedirectResponse
    {
        $link->delete();

        return back();
    }

    /**
     * @return array{ulid: string, kind: string, label: string, url: string}
     */
    public static function present(CandidateLink $link): array
    {
        return ['ulid' => $link->ulid, 'kind' => $link->kind->value, 'label' => $link->displayLabel(), 'url' => $link->url];
    }
}

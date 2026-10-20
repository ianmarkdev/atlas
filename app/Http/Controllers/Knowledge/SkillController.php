<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\Skill;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The candidate's skill list (route middleware `can:edit-candidate-profile`). Resumes choose,
 * group and order skills from it.
 */
class SkillController extends Controller
{
    public const MAX_NAME_LENGTH = 100;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Add one or more skills, separated by commas or new lines. Existing skills (same name,
     * ignoring case, spaces, dots and dashes) are reused, not duplicated.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['names' => ['required', 'string', 'max:5000']]);

        // Keyed by normalised name, so "Laravel" and "laravel" in one paste count once (first spelling wins).
        $names = [];

        foreach (preg_split('/[,\n]/', (string) $request->input('names')) ?: [] as $raw) {
            $name = PlainText::normalize($raw);

            if ($name !== '') {
                $names[Skill::normalizeName($name)] ??= $name;
            }
        }

        $names = array_values($names);

        foreach ($names as $name) {
            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw ValidationException::withMessages(['names' => __('":name" is longer than :max characters.', ['name' => mb_substr($name, 0, 30).'…', 'max' => self::MAX_NAME_LENGTH])]);
            }
        }

        $skills = [];

        foreach ($names as $name) {
            $skills[] = Skill::query()->where('normalized_name', Skill::normalizeName($name))->first()
                ?? Skill::create(['name' => $name, 'created_by' => $request->user()->id]);
        }

        $this->audit->record('skills.added', null, ['count' => count($skills)], $request->user());

        if ($request->expectsJson()) {
            return response()->json(['skills' => array_map(self::present(...), $skills)], 201);
        }

        return back();
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:'.self::MAX_NAME_LENGTH]]);
        $name = PlainText::normalize($validated['name']);

        $clash = Skill::query()->where('normalized_name', Skill::normalizeName($name))->whereKeyNot($skill->id)->exists();

        if ($clash) {
            throw ValidationException::withMessages(['name' => __('You already have this skill.')]);
        }

        $skill->update(['name' => $name]);

        return back();
    }

    /**
     * Removing a skill also drops it from every resume the next time that resume is saved.
     */
    public function destroy(Request $request, Skill $skill): RedirectResponse
    {
        $skill->delete();

        $this->audit->record('skill.deleted', null, ['name' => $skill->name], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":name" removed from your skills.', ['name' => $skill->name])]);

        return back();
    }

    /**
     * @return array{ulid: string, name: string}
     */
    public static function present(Skill $skill): array
    {
        return ['ulid' => $skill->ulid, 'name' => $skill->name];
    }
}

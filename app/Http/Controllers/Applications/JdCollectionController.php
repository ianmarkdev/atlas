<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\JdCollection;
use App\Domain\Applications\JobApplication;
use App\Domain\Shared\PlainText;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Collections of applications (M7): group job descriptions, and mark a collection as the
 * evaluation set for analysis quality. Candidate and VAs both manage them; marking a
 * collection for evaluation is the candidate's.
 */
class JdCollectionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('applications/collections', [
            'collections' => JdCollection::query()->withCount('applications')->orderBy('name')->get()
                ->map(fn (JdCollection $collection): array => self::present($collection))->values()->all(),
            'canMarkEval' => $request->user()->isCandidate(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $collection = new JdCollection;
        $collection->forceFill([...$validated, 'created_by' => $request->user()->id])->save();

        return back();
    }

    public function update(Request $request, JdCollection $collection): RedirectResponse
    {
        $collection->forceFill($this->validated($request, $collection))->save();

        return back();
    }

    public function destroy(JdCollection $collection): RedirectResponse
    {
        $collection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection deleted. The applications in it are unchanged.')]);

        return back();
    }

    /**
     * Put an application into a collection, or take it out.
     */
    public function toggle(Request $request, JobApplication $application, TenantContext $tenant): RedirectResponse
    {
        Gate::authorize('update', $application);

        $validated = $request->validate([
            'collection' => ['required', 'string', Rule::exists('jd_collections', 'ulid')->where('workspace_id', $tenant->workspaceId())],
            'member' => ['required', 'boolean'],
        ]);

        $collection = JdCollection::query()->where('ulid', $validated['collection'])->firstOrFail();

        DB::transaction(function () use ($collection, $application, $validated, $request): void {
            if ($validated['member']) {
                $collection->applications()->syncWithoutDetaching([$application->id => ['workspace_id' => $application->workspace_id, 'added_by' => $request->user()->id]]);
            } else {
                $collection->applications()->detach($application->id);
            }
        });

        return back();
    }

    /**
     * @return array{ulid: string, name: string, description: ?string, use_for_eval: bool, applications: int}
     */
    public static function present(JdCollection $collection): array
    {
        return [
            'ulid' => $collection->ulid,
            'name' => $collection->name,
            'description' => $collection->description,
            'use_for_eval' => $collection->use_for_eval,
            'applications' => (int) ($collection->getAttribute('applications_count') ?? 0),
        ];
    }

    /**
     * @return array{name: string, description: ?string, use_for_eval?: bool}
     */
    private function validated(Request $request, ?JdCollection $collection = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'use_for_eval' => ['sometimes', 'boolean'],
        ]);

        $fields = [
            'name' => PlainText::normalize($validated['name']),
            'description' => isset($validated['description']) ? PlainText::normalize($validated['description'], multiline: true) ?: null : null,
        ];

        // Only the candidate decides what the evaluation set is.
        if ($request->user()->isCandidate() && array_key_exists('use_for_eval', $validated)) {
            $fields['use_for_eval'] = (bool) $validated['use_for_eval'];
        }

        return $fields;
    }
}

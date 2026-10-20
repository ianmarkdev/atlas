<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Knowledge\KnowledgeRecordService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\SaveEmploymentRequest;
use App\Http\Resources\EmploymentResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmploymentController extends Controller
{
    public function __construct(private KnowledgeRecordService $records) {}

    public function create(): Response
    {
        return Inertia::render('knowledge/employment-form', [
            'employment' => null,
            'employmentTypes' => $this->employmentTypes(),
        ]);
    }

    public function store(SaveEmploymentRequest $request): RedirectResponse
    {
        $employment = $this->records->create(new Employment($request->employmentAttributes()), $request->user());

        $this->flashSaved($employment);

        return to_route('knowledge.index');
    }

    public function edit(Employment $employment): Response
    {
        Gate::authorize('update', $employment);

        return Inertia::render('knowledge/employment-form', [
            'employment' => EmploymentResource::make($employment)->resolve(),
            'employmentTypes' => $this->employmentTypes(),
        ]);
    }

    public function update(SaveEmploymentRequest $request, Employment $employment): RedirectResponse
    {
        Gate::authorize('update', $employment);

        $this->records->update($employment, $request->employmentAttributes(), $request->integer('lock_version'), $request->user());

        $this->flashSaved($employment);

        return to_route('knowledge.index');
    }

    public function verify(Request $request, Employment $employment): RedirectResponse
    {
        Gate::authorize('verify', $employment);

        $this->records->verify($employment, $request->user());

        return to_route('knowledge.index');
    }

    public function archive(Request $request, Employment $employment): RedirectResponse
    {
        Gate::authorize('archive', $employment);

        $this->records->archive($employment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Employment archived.')]);

        return to_route('knowledge.index');
    }

    public function restore(Request $request, Employment $employment): RedirectResponse
    {
        Gate::authorize('restore', $employment);

        $this->records->restore($employment, $request->user());

        return to_route('knowledge.index', ['archived' => 1]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function employmentTypes(): array
    {
        return array_map(fn (EmploymentType $type): array => [
            'value' => $type->value,
            'label' => ucfirst(str_replace('_', ' ', $type->value)),
        ], EmploymentType::cases());
    }

    private function flashSaved(Employment $employment): void
    {
        $message = $employment->isVerified()
            ? __('Employment saved.')
            : __('Employment saved as proposed. The candidate will verify it.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}

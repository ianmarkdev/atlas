<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\CredentialType;
use App\Domain\Knowledge\KnowledgeRecordService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\SaveCredentialRequest;
use App\Http\Resources\CredentialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Education and certifications in the library. Same rules as experiences and projects.
 */
class CredentialController extends Controller
{
    public function __construct(private KnowledgeRecordService $records) {}

    public function create(Request $request): Response
    {
        return Inertia::render('knowledge/credential-form', [
            'credential' => null,
            'defaultType' => ($request->enum('type', CredentialType::class) ?? CredentialType::Education)->value,
        ]);
    }

    /**
     * JSON requests (the resume editor's "Add education/certification" dialog) get the new record back.
     */
    public function store(SaveCredentialRequest $request): RedirectResponse|JsonResponse
    {
        $credential = $this->records->create(new Credential($request->credentialAttributes()), $request->user());

        if ($request->expectsJson()) {
            return response()->json(['credential' => CredentialResource::make($credential)->resolve($request)], 201);
        }

        $this->flashSaved($credential);

        return to_route('knowledge.index');
    }

    public function edit(Credential $credential): Response
    {
        Gate::authorize('update', $credential);

        return Inertia::render('knowledge/credential-form', [
            'credential' => CredentialResource::make($credential)->resolve(),
            'defaultType' => $credential->type->value,
        ]);
    }

    public function update(SaveCredentialRequest $request, Credential $credential): RedirectResponse
    {
        Gate::authorize('update', $credential);

        $this->records->update($credential, $request->credentialAttributes(), $request->integer('lock_version'), $request->user());

        $this->flashSaved($credential);

        return to_route('knowledge.index');
    }

    public function verify(Request $request, Credential $credential): RedirectResponse
    {
        Gate::authorize('verify', $credential);

        $this->records->verify($credential, $request->user());

        return back();
    }

    public function archive(Request $request, Credential $credential): RedirectResponse
    {
        Gate::authorize('archive', $credential);

        $this->records->archive($credential, $request->user());

        return back();
    }

    public function restore(Request $request, Credential $credential): RedirectResponse
    {
        Gate::authorize('restore', $credential);

        $this->records->restore($credential, $request->user());

        return back();
    }

    private function flashSaved(Credential $credential): void
    {
        $label = $credential->type === CredentialType::Education ? __('Education') : __('Certification');
        $message = $credential->isVerified()
            ? __(':label saved.', ['label' => $label])
            : __(':label saved as proposed. The candidate will verify it.', ['label' => $label]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}

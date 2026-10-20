<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmploymentResource;
use App\Http\Resources\ProjectResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeController extends Controller
{
    /**
     * Career knowledge overview: profile, employments with their projects, standalone projects.
     * Archived records are hidden unless `?archived=1`.
     */
    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archived');
        $visible = fn (Builder $query) => $showArchived ? $query : $query->where('status', '!=', KnowledgeStatus::Archived);

        $profile = CandidateProfile::current();

        $employments = Employment::query()
            ->tap($visible)
            ->with(['projects' => fn ($query) => $query->tap($visible)->with('employment')->orderByDesc('start_date')])
            ->orderByRaw('end_date IS NOT NULL, end_date DESC')
            ->orderByDesc('start_date')
            ->get();

        $standaloneProjects = Project::query()
            ->tap($visible)
            ->whereNull('employment_id')
            ->orderByDesc('start_date')
            ->get();

        return Inertia::render('knowledge/index', [
            'profile' => [
                'display_name' => $profile->display_name,
                'headline' => $profile->headline,
            ],
            'employments' => EmploymentResource::collection($employments)->resolve(),
            'standaloneProjects' => ProjectResource::collection($standaloneProjects)->resolve(),
            'showArchived' => $showArchived,
            'canEditProfile' => $request->user()->can('edit-candidate-profile'),
        ]);
    }
}

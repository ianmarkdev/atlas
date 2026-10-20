<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use App\Domain\Resumes\ResumeUsage;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\EmploymentResource;
use App\Http\Resources\ProjectResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeController extends Controller
{
    /**
     * Career knowledge overview: profile, employments with their projects and claims, standalone
     * projects, general claims. Archived (and rejected) records are hidden unless `?archived=1`.
     */
    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archived');
        $visibleRecords = fn (Builder $query) => $showArchived ? $query : $query->where('status', '!=', KnowledgeStatus::Archived);
        $visibleClaims = fn ($query) => ($showArchived ? $query : $query->whereIn('status', ClaimStatus::live()))->with('currentRevision');

        $profile = CandidateProfile::current();

        $employments = Employment::query()
            ->tap($visibleRecords)
            ->with([
                'claims' => $visibleClaims,
                'projects' => fn ($query) => $query->tap($visibleRecords)
                    ->with(['employment', 'claims' => $visibleClaims])
                    ->orderByDesc('start_date'),
            ])
            ->orderByRaw('end_date IS NOT NULL, end_date DESC')
            ->orderByDesc('start_date')
            ->get();

        $standaloneProjects = Project::query()
            ->tap($visibleRecords)
            ->whereNull('employment_id')
            ->with(['claims' => $visibleClaims])
            ->orderByDesc('start_date')
            ->get();

        $generalClaims = Claim::query()
            ->whereNull('subject_type')
            ->tap($visibleClaims)
            ->oldest('id')
            ->get();

        return Inertia::render('knowledge/index', [
            'profile' => [
                'display_name' => $profile->display_name,
            ],
            'usedIn' => ResumeUsage::map(),
            'employments' => EmploymentResource::collection($employments)->resolve(),
            'standaloneProjects' => ProjectResource::collection($standaloneProjects)->resolve(),
            'generalClaims' => ClaimResource::collection($generalClaims)->resolve(),
            'showArchived' => $showArchived,
        ]);
    }
}

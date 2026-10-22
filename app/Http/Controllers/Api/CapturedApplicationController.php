<?php

namespace App\Http\Controllers\Api;

use App\Domain\Applications\DuplicateApplication;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\JobApplicationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CaptureJobApplicationRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saves a job listing sent by the browser extension (`POST /api/extension/applications`).
 * 201 with the new application, or 409 with the one already saved for the same listing.
 */
class CapturedApplicationController extends Controller
{
    public function __invoke(CaptureJobApplicationRequest $request, JobApplicationService $applications): JsonResponse
    {
        try {
            $application = $applications->capture(
                $request->details(),
                $request->jobDescription(),
                $request->captureMetadata(),
                $request->allowsDuplicate(),
                $request->user(),
            );
        } catch (DuplicateApplication $duplicate) {
            return response()->json([
                'message' => $duplicate->getMessage(),
                'existing' => self::summary($duplicate->existing),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => self::summary($application)], Response::HTTP_CREATED);
    }

    /**
     * @return array{ulid: string, url: string, company_name: ?string, role_title: string, stage: string, deleted: bool}
     */
    private static function summary(JobApplication $application): array
    {
        return [
            'ulid' => $application->ulid,
            'url' => route('applications.show', $application),
            'company_name' => $application->company_name,
            'role_title' => $application->role_title,
            'stage' => $application->stage->value,
            'deleted' => $application->trashed(),
        ];
    }
}

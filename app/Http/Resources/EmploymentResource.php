<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\Employment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employment
 */
class EmploymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'employer_name' => $this->employer_name,
            'title' => $this->title,
            'employment_type' => $this->employment_type?->value,
            'location' => $this->location,
            'start_date' => $this->start_date->format('Y-m'),
            'end_date' => $this->end_date?->format('Y-m'),
            'summary' => $this->summary,
            'status' => $this->status->value,
            'lock_version' => $this->lock_version,
            // Resolved to a plain list: Inertia would otherwise serialise a nested resource as `{ data: [...] }`.
            'projects' => $this->whenLoaded('projects', fn () => ProjectResource::collection($this->projects)->resolve($request)),
            'claims' => $this->whenLoaded('claims', fn () => ClaimResource::collection($this->claims)->resolve($request)),
            'can' => KnowledgeAbilities::for($request->user(), $this->resource),
        ];
    }
}

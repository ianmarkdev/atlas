<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
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
            'employment' => $this->employment?->ulid,
            'name' => $this->name,
            'client_name' => $this->client_name,
            'role' => $this->role,
            'start_date' => $this->start_date?->format('Y-m'),
            'end_date' => $this->end_date?->format('Y-m'),
            'summary' => $this->summary,
            'url' => $this->url,
            'status' => $this->status->value,
            'lock_version' => $this->lock_version,
            // Resolved to a plain list: Inertia would otherwise serialise a nested resource as `{ data: [...] }`.
            'claims' => $this->whenLoaded('claims', fn () => ClaimResource::collection($this->claims)->resolve($request)),
            'can' => KnowledgeAbilities::for($request->user(), $this->resource),
        ];
    }
}

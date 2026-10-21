<?php

namespace App\Http\Resources;

use App\Domain\Applications\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application as listed on the board and table. The job description and notes are added by
 * the detail page.
 *
 * @mixin JobApplication
 */
class JobApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'ulid' => $this->ulid,
            'company_name' => $this->company_name,
            'role_title' => $this->role_title,
            'location' => $this->location,
            'employment_type' => $this->employment_type?->value,
            'salary_text' => $this->salary_text,
            'source_url' => $this->source_url,
            'stage' => $this->stage->value,
            'review_status' => $this->review_status->value,
            'deadline_on' => $this->deadline_on?->format('Y-m-d'),
            'is_overdue' => $this->isOverdue(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}

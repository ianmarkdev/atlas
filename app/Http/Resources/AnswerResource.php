<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\AnswerLibraryEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AnswerLibraryEntry
 */
class AnswerResource extends JsonResource
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
            'question' => $this->question,
            'body' => $this->body(),
            'category' => $this->category->value,
            'claims' => $this->claimUlids(),
            'status' => $this->status->value,
            'revision_no' => $this->currentRevision?->revision_no,
            'lock_version' => $this->lock_version,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'propose' => $user?->can('propose', $this->resource) ?? false,
                'approve' => $user?->can('approve', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\AnswerLibraryRevision;
use App\Domain\Knowledge\ClaimRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One earlier wording of a bullet point or an answer, for the read-only history on edit pages.
 *
 * @mixin ClaimRevision|AnswerLibraryRevision
 */
class TextRevisionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revision = $this->resource;

        return [
            'revision_no' => $revision->revision_no,
            'text' => $revision instanceof ClaimRevision ? $revision->statement : $revision->body,
            'change_reason' => $revision->change_reason,
            'author' => $revision->author?->name,
            'from_suggestion' => str_starts_with($revision->created_via, 'proposal:'),
            'created_at' => $revision->created_at->toIso8601String(),
        ];
    }
}

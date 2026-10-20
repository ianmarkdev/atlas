<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\Claim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Claim
 */
class ClaimResource extends JsonResource
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
            'statement' => $this->statement(),
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'revision_no' => $this->currentRevision?->revision_no,
            'lock_version' => $this->lock_version,
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'verify' => $user?->can('verify', $this->resource) ?? false,
                'reject' => $user?->can('reject', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}

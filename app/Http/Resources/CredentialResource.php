<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\Credential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Credential
 */
class CredentialResource extends JsonResource
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
            'type' => $this->type->value,
            'institution' => $this->institution,
            'name' => $this->name,
            'field_of_study' => $this->field_of_study,
            'start_date' => $this->start_date?->format('Y-m'),
            'end_date' => $this->end_date?->format('Y-m'),
            'expires_on' => $this->expires_on?->format('Y-m'),
            'credential_url' => $this->credential_url,
            'summary' => $this->summary,
            'status' => $this->status->value,
            'lock_version' => $this->lock_version,
            'can' => KnowledgeAbilities::for($request->user(), $this->resource),
        ];
    }
}

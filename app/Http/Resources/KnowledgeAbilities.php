<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Models\User;

/**
 * Which actions the signed-in user may take on a knowledge record, so the UI only shows
 * buttons the policy will accept.
 */
class KnowledgeAbilities
{
    /**
     * @return array{update: bool, propose: bool, verify: bool, archive: bool, restore: bool}
     */
    public static function for(?User $user, Employment|Project|Credential $record): array
    {
        return [
            'update' => $user?->can('update', $record) ?? false,
            'propose' => $user?->can('propose', $record) ?? false,
            'verify' => $user?->can('verify', $record) ?? false,
            'archive' => $user?->can('archive', $record) ?? false,
            'restore' => $user?->can('restore', $record) ?? false,
        ];
    }
}

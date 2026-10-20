<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private ?Request $request = null) {}

    /**
     * Record an action in the current workspace. With no actor the event is attributed to the system.
     *
     * @param  array<string, mixed>  $metadata  identifiers and flags only — never content bodies
     */
    public function record(string $action, ?Model $subject = null, array $metadata = [], ?User $actor = null): AuditEvent
    {
        $ip = $this->request?->ip();

        return AuditEvent::create([
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
        ]);
    }
}

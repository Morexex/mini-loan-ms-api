<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAuditLog
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function handle(
        Model $auditable,
        string $action,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $ip = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor ? 'user' : 'system',
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}

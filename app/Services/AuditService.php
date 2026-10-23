<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditService
{
    public function record(?User $actor, string $action, mixed $subject = null, array $details = []): void
    {
        AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => is_object($subject) ? $subject::class : null,
            'subject_id' => is_object($subject) && isset($subject->id) ? $subject->id : null,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;

class AdminAuditService
{
    public static function record(User $admin, string $action, string $targetType, int $targetId, ?string $reason = null, ?array $metadata = null): AdminAuditLog
    {
        return AdminAuditLog::create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}

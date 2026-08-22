<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;

// Helper de auditoría — cada escritura en admin/* (creación de negocio,
// renovación de suscripción) pasa por acá para dejar quién, qué, sobre qué
// negocio y cuándo. Ver spec admin-api-boundary, Requirement "Audit Trail".
class AdminAudit
{
    public static function record(
        AdminUser $admin,
        string $action,
        ?int $targetUserId = null,
        array $payload = [],
        ?Request $request = null,
    ): AdminAuditLog {
        $request ??= request();

        return AdminAuditLog::create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'target_user_id' => $targetUserId,
            'payload' => $payload,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}

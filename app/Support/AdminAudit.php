<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * One-line audit entry for System Admin actions, in the same payload shape
 * Admin\ModerationController already writes (name/reason/description), so
 * the admin Activity Log can render every admin row the same way. store_id
 * is null for platform-level actions (sign-ins, account suspensions).
 */
class AdminAudit
{
    public static function record(
        Request $request,
        string $action,
        string $modelType,
        int $modelId,
        string $name,
        ?int $storeId = null,
        ?string $reason = null,
        ?int $actorId = null,
        // Done by SUTURA itself (e.g. a GCash payment confirmed by the gateway), not by whoever's request triggered it.
        bool $automatic = false,
    ): void {
        AuditLog::create([
            'store_id' => $storeId,
            'user_id' => $automatic ? null : ($actorId ?? $request->user()?->id),
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'payload' => [
                'name' => $name,
                'reason' => $reason,
                'description' => ($automatic ? 'SUTURA (automatic): ' : 'System Admin: ').str_replace('_', ' ', $action).' — '.$name,
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}

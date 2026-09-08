<?php

namespace App\Services\Okr;

use App\Models\OkrAuditLog;

/**
 * Módulo OKR (08-sep-2026) — "UNA META NO SE PUEDE MODIFICAR SILENCIOSAMENTE"
 * (sección 31/59 del pedido). Toda modificación de meta/peso/plazo/KPI/
 * responsable pasa por aquí, con motivo cuando corresponda.
 */
class OkrAuditLogger
{
    public function log(string $auditableType, int $auditableId, int $userId, string $action, ?string $field = null, mixed $oldValue = null, mixed $newValue = null, ?string $reason = null): OkrAuditLog
    {
        return OkrAuditLog::query()->create([
            'auditable_type' => $auditableType,
            'auditable_id'   => $auditableId,
            'user_id'        => $userId,
            'action'         => $action,
            'field'          => $field,
            'old_value'      => $oldValue !== null ? (string) $oldValue : null,
            'new_value'      => $newValue !== null ? (string) $newValue : null,
            'reason'         => $reason,
        ]);
    }

    public function logFieldChange(string $auditableType, int $auditableId, int $userId, string $field, mixed $oldValue, mixed $newValue, ?string $reason): OkrAuditLog
    {
        return $this->log($auditableType, $auditableId, $userId, 'field_changed', $field, $oldValue, $newValue, $reason);
    }
}

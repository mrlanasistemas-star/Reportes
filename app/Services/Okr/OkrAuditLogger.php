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
    /**
     * Registra una entrada de auditoría OKR.
     *
     * Efecto: inserta una fila en `okr_audit_logs` (modelo OkrAuditLog). Los
     * valores viejo/nuevo se guardan como texto (null se conserva como null).
     *
     * @param  string  $auditableType  Tipo de entidad auditada (clase/morph del modelo: Objective, KR, etc.).
     * @param  int  $auditableId  Id de la entidad auditada.
     * @param  int  $userId  Usuario que realizó la acción.
     * @param  string  $action  Acción realizada (p. ej. 'field_changed', 'activated', 'closed').
     * @param  string|null  $field  Campo modificado, si aplica.
     * @param  mixed  $oldValue  Valor anterior (se convierte a string).
     * @param  mixed  $newValue  Valor nuevo (se convierte a string).
     * @param  string|null  $reason  Motivo capturado por el usuario, cuando la regla lo exige.
     * @return OkrAuditLog Registro creado.
     */
    public function log(string $auditableType, int $auditableId, int $userId, string $action, ?string $field = null, mixed $oldValue = null, mixed $newValue = null, ?string $reason = null): OkrAuditLog
    {
        return OkrAuditLog::query()->create([
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'user_id' => $userId,
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue !== null ? (string) $oldValue : null,
            'new_value' => $newValue !== null ? (string) $newValue : null,
            'reason' => $reason,
        ]);
    }

    /**
     * Atajo para auditar el cambio de un campo concreto (acción 'field_changed').
     *
     * Efecto: inserta una fila en `okr_audit_logs` vía log().
     *
     * @param  string  $auditableType  Tipo de entidad auditada.
     * @param  int  $auditableId  Id de la entidad auditada.
     * @param  int  $userId  Usuario que hizo el cambio.
     * @param  string  $field  Nombre del campo modificado (meta, peso, plazo, KPI, responsable...).
     * @param  mixed  $oldValue  Valor anterior.
     * @param  mixed  $newValue  Valor nuevo.
     * @param  string|null  $reason  Motivo del cambio.
     * @return OkrAuditLog Registro creado.
     */
    public function logFieldChange(string $auditableType, int $auditableId, int $userId, string $field, mixed $oldValue, mixed $newValue, ?string $reason): OkrAuditLog
    {
        return $this->log($auditableType, $auditableId, $userId, 'field_changed', $field, $oldValue, $newValue, $reason);
    }
}

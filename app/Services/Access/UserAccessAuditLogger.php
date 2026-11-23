<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Historial de acciones de administración de usuarios y roles (prioridad 8).
 * Reutiliza la bitácora existente `okr_audit_logs` (auditable_type/id, actor,
 * acción, campo, antes/después) en vez de crear una tabla paralela — con
 * auditable_type 'user_account' o 'role'. Nunca guarda contraseñas ni tokens.
 * Si la escritura falla, la acción del administrador NO se revierte (se registra en el log).
 *
 * Lo usan los controladores de Usuarios/Roles e invitaciones para registrar
 * cada cambio y la pantalla de historial para listarlos.
 */
class UserAccessAuditLogger
{
    /** auditable_type para acciones sobre cuentas de usuario. */
    public const TYPE_USER = 'user_account';

    /** auditable_type para acciones sobre roles. */
    public const TYPE_ROLE = 'role';

    /** Etiqueta en español que se muestra en el historial para cada clave de acción. */
    public const LABELS = [
        'user_created' => 'Creó el usuario',
        'access_enabled' => 'Activó el acceso',
        'access_revoked' => 'Revocó la activación del acceso',
        'account_blocked' => 'Bloqueó la cuenta',
        'account_unblocked' => 'Desbloqueó la cuenta',
        'password_reset' => 'Restableció la contraseña',
        'invitation_accepted' => 'Envió la invitación (aceptada por el servidor de correo)',
        'invitation_failed' => 'Intentó enviar la invitación (falló)',
        'role_changed' => 'Cambió el rol',
        'employee_linked' => 'Cambió el colaborador vinculado',
        'branch_scope_changed' => 'Cambió el alcance por sucursal',
        'role_created' => 'Creó el rol',
        'role_updated' => 'Editó el rol',
        'role_deleted' => 'Eliminó el rol',
    ];

    /**
     * Inserta un renglón en `okr_audit_logs` con la acción de administración.
     *
     * Si no hay actor (p. ej. procesos de sistema) no registra nada. Cualquier
     * error de escritura se atrapa y solo se deja un warning en el log de Laravel,
     * para no revertir la acción del administrador.
     *
     * @param  User|null  $actor  Usuario que ejecutó la acción.
     * @param  string  $type  TYPE_USER o TYPE_ROLE.
     * @param  int  $id  Id del usuario o rol afectado.
     * @param  string  $action  Clave de la acción (ver LABELS).
     * @param  string|null  $field  Campo modificado, si aplica.
     * @param  string|null  $old  Valor anterior (nunca contraseñas ni tokens).
     * @param  string|null  $new  Valor nuevo (nunca contraseñas ni tokens).
     * @param  string|null  $detail  Detalle libre; se guarda en la columna `reason`.
     */
    public function log(?User $actor, string $type, int $id, string $action, ?string $field = null, ?string $old = null, ?string $new = null, ?string $detail = null): void
    {
        if (! $actor) {
            return;
        }

        try {
            DB::table('okr_audit_logs')->insert([
                'auditable_type' => $type, 'auditable_id' => $id, 'user_id' => $actor->id, 'action' => $action,
                'field' => $field, 'old_value' => $old, 'new_value' => $new, 'reason' => $detail,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar la acción de administración de usuarios.', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Últimas acciones para la pantalla (más recientes primero).
     *
     * Une la bitácora con `users` (actor y usuario objetivo) y `roles` para
     * mostrar nombres; si el usuario o rol ya no existe muestra "eliminado"
     * (para roles intenta usar old_value como nombre). Si la consulta falla
     * (tablas sin migrar) devuelve arreglo vacío.
     *
     * @param  int  $limit  Máximo de renglones a devolver.
     * @return array<int, array<string, mixed>> Renglones listos para la vista (id, type, action, label, actor, target, old_value, new_value, detail, at).
     */
    public function recent(int $limit = 150): array
    {
        try {
            $rows = DB::table('okr_audit_logs as l')
                ->leftJoin('users as actor', 'actor.id', '=', 'l.user_id')
                ->leftJoin('users as target', function ($join) {
                    $join->on('target.id', '=', 'l.auditable_id')->where('l.auditable_type', '=', self::TYPE_USER);
                })
                ->leftJoin('roles as role', function ($join) {
                    $join->on('role.id', '=', 'l.auditable_id')->where('l.auditable_type', '=', self::TYPE_ROLE);
                })
                ->whereIn('l.auditable_type', [self::TYPE_USER, self::TYPE_ROLE])
                ->orderByDesc('l.id')
                ->limit($limit)
                ->get(['l.id', 'l.auditable_type', 'l.action', 'l.old_value', 'l.new_value', 'l.reason', 'l.created_at', 'actor.name as actor_name', 'target.name as target_name', 'role.name as role_name']);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'type' => $r->auditable_type === self::TYPE_ROLE ? 'role' : 'user',
            'action' => $r->action,
            'label' => self::LABELS[$r->action] ?? $r->action,
            'actor' => $r->actor_name ?? 'Usuario eliminado',
            'target' => $r->auditable_type === self::TYPE_ROLE ? ($r->role_name ?? $r->old_value ?? 'Rol eliminado') : ($r->target_name ?? 'Usuario eliminado'),
            'old_value' => $r->old_value,
            'new_value' => $r->new_value,
            'detail' => $r->reason,
            'at' => $r->created_at,
        ])->all();
    }
}

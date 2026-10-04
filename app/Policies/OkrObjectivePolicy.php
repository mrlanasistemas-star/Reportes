<?php

namespace App\Policies;

use App\Models\OkrObjective;
use App\Models\User;

/**
 * Módulo OKR — CORRECCIÓN 09-sep-2026 (punto 11 de la auditoría): antes TODAS
 * las acciones sobre un Objective concreto (activar, recalcular, editar meta/
 * peso, check-in, evidencia, eliminar) se autorizaban con Gates GLOBALES
 * (`okr.update`, `okr.assign`, ...) sin mirar el Objective — cualquier
 * usuario autenticado podía modificar/asignar/eliminar CUALQUIER OKR, no solo
 * el suyo. Esta Policy recibe el Objective y decide según:
 *
 *   - Administrador (users.role='admin'): todo.
 *   - Responsable del Objective (objective.responsible_user_id === user.id):
 *     puede dar seguimiento (check-in, evidencia) y ajustar su propio OKR
 *     (meta/peso/recalcular) — pero NO activar (asignación es una decisión
 *     administrativa) ni eliminar.
 *   - Cualquier otro autenticado: solo lectura (mismo criterio "autenticado
 *     ve todo" que el resto de Reportería) — nunca modificar/asignar/eliminar
 *     un OKR ajeno.
 *
 * Registrada en OkrServiceProvider::boot() vía Gate::policy().
 */
class OkrObjectivePolicy
{
    /**
     * CORRECCIÓN 04-oct-2026 (cierre real OKR, punto 15): antes CUALQUIER
     * autenticado veía CUALQUIER Objective (incluyendo por URL directa
     * /okr/123) — bug de privacidad. Regla real:
     *   - ADMIN: todo.
     *   - GERENCIAL: todo (sin alcance regional/sucursal definido todavía en
     *     el modelo — mínimo vigente es ver todo el seguimiento gerencial).
     *   - COLABORADOR: solo su propio Objective — único vínculo real
     *     User↔Objective es responsible_user_id (Employee no tiene FK a
     *     users), mismo criterio que update/checkin/uploadEvidence.
     */
    public function view(User $user, OkrObjective $objective): bool
    {
        if ($this->isAdmin($user) || $user->hasManagerialAccess()) {
            return true;
        }

        return $this->isResponsible($user, $objective);
    }

    /** Editar meta/peso, recalcular progreso. */
    public function update(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user) || $this->isResponsible($user, $objective);
    }

    /**
     * Activar el Objective — decisión administrativa/gerencial (Parte 1,
     * 04-oct-2026: GERENCIAL tiene "asignación" explícita en su alcance),
     * nunca del propio responsable ni de un colaborador.
     */
    public function assign(User $user, OkrObjective $objective): bool
    {
        return $user->hasManagerialAccess();
    }

    public function delete(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user);
    }

    public function checkin(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user) || $this->isResponsible($user, $objective);
    }

    public function uploadEvidence(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user) || $this->isResponsible($user, $objective);
    }

    private function isAdmin(User $user): bool
    {
        return ($user->role ?? null) === 'admin';
    }

    private function isResponsible(User $user, OkrObjective $objective): bool
    {
        return $objective->responsible_user_id !== null && (int) $objective->responsible_user_id === (int) $user->id;
    }
}

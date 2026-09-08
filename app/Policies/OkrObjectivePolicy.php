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
    public function view(User $user, OkrObjective $objective): bool
    {
        return true; // mismo criterio que el resto de Reportería: autenticado = puede ver
    }

    /** Editar meta/peso, recalcular progreso. */
    public function update(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user) || $this->isResponsible($user, $objective);
    }

    /** Activar el Objective — decisión administrativa, nunca del propio responsable. */
    public function assign(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user);
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

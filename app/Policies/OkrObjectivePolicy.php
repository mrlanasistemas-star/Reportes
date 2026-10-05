<?php

namespace App\Policies;

use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Okr\OkrObjectiveVisibilityService;

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
    public function __construct(private readonly OkrObjectiveVisibilityService $visibility)
    {
    }

    /**
     * CORRECCIÓN 04-oct-2026 (cierre real OKR, punto 15) / 05-oct-2026
     * (identidad User↔Employee): antes CUALQUIER autenticado veía CUALQUIER
     * Objective (incluyendo por URL directa /okr/123) — bug de privacidad.
     * Regla real, centralizada en OkrObjectiveVisibilityService (misma que
     * usan Dashboard/History/Lookup/Alerts, nunca una copia separada):
     *   - ADMIN/GERENCIAL: todo.
     *   - COLABORADOR: responsible_user_id === user.id, O es el DUEÑO real
     *     del Objective individual (user.employee_id === objective.employee_id).
     */
    public function view(User $user, OkrObjective $objective): bool
    {
        return $this->visibility->canView($user, $objective);
    }

    /** Editar meta/peso, recalcular progreso. */
    public function update(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user) || $this->visibility->isResponsible($user, $objective);
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

    /**
     * 11 del cierre (05-oct-2026): el DUEÑO real del Objective (vínculo
     * employee_id) puede dar seguimiento a SU PROPIO Objective aunque
     * responsible_user_id sea su gerente — sin que esto lo autorice a
     * editar meta/peso, activar, cancelar, emitir Carta o Warning (eso sigue
     * siendo admin/responsable/gerencial exclusivamente, ver update/assign).
     */
    public function checkin(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user)
            || $this->visibility->isResponsible($user, $objective)
            || $this->visibility->isOwner($user, $objective);
    }

    public function uploadEvidence(User $user, OkrObjective $objective): bool
    {
        return $this->isAdmin($user)
            || $this->visibility->isResponsible($user, $objective)
            || $this->visibility->isOwner($user, $objective);
    }

    private function isAdmin(User $user): bool
    {
        return ($user->role ?? null) === 'admin';
    }
}

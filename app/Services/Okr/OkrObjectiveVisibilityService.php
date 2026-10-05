<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cierre real OKR (05-oct-2026) — fuente ÚNICA de "¿qué Objectives puede ver
 * este usuario?". Antes esta pregunta se respondía de formas distintas (o no
 * se respondía) en Policy, Dashboard, History, Lookup y Alerts — cada uno con
 * su propio where()/Gate global, divergiendo con el tiempo. Ahora Policy Y
 * queries llaman a esta MISMA clase.
 *
 * Regla:
 *   - ADMIN / GERENCIAL: todo (GERENCIAL no tiene alcance regional/sucursal
 *     persistente todavía — ver OkrObjectivePolicy — así que el mínimo
 *     vigente es "ve todo el seguimiento gerencial").
 *   - COLABORADOR: Objectives donde
 *       (a) responsible_user_id === user.id  — es quien le da seguimiento
 *           administrativo (gerente/responsable asignado), O
 *       (b) scope_type === employee AND employee_id === user.employee_id —
 *           es el DUEÑO/evaluado real del Objective individual, vínculo
 *           persistente (users.employee_id), nunca por nombre/email.
 *
 * (a) y (b) NO son excluyentes ni intercambiables — un Objective puede tener
 * responsible_user_id = gerente Y employee_id = el propio colaborador: ambos
 * deben poder verlo, cada uno por su propia razón.
 */
class OkrObjectiveVisibilityService
{
    /** Aplica el scope de visibilidad a un builder de OkrObjective — reutilizable en cualquier query. */
    public function applyScope(Builder $query, User $user): Builder
    {
        if ($this->seesEverything($user)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('responsible_user_id', $user->id);

            if ($user->employee_id !== null) {
                $q->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('scope_type', OkrObjective::SCOPE_EMPLOYEE)
                        ->where('employee_id', $user->employee_id);
                });
            }
        });
    }

    /** Misma regla que applyScope(), para un Objective YA cargado en memoria (Policy::view(), checks puntuales). */
    public function canView(User $user, OkrObjective $objective): bool
    {
        if ($this->seesEverything($user)) {
            return true;
        }

        if ($this->isResponsible($user, $objective)) {
            return true;
        }

        return $this->isOwner($user, $objective);
    }

    /**
     * El colaborador es el DUEÑO/evaluado real de este Objective individual
     * (vínculo persistente users.employee_id ↔ okr_objectives.employee_id) —
     * distinto de "responsable administrativo" (ver docblock de la clase).
     * Usado también por checkin/uploadEvidence (11 del pedido): el dueño
     * puede dar seguimiento a SU Objective aunque no sea responsible_user_id,
     * sin que eso amplíe sus privilegios de edición/activación/emisión.
     */
    public function isOwner(User $user, OkrObjective $objective): bool
    {
        return $user->employee_id !== null
            && $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE
            && (int) $objective->employee_id === (int) $user->employee_id;
    }

    public function isResponsible(User $user, OkrObjective $objective): bool
    {
        return $objective->responsible_user_id !== null
            && (int) $objective->responsible_user_id === (int) $user->id;
    }

    public function seesEverything(User $user): bool
    {
        return $user->isAdmin() || $user->hasManagerialAccess();
    }
}

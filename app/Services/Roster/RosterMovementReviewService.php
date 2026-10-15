<?php

namespace App\Services\Roster;

use App\Models\Period;
use App\Models\User;
use App\Services\Roster\RosterMovementStatus as S;
use App\Services\RotacionDerivedFromNoiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Conciliación autorizada de una ausencia de nómina (18-oct-2026): confirmar
 * la baja o descartarla. Cada decisión queda en la bitácora
 * period_employee_movement_reviews (nunca se borra; la última manda) y se
 * vuelve a aplicar cada vez que el roster se reconstruye.
 */
class RosterMovementReviewService
{
    public function __construct(private readonly RotacionDerivedFromNoiService $rotation) {}

    /**
     * @return array{applied:bool, status:string, warning:?string}
     */
    public function review(Period $period, string $nombreNormalizado, string $decision, string $note, User $user): array
    {
        if (! in_array($decision, [S::DECISION_CONFIRMAR, S::DECISION_DESCARTAR], true)) {
            throw ValidationException::withMessages(['decision' => 'La decisión no es válida.']);
        }

        // La llave es employee_key (= nombre normalizado salvo homónimos separados).
        $row = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('employee_key', $nombreNormalizado)
            ->where('is_active_for_period', false)
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'nombre_normalizado' => 'Solo se puede conciliar una ausencia de nómina de este periodo. Vuelve a cargar la página.',
            ]);
        }

        $before = S::resolve($row->reconciliation_status, $row->movement_type, false);

        DB::table('period_employee_movement_reviews')->insert([
            'period_id' => $period->id,
            'nombre_normalizado' => $nombreNormalizado,
            'employee_id' => $row->employee_id,
            'decision' => $decision,
            'status_before' => $before,
            'note' => $note,
            'reviewed_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reconstruye roster + Rotación derivada del periodo con la decisión ya
        // registrada (misma ruta que "Actualizar BD"). Si algo falla, la decisión
        // queda guardada y se aplica en la siguiente reconstrucción.
        $warning = null;
        try {
            $this->rotation->deriveForPeriod($period);
        } catch (\Throwable $e) {
            report($e);
            $warning = 'La decisión quedó registrada, pero no se pudo recalcular el periodo ahora; se aplicará en la próxima actualización.';
            $this->applyInPlace($period, $nombreNormalizado, $decision, $user, $note);
        }

        $after = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('employee_key', $nombreNormalizado)
            ->value('reconciliation_status');

        return ['applied' => $warning === null, 'status' => (string) $after, 'warning' => $warning];
    }

    private function applyInPlace(Period $period, string $name, string $decision, User $user, string $note): void
    {
        $confirm = $decision === S::DECISION_CONFIRMAR;
        $extra = Schema::hasColumn('period_employee_rosters', 'requires_review') ? ['requires_review' => false] : [];
        DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('employee_key', $name)
            ->where('is_active_for_period', false)
            ->update($extra + [
                'movement_type' => $confirm ? S::MOVEMENT_BAJA : S::MOVEMENT_AUSENCIA,
                'reconciliation_status' => $confirm ? S::BAJA_CONFIRMADA : S::AUSENCIA_JUSTIFICADA,
                'reconciliation_note' => ($confirm ? 'Baja confirmada' : 'Ausencia descartada como baja')." por conciliación autorizada ({$user->name}): {$note}",
                'updated_at' => now(),
            ]);
    }
}

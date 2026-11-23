<?php

namespace App\Models\ExpenseReview;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisión manual (opcional) sobre un gasto, ligada por huella estable — ver ExpenseReviewService.
 *
 * El usuario, en la Revisión manual de gastos, puede decidir a qué colaborador y/o sucursal
 * pertenece un gasto (o confirmar que no pertenece a ninguno). Como los importadores borran y
 * reinsertan fact_expenses (cambian los id), la decisión NO apunta al id del gasto sino a una
 * huella (fingerprint) calculada con fuente+fecha+concepto+monto+observaciones+ocurrencia.
 * ExpenseReviewService::applyForPeriod() vuelve a aplicarla tras cada reimportación/generación.
 *
 * Tabla: expense_review_decisions (única por period_id + fingerprint)
 *  - period_id          periodo al que pertenece el gasto.
 *  - fingerprint        sha1 de la huella estable del gasto.
 *  - source_code, expense_date, concept, amount, observations  copia informativa del gasto al decidir.
 *  - employee_decision  'asignado' | 'sin_empleado' | null (sin decisión de colaborador).
 *  - employee_id        colaborador elegido (solo con 'asignado').
 *  - branch_decision    'asignada' | 'corporativo' | 'sin_sucursal' | null (sin decisión de sucursal).
 *  - branch_id          sucursal elegida (solo con 'asignada'; 'corporativo' se resuelve al aplicar).
 *  - auto_employee_id / auto_branch_id / auto_captured  valores automáticos previos a la decisión,
 *                       para poder restaurarlos cuando el usuario elige «automático».
 *  - notes              nota libre del usuario.
 *  - created_by / updated_by  usuarios que crearon / modificaron la decisión.
 *
 * Relaciones: employee() → Employee, branch() → Branch.
 */
class ExpenseReviewDecision extends Model
{
    /** El usuario asignó el gasto a un colaborador concreto (employee_id). */
    public const EMPLOYEE_ASSIGNED = 'asignado';

    /** El usuario confirmó que el gasto no pertenece a ningún colaborador. */
    public const EMPLOYEE_NONE = 'sin_empleado';

    /** El usuario asignó el gasto a una sucursal concreta (branch_id). */
    public const BRANCH_ASSIGNED = 'asignada';

    /** El gasto va a la sucursal CORPORATIVO (se resuelve con BranchRadiographyCalculator). */
    public const BRANCH_CORPORATE = 'corporativo';

    /** El usuario confirmó que el gasto no pertenece a ninguna sucursal. */
    public const BRANCH_NONE = 'sin_sucursal';

    protected $table = 'expense_review_decisions';

    protected $fillable = [
        'period_id', 'fingerprint', 'source_code', 'expense_date', 'concept', 'amount', 'observations',
        'employee_decision', 'employee_id', 'branch_decision', 'branch_id',
        'auto_employee_id', 'auto_branch_id', 'auto_captured', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'auto_captured' => 'boolean',
    ];

    /**
     * Colaborador elegido por el usuario (solo con employee_decision = 'asignado').
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Sucursal elegida por el usuario (solo con branch_decision = 'asignada').
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

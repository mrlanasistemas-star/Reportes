<?php

namespace App\Models\ExpenseReview;

use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora append-only de la revisión manual de gastos (usuario, fecha, antes/después).
 *
 * Cada vez que el usuario guarda/quita una decisión por gasto o una regla de concepto,
 * ExpenseReviewService escribe un renglón aquí. Nunca se actualiza ni se borra.
 *
 * Tabla: expense_review_logs (solo created_at; no hay updated_at)
 *  - period_id        periodo en revisión.
 *  - subject          'gasto' | 'concepto'.
 *  - subject_key      huella del gasto (subject=gasto) o 'tipo|CONCEPTO NORMALIZADO' (subject=concepto).
 *  - fact_expense_id  id del gasto al momento del cambio (informativo: cambia al reimportar).
 *  - action           'decision_guardada' | 'decision_quitada' | 'regla_guardada' | 'regla_quitada'.
 *  - before / after   JSON con el estado previo y posterior (gasto + decisión, o categoría de la regla).
 *  - user_id          usuario que hizo el cambio.
 */
class ExpenseReviewLog extends Model
{
    /** Bitácora append-only: la tabla no tiene columna updated_at. */
    public const UPDATED_AT = null;

    protected $table = 'expense_review_logs';

    protected $fillable = [
        'period_id', 'subject', 'subject_key', 'fact_expense_id', 'action', 'before', 'after', 'user_id',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];
}

<?php

namespace App\Models\ExpenseReview;

use Illuminate\Database\Eloquent\Model;

/**
 * Destino (categoría) elegido por el usuario para un concepto de gasto — persistente entre cargas.
 *
 * Forma parte de la Revisión manual de gastos (paso del flujo Histórico general). Cuando el
 * usuario dice «todos los gastos con el concepto X van a la categoría Y», se guarda aquí y
 * ExpenseReviewService::applyForPeriod() la aplica a fact_expenses.category (guardando la
 * categoría automática original en fact_expenses.category_before_rule para poder revertir).
 * Las reglas son GLOBALES (no dependen del periodo): la siguiente carga ya las reconoce.
 *
 * Tabla: expense_concept_rules
 *  - source_type        'lendus' | 'erp' (ver ExpenseReviewService::SOURCE_TYPES).
 *  - normalized_concept concepto en MAYÚSCULAS, sin acentos y con espacios colapsados
 *                       (ExpenseReviewService::normalizeText). Único junto con source_type.
 *  - concept_label      texto del concepto tal como lo vio el usuario (solo para mostrar).
 *  - category           categoría destino (una de ExpenseCategoryMapper::destinationOptions()).
 *  - is_active          false = regla quitada (se conserva el registro; la categoría vuelve a la automática).
 *  - created_by / updated_by  usuarios (users.id) que crearon / modificaron la regla.
 *
 * Usado por: ExpenseReviewService (saveConceptRule, applyForPeriod) y ExpenseReviewQuery (concepts).
 */
class ExpenseConceptRule extends Model
{
    protected $table = 'expense_concept_rules';

    protected $fillable = [
        'source_type', 'normalized_concept', 'concept_label', 'category', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];
}

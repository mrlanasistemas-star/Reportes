<?php

namespace App\Services\EstadoResultados\Classification;

use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas INICIALES del catálogo de conceptos (25-nov-2026) para conceptos recurrentes vistos en
 * abril. Se insertan una sola vez (migración 2026_11_25_000001) y quedan visibles y editables en
 * «Catálogo de conceptos» como cualquier regla del usuario.
 *
 * Idempotente: una regla con el mismo texto, modo y sentido (aunque esté desactivada o borrada)
 * no se vuelve a crear; así, si el usuario la editó o la quitó, se respeta su decisión.
 * Las reglas que dependen de la sucursal (OPEX corporativo, DG, préstamos, dispersión sin
 * sucursal) viven en MovementClassifier porque el catálogo no filtra por sucursal.
 */
final class ConceptCatalogDefaults
{
    /** Nota que acompaña a cada regla inicial. */
    public const NOTE = 'Regla inicial del sistema (25-nov-2026). Puedes editarla o desactivarla.';

    /**
     * name, match_text, match_mode, direction, treatment.
     *
     * @var list<array{name:string, text:string, mode:string, direction:string, treatment:string}>
     */
    public const RULES = [
        ['name' => 'Comisiones bancarias (otros gastos financieros)', 'text' => 'COMISIONES BANCARIAS', 'mode' => 'contiene', 'direction' => 'cargo', 'treatment' => T::GASTO_FINANCIERO],
        ['name' => 'Prueba SPEI (centavos)', 'text' => 'PRUEBA SPEI', 'mode' => 'empieza', 'direction' => 'cualquiera', 'treatment' => T::BALANCE],
        ['name' => 'Dispersión grupal de créditos (colocación)', 'text' => 'DISPERSION GRUPAL', 'mode' => 'empieza', 'direction' => 'cualquiera', 'treatment' => T::BALANCE],
        ['name' => 'Depósito erróneo (error bancario)', 'text' => 'DEPOSITO ERRONEO', 'mode' => 'contiene', 'direction' => 'cualquiera', 'treatment' => T::BALANCE],
        ['name' => 'Devolución por error en depósito (reverso)', 'text' => 'DEVOLUCION POR ERROR EN DEPOSITO', 'mode' => 'contiene', 'direction' => 'cualquiera', 'treatment' => T::BALANCE],
    ];

    /**
     * Inserta las reglas que falten y registra «creada» en la bitácora (usuario: sistema).
     *
     * @return int reglas creadas
     */
    public static function install(): int
    {
        if (! Schema::hasTable('estado_resultados_concept_rules')) {
            return 0;
        }
        $created = 0;
        foreach (self::RULES as $r) {
            $norm = ConceptCatalogService::normalize($r['text']);
            $exists = DB::table('estado_resultados_concept_rules')
                ->where('match_normalized', $norm)->where('match_mode', $r['mode'])->where('direction', $r['direction'])
                ->exists(); // incluye borradas/desactivadas: se respeta la decisión del usuario
            if ($exists) {
                continue;
            }
            $now = now();
            $row = [
                'name' => $r['name'], 'match_text' => $r['text'], 'match_normalized' => $norm, 'match_mode' => $r['mode'],
                'fields' => json_encode(ConceptCatalogService::DEFAULT_FIELDS), 'account_slot' => null, 'direction' => $r['direction'],
                'treatment' => $r['treatment'], 'priority' => 100, 'active' => true, 'notes' => self::NOTE,
                'created_by' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
            $id = DB::table('estado_resultados_concept_rules')->insertGetId($row);
            if (Schema::hasTable('estado_resultados_concept_rule_logs')) {
                DB::table('estado_resultados_concept_rule_logs')->insert([
                    'rule_id' => $id, 'action' => 'creada', 'before' => null,
                    'after' => json_encode(['id' => $id, 'fields' => ConceptCatalogService::DEFAULT_FIELDS]
                        + array_diff_key($row, array_flip(['fields', 'created_at', 'updated_at'])), JSON_UNESCAPED_UNICODE),
                    'user_id' => null, 'created_at' => $now,
                ]);
            }
            $created++;
        }

        return $created;
    }
}

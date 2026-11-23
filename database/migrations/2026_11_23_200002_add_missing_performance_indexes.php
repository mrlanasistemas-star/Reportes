<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de rendimiento faltantes (23-nov-2026). SOLO AGREGA índices: no cambia tipos de
 * columna, no borra índices ni toca datos, así que ninguna cifra cambia (un índice sólo
 * cambia CÓMO se encuentran las filas, nunca CUÁLES).
 *
 * Idempotente: cada índice se crea sólo si la tabla y las columnas existen y no hay ya un
 * índice con ese nombre o con exactamente esas columnas (Schema::hasIndex). Se puede volver
 * a ejecutar sin error.
 *
 * MySQL 8 / MariaDB 10.x: se crea con `ALGORITHM=INPLACE, LOCK=NONE` (DDL en línea: la tabla
 * sigue aceptando lecturas y escrituras mientras se construye). Si el servidor no lo admite
 * se cae al ALTER normal. Tiempo esperado (orden de magnitud, disco SSD de VPS):
 *   ~100 mil filas (fact_recoveries hoy)  → 1-3 s por índice
 *   ~1 millón de filas                    → 10-40 s por índice
 * Las demás tablas tienen miles de filas → menos de 1 s. Ver docs/MANUAL-DESARROLLADOR.md.
 *
 * Por qué cada índice (consultas calientes con WHERE period_id + fecha/sucursal/gestor):
 */
return new class extends Migration
{
    /** @return list<array{0:string,1:list<string>,2:string,3:string}> tabla, columnas, nombre, porqué */
    private function indexes(): array
    {
        return [
            ['fact_placements', ['period_id', 'operation_date'], 'fact_placements_period_operation_date_idx',
                'API por meses naturales (NaturalMonthActivityService) y colocación por fecha: period_id IN (...) + rango de operation_date.'],
            ['fact_placements', ['period_id', 'normalized_promoter_name'], 'fact_placements_period_promoter_idx',
                'Colocación por gestor dentro del periodo (alcance por colaborador, identidad de gestores).'],
            ['fact_expenses', ['period_id', 'expense_date'], 'fact_expenses_period_expense_date_idx',
                'API por meses naturales y gastos por fecha dentro de los periodos base.'],
            ['fact_expenses', ['period_id', 'employee_id'], 'fact_expenses_period_employee_idx',
                'Gasto atribuido por colaborador en el periodo (OPEX por gestor, auditorías de atribución).'],
            ['fact_portfolios', ['period_id', 'portfolio_date'], 'fact_portfolios_period_portfolio_date_idx',
                'Cartera del periodo por fecha de foto (último corte del periodo, mora por fecha).'],
            ['fact_noi_movements', ['period_id', 'movement_date'], 'fact_noi_movements_period_movement_date_idx',
                'Nómina NOI del periodo por fecha de movimiento (roster, IMSS/rotación).'],
            ['estado_resultados_movements', ['movement_date'], 'er_mov_movement_date_idx',
                'Movimientos bancarios por rango de fecha sin period_id (reparto por fecha, API por meses naturales).'],
            ['employee_branch_assignments', ['period_id', 'branch_id'], 'eba_period_branch_idx',
                'Colaboradores de una sucursal en el periodo (alcance por sucursal, incidencias sin sucursal).'],
            ['period_database_update_runs', ['period_id', 'status'], 'pdur_period_status_idx',
                '«¿Hay una carga en cola/en proceso para este periodo?» (doble clic, polling de progreso).'],
            ['period_radiography_runs', ['period_id', 'status'], 'prr_period_status_idx',
                '«¿Hay una generación en curso para este periodo?» (doble clic, polling de progreso).'],
            ['period_reprocess_runs', ['period_id', 'status'], 'pprr_period_status_idx',
                'Reproceso de fuentes activo por periodo.'],
            ['api_query_logs', ['created_at'], 'api_query_logs_created_at_idx',
                'Purga diaria api:query-logs:prune (WHERE created_at < corte); el índice existente empieza por api_consumer_id y no sirve para esa purga.'],
            ['failed_jobs', ['failed_at'], 'failed_jobs_failed_at_idx',
                'Purga diaria queue:prune-failed (WHERE failed_at < corte).'],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                continue;
            }
            if (Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns)) {
                continue;
            }
            $this->addIndex($table, $columns, $name);
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as [$table, , $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $grammar = DB::connection()->getQueryGrammar();
            $cols = implode(', ', array_map(fn ($c) => $grammar->wrap($c), $columns));
            try {
                // DDL en línea: no bloquea lecturas/escrituras mientras se construye el índice.
                DB::statement('ALTER TABLE '.$grammar->wrapTable($table).' ADD INDEX '.$grammar->wrap($name)." ({$cols}), ALGORITHM=INPLACE, LOCK=NONE");

                return;
            } catch (Throwable $e) {
                Log::warning('Índice en línea no disponible; se crea con ALTER normal.', ['table' => $table, 'index' => $name, 'error' => $e->getMessage()]);
            }
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }
};

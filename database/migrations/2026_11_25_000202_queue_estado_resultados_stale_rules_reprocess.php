<?php

use App\Services\EstadoResultados\RulesRefreshService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Reproceso automático al desplegar (25-nov-2026): encola «Procesar conciliación» de cada periodo
 * NO aprobado cuya última corrida usó otra versión de reglas (MovementClassifier::RULES_VERSION) u
 * otro catálogo de conceptos. Así los movimientos ya procesados (p. ej. «DEPOSITO LEROM» del 24-nov)
 * quedan con las reglas nuevas sin que nadie presione «Procesar conciliación».
 *
 * Sólo ENCOLA (lo procesa el worker de colas): nunca procesa dentro de `migrate`. Con cola `sync`
 * no encola nada; el periodo se actualiza solo al abrir el ER. Nunca rompe `migrate`.
 * Periodos aprobados y clasificaciones manuales no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || config('queue.default') === 'sync') {
            return;
        }
        try {
            $queued = app(RulesRefreshService::class)->refreshAllStale();
            Log::info('estado_resultados.reproceso_reglas_encolado', ['periodos' => $queued]);
        } catch (Throwable $e) {
            Log::warning('estado_resultados.reproceso_reglas_fallo', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // Nada que revertir: el reproceso es idempotente.
    }
};

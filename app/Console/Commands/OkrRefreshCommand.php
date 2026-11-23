<?php

namespace App\Console\Commands;

use App\Models\OkrObjective;
use App\Services\Okr\OkrAlertService;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Comando `okr:refresh` — Módulo OKR (08-sep-2026): recalcula el progreso de todos los
 * Objectives activos (sección 55/56 del pedido): pide el valor real a Reportería vía
 * OkrKpiValueResolver, actualiza snapshots, rellena semanas históricas faltantes y genera
 * alertas. Idempotente (el snapshot semanal se actualiza, nunca se duplica) — seguro de correr
 * por scheduler o manualmente si el cron no corrió. Registra `okr:last_refresh_at` en caché.
 *
 * Cuándo usarlo: lo ejecuta el scheduler diario a las 06:00; también existe el botón
 * "Recalcular" en la pantalla de seguimiento.
 * Escribe en BD: SÍ (snapshots y alertas de OKR) y una clave de caché.
 *
 * Ejemplos:
 *   php artisan okr:refresh
 *   php artisan okr:refresh --objective=12
 */
class OkrRefreshCommand extends Command
{
    protected $signature = 'okr:refresh {--objective= : ID de un solo Objective a recalcular}';

    protected $description = 'Recalcula el progreso de los OKR activos desde Reportería (snapshots + alertas).';

    /**
     * Evalúa cada Objective activo (o solo el indicado), rellena huecos de semanas anteriores y
     * genera sus alertas.
     *
     * @param  OkrSnapshotService  $snapshotService  Calcula y guarda los snapshots de progreso.
     * @param  OkrAlertService  $alertService  Genera las alertas del Objective.
     * @return int Siempre 0.
     */
    public function handle(OkrSnapshotService $snapshotService, OkrAlertService $alertService): int
    {
        $query = OkrObjective::query()->where('lifecycle_status', OkrObjective::STATUS_ACTIVE);
        if ($id = $this->option('objective')) {
            $query->where('id', $id);
        }
        $objectives = $query->get();

        $this->info("Recalculando {$objectives->count()} OKR activo(s)...");

        foreach ($objectives as $objective) {
            $snapshotService->evaluateObjective($objective);
            // Punto 4 de la auditoría 09-sep-2026: si el cron no corrió alguna
            // semana anterior, rellena el hueco histórico (idempotente, nunca
            // duplica, nunca toca el cache de la semana actual).
            $backfilled = $snapshotService->backfillMissingWeeks($objective);
            $alertService->generateForObjective($objective->fresh());
            $this->line("  ✓ #{$objective->id} {$objective->title}".($backfilled ? " (backfill: {$backfilled} semana(s))" : ''));
        }

        // Parte 17 del cierre (04-oct-2026) — marca de "último refresh" para
        // `okr:health`, nunca para lógica de negocio (solo telemetría).
        Cache::forever('okr:last_refresh_at', now()->toDateTimeString());

        $this->info('Listo.');

        return 0;
    }
}

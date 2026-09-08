<?php

namespace App\Console\Commands;

use App\Models\OkrObjective;
use App\Services\Okr\OkrAlertService;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Console\Command;

/**
 * Módulo OKR (08-sep-2026) — recalcula el progreso de todos los Objectives
 * activos (sección 55/56 del pedido): pide el valor real a Reportería vía
 * OkrKpiValueResolver, actualiza snapshots y genera alertas. Idempotente
 * (snapshot semanal se actualiza, nunca duplica) — seguro de correr por
 * scheduler o manualmente si el cron no corrió.
 */
class OkrRefreshCommand extends Command
{
    protected $signature = 'okr:refresh {--objective= : ID de un solo Objective a recalcular}';

    protected $description = 'Recalcula el progreso de los OKR activos desde Reportería (snapshots + alertas).';

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
            $alertService->generateForObjective($objective->fresh());
            $this->line("  ✓ #{$objective->id} {$objective->title}");
        }

        $this->info('Listo.');

        return 0;
    }
}

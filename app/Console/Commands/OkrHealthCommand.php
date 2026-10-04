<?php

namespace App\Console\Commands;

use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Models\OkrWarning;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Okr\OkrWeeklyPlacementResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Parte 17 del cierre (04-oct-2026) — diagnóstico operativo del módulo OKR,
 * de un vistazo: nada aquí modifica datos, solo lee y reporta.
 */
class OkrHealthCommand extends Command
{
    protected $signature = 'okr:health';

    protected $description = 'Diagnóstico operativo del módulo OKR: scheduler, objectives vencidos, semanas de colocación sin archivo, documentos pendientes de firma.';

    public function handle(OkrEmployeeBranchResolver $employeeResolver, OkrWeeklyPlacementResolver $placementResolver): int
    {
        $this->line('<fg=cyan;options=bold>── Scheduler ──</>');
        $lastRefresh = Cache::get('okr:last_refresh_at');
        $lastCloseDue = Cache::get('okr:last_close_due_at');
        $this->reportLastRun('Último okr:refresh', $lastRefresh, 26);
        $this->reportLastRun('Último okr:close-due', $lastCloseDue, 26);

        $this->newLine();
        $this->line('<fg=cyan;options=bold>── Objectives ──</>');
        $activeCount = OkrObjective::query()->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)->count();
        $overdueStillActive = OkrObjective::query()
            ->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)
            ->where('end_date', '<', now()->toDateString())
            ->count();
        $this->line("Objectives activos: {$activeCount}");
        $this->{$overdueStillActive > 0 ? 'warn' : 'line'}("Objectives vencidos aún activos: {$overdueStillActive}" . ($overdueStillActive > 0 ? ' — revisar si okr:close-due está corriendo' : ''));

        $this->newLine();
        $this->line('<fg=cyan;options=bold>── Colocación semanal ──</>');
        [$missingWeeksTotal, $neverUploaded] = $this->placementGaps($placementResolver);
        $this->{$missingWeeksTotal > 0 ? 'warn' : 'line'}("Semanas sin archivo de colocación (Objectives activos que YA usan carga semanal): {$missingWeeksTotal}");
        $this->{$neverUploaded > 0 ? 'warn' : 'line'}("Objectives activos con KPI de colocación que nunca han recibido ninguna carga: {$neverUploaded}");

        $this->newLine();
        $this->line('<fg=cyan;options=bold>── Usuarios / gestores ──</>');
        $this->line('Colaboradores activos elegibles para nuevo OKR: ' . $employeeResolver->countAllActive());

        $this->newLine();
        $this->line('<fg=cyan;options=bold>── Documentos ──</>');
        $lettersUnsigned = OkrCommitmentLetter::query()->whereNull('signed_stored_path')->count();
        $warningsUnsigned = OkrWarning::query()->whereNull('signed_stored_path')->count();
        $this->line("Cartas Compromiso sin firma subida: {$lettersUnsigned}");
        $this->line("Warnings sin firma subida: {$warningsUnsigned}");

        return 0;
    }

    private function reportLastRun(string $label, ?string $timestamp, int $staleAfterHours): void
    {
        if ($timestamp === null) {
            $this->warn("{$label}: nunca registrado (¿el scheduler corrió al menos una vez?)");

            return;
        }

        $hoursAgo = now()->diffInHours($timestamp);
        $line = "{$label}: {$timestamp} (hace {$hoursAgo}h)";
        $this->{$hoursAgo > $staleAfterHours ? 'warn' : 'line'}($line);
    }

    /** @return array{0: int, 1: int} [semanas faltantes totales, objectives que nunca cargaron nada] */
    private function placementGaps(OkrWeeklyPlacementResolver $placementResolver): array
    {
        $objectives = OkrObjective::query()
            ->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)
            ->whereHas('keyResults.kpi', fn ($q) => $q->where('provider_key', 'reporteria.placement'))
            ->get();

        $missingWeeksTotal = 0;
        $neverUploaded = 0;

        foreach ($objectives as $objective) {
            $currentWeek = $objective->currentWeekNumber();
            if ($currentWeek < 1) {
                continue;
            }

            if (!$placementResolver->hasAnyUpload($objective)) {
                $neverUploaded++;

                continue;
            }

            $missingWeeksTotal += count($placementResolver->resolve($objective, $currentWeek)['missing_weeks']);
        }

        return [$missingWeeksTotal, $neverUploaded];
    }
}

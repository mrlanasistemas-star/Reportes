<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Módulo OKR (08-sep-2026, sección 55 del pedido) — recalcula progreso desde
// Reportería y cierra OKR vencidos. Ambos comandos son idempotentes — seguros
// de re-ejecutar si el scheduler no corrió a tiempo (también hay un botón
// manual "Recalcular" en la pantalla de seguimiento, ver ObjectiveController::
// refresh(), para no depender solo del cron).
Schedule::command('okr:refresh')->dailyAt('06:00');
Schedule::command('okr:close-due')->dailyAt('06:15');

// Fase 3.3 (auditoría de rendimiento API, 2026-10-11) — retención de
// api_query_logs, nunca crecimiento ilimitado.
Schedule::command('api:query-logs:prune')->dailyAt('03:00');

// Efectividad de Cobranza — trabajos en segundo plano. `recover-stalled --apply`
// solo cierra ejecuciones con evidencia real de que el Job ya no está en la cola
// (nunca re-encola automáticamente). `retry-notifications` reintenta correos que
// fallaron por SMTP sin duplicar los ya entregados.
Schedule::command('efectividad:recover-stalled --apply')->everyTenMinutes()->withoutOverlapping();
Schedule::command('efectividad:retry-notifications')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aliases for reconcile section commands: reconcile-gastos 5 == reconcile gastos 5
foreach (['gastos', 'nomina', 'ingresos', 'cartera', 'moras', 'fondeo'] as $section) {
    Artisan::command("reportes:reconcile-{$section} {period_id}", function (int $period_id) use ($section) {
        $this->call("reportes:reconcile", ['section' => $section, 'period_id' => $period_id]);
    })->purpose("Conciliación de {$section} para el periodo dado.");
}

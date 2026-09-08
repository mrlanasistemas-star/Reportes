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

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aliases for reconcile section commands: reconcile-gastos 5 == reconcile gastos 5
foreach (['gastos', 'nomina', 'ingresos', 'cartera', 'moras', 'fondeo'] as $section) {
    Artisan::command("reportes:reconcile-{$section} {period_id}", function (int $period_id) use ($section) {
        $this->call("reportes:reconcile", ['section' => $section, 'period_id' => $period_id]);
    })->purpose("Conciliación de {$section} para el periodo dado.");
}

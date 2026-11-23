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

// Rendimiento (23-nov-2026) — con CACHE_STORE=database la tabla `cache` crecía sin purga
// (Laravel sólo borra una clave vencida si alguien la vuelve a leer). Sólo borra filas ya
// vencidas; con Redis no hace nada. Ver docs/MANUAL-DESARROLLADOR.md.
Schedule::command('reportes:purgar-cache-vencida')->dailyAt('03:10')->withoutOverlapping();
// Jobs fallidos: se conservan 30 días para diagnóstico; lotes terminados/viejos, 7 días.
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:20');
Schedule::command('queue:prune-batches --hours=168 --unfinished=168 --cancelled=168')->dailyAt('03:25');

// Calculadora de Efectividad — borra espacios temporales vencidos (Excel leídos y
// cifrados, PDF/PNG no descargados). Ya no hay ejecuciones persistentes que recuperar.
Schedule::command('efectividad:limpiar-temporales')->everyTenMinutes()->withoutOverlapping();

// Estado de Resultados (06-nov-2026) — la caché de comparativas/base de Radiografía se firma
// con la fecha del día; se precalienta antes de la jornada para que nadie espere el cálculo en frío.
Schedule::command('er:precalentar-comparativas --ultimos=3')->dailyAt('05:30')->withoutOverlapping();

// Radiografía (13-nov-2026) — la corrida general del mes (~10-25 s en frío) es la que lee la
// API v1 (financial-summary, periods/{id}/branches). Se mantiene caliente para los meses
// recientes: el comando no hace nada si el valor ya está en caché y es reciente, y lo
// refresca antes de que caduque (RADIOGRAFIA_PRECALENTAR_* en .env.example). En segundo
// plano para que un cálculo en frío no retrase las demás tareas del mismo minuto.
Schedule::command('radiografia:precalentar')
    ->cron((string) config('radiografia.precalentar.cron', '*/30 * * * *'))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();

// Comando de ejemplo que trae Laravel por defecto (imprime una frase). No lee ni escribe
// datos del sistema; se conserva solo por compatibilidad con la plantilla del framework.
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Alias de los comandos de conciliación por sección: `reportes:reconcile-gastos 5` equivale
// a `reportes:reconcile gastos 5` (ver ReconcileSectionCommand). Solo lectura: reconstruyen
// el snapshot de la Radiografía en memoria y comparan crudo vs procesado. Los alias NO
// aceptan la opción --target; para comparar contra un valor manual usa el comando base:
//   php artisan reportes:reconcile ingresos 21 --target=18324971.76
foreach (['gastos', 'nomina', 'ingresos', 'cartera', 'moras', 'fondeo'] as $section) {
    Artisan::command("reportes:reconcile-{$section} {period_id}", function (int $period_id) use ($section) {
        $this->call('reportes:reconcile', ['section' => $section, 'period_id' => $period_id]);
    })->purpose("Conciliación de {$section} para el periodo dado.");
}

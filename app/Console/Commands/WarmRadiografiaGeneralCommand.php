<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\RadiografiaExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mantiene caliente la corrida GENERAL de la radiografía de los periodos mensuales
 * más recientes (13-nov-2026). Esa corrida (~10-25 s en frío) es la que leen la API v1
 * (/employees/{id}/financial-summary, /periods/{id}/branches), el Dashboard, el
 * comparativo y OKR; antes sólo se calentaba al generar la radiografía y caducaba a
 * las 12 h, así que la primera consulta de un sistema externo la pagaba entera y
 * agotaba su tiempo de espera.
 *
 * Idempotente: si el valor ya está en caché y es reciente no hace nada; si le falta
 * poco para caducar lo recalcula y reemplaza (el anterior se sigue sirviendo mientras
 * tanto); si otro proceso lo está calculando, no lo duplica. Sólo LEE datos y escribe
 * en la caché: no genera, regenera ni modifica radiografías.
 */
class WarmRadiografiaGeneralCommand extends Command
{
    protected $signature = 'radiografia:precalentar
                                {--periodo=* : id(s) de periodo; por defecto los mensuales más recientes con radiografía vigente}
                                {--ultimos= : cuántos periodos mensuales recientes calentar (por defecto RADIOGRAFIA_PRECALENTAR_PERIODOS)}
                                {--forzar : recalcula aunque el valor en caché sea reciente}';

    protected $description = 'Radiografía: deja en caché la corrida general de los periodos mensuales recientes (la que consume la API v1)';

    public function handle(RadiografiaExportService $exportService): int
    {
        $explicitIds = array_values(array_filter(array_map('intval', (array) $this->option('periodo'))));

        // El interruptor sólo apaga la corrida automática; una petición explícita siempre corre.
        if (! config('radiografia.precalentar.activo', true) && $explicitIds === [] && ! $this->option('forzar')) {
            $this->line('Precalentado desactivado (RADIOGRAFIA_PRECALENTAR=false).');

            return self::SUCCESS;
        }

        @ini_set('memory_limit', '1024M');

        $vigentes = PeriodSummary::query()
            ->select('period_id')
            ->where('status', 'generated')
            ->whereNull('invalidated_at');

        $query = Period::query()->whereIn('id', $vigentes);
        if ($explicitIds !== []) {
            $periods = $query->whereIn('id', $explicitIds)->orderByDesc('start_date')->get();
        } else {
            $ultimos = $this->option('ultimos');
            $limit = max(1, (int) ($ultimos !== null && $ultimos !== '' ? $ultimos : config('radiografia.precalentar.periodos', 3)));
            $periods = $query->where('type', 'monthly')->where('code', 'not like', 'TEST-%')
                ->orderByDesc('start_date')->limit($limit)->get();
        }

        if ($periods->isEmpty()) {
            $this->warn('No hay periodos con radiografía vigente que calentar.');

            return self::SUCCESS;
        }

        $refreshAfter = $this->option('forzar')
            ? 0
            : max(1, (int) config('radiografia.precalentar.refrescar_horas', 6)) * 3600;

        $resumen = [];
        foreach ($periods as $period) {
            $t = microtime(true);
            try {
                $estado = $exportService->warmGeneralState($period, $refreshAfter);
            } catch (\Throwable $e) {
                // Un periodo que falla nunca impide calentar los demás ni tumba el scheduler.
                $estado = 'error';
                $this->error(sprintf('%s (%d): %s', $period->code, $period->id, $e->getMessage()));
                Log::warning('radiografia:precalentar: no se pudo calentar el periodo.', [
                    'period_id' => $period->id, 'exception' => get_class($e), 'message' => $e->getMessage(),
                ]);
            }
            $ms = (int) round((microtime(true) - $t) * 1000);
            $resumen[] = ['period_id' => $period->id, 'code' => $period->code, 'estado' => $estado, 'ms' => $ms];
            if ($estado !== 'error') {
                $this->line(sprintf('%s (%d): %s · %d ms', $period->code, $period->id, $estado, $ms));
            }
        }

        // Rastro para comprobar en producción que el scheduler corre (storage/logs).
        Log::info('radiografia:precalentar', ['periodos' => $resumen]);

        return self::SUCCESS;
    }
}

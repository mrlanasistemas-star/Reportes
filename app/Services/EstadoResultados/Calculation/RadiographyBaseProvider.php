<?php

namespace App\Services\EstadoResultados\Calculation;

use App\Models\Period;
use App\Services\EstadoResultados\Comparatives\InputSignature;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Base de Radiografía para el nivel 1 (EBITDA sucursales) del Estado de Resultados.
 *
 * Llama EXACTAMENTE al mismo camino que RadiographySnapshotBuilder::build():
 *   BranchRadiographyCalculator::buildBranches($period, $dataIds, [], $closingDataIds)
 *   → sumGlobal($branches, $unassigned)
 * y a los métodos estáticos canónicos (ingresoEbitdaBaseFor/nominaTotalFor/ebitdaFinalFor).
 * No replica ni altera ninguna regla: si Radiografía cambia, el ER la sigue.
 *
 * Rendimiento (06-nov-2026):
 *  - #[Scoped]: una sola instancia por petición/job. Antes cada builder (el del controlador,
 *    el del resolver, el del servicio de aprobación) tenía su propio proveedor, así que
 *    session() no abarcaba a los demás y la base se calculaba DOS veces por petición.
 *  - Caché entre peticiones con la firma determinista de sus insumos
 *    (InputSignature::radiography): mismo insumo ⇒ misma base, bit a bit (es el resultado
 *    del mismo cálculo); cualquier cambio de datos, catálogo, código o día ⇒ otra firma.
 */
#[Scoped]
class RadiographyBaseProvider
{
    /**
     * Caché SÓLO dentro de una sesión explícita (session()). Fuera de ella cada
     * llamada recalcula: antes el caché vivía por instancia y, en una misma petición,
     * un worker de cola o un servicio reutilizado, devolvía cifras viejas si
     * Radiografía/ERP/NOI cambiaban entre dos llamadas (fase 2.4).
     *
     * @var array<int, array>
     */
    private array $cache = [];

    private int $sessionDepth = 0;

    /** Diagnóstico de la petición: cuántas bases salieron de caché y cuántas se calcularon. */
    public array $stats = ['cache_hit' => 0, 'computed' => 0, 'ms_computing' => 0.0];

    public function __construct(
        private readonly BranchRadiographyCalculator $calculator,
        private readonly PeriodDataIds $dataIds,
        private ?InputSignature $signature = null,
    ) {}

    /**
     * @return array{global: array, branches: array<string,array>, unassigned: array, cartera: array, rotacion: array, data_ids: list<int>}
     */
    public function for(Period $period): array
    {
        if ($this->sessionDepth === 0) {
            return $this->compute($period);
        }

        return $this->cache[$period->id] ??= $this->compute($period);
    }

    /**
     * Ejecuta $fn reutilizando la base de Radiografía calculada dentro de ella (una sola
     * foto de datos por operación: p. ej. alcances + dataset de una petición). Al salir
     * de la sesión más externa el caché se descarta. Re-entrante.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function session(callable $fn): mixed
    {
        $this->sessionDepth++;
        try {
            return $fn();
        } finally {
            if (--$this->sessionDepth === 0) {
                $this->cache = [];
            }
        }
    }

    public function inSession(): bool
    {
        return $this->sessionDepth > 0;
    }

    /**
     * Base desde la caché firmada o, si no existe, calculada una sola vez (con candado para que
     * dos peticiones simultáneas del mismo periodo no la calculen en paralelo).
     */
    private function compute(Period $period): array
    {
        if (! config('estado_resultados.cache.base_radiografia', true)) {
            return $this->calculate($period);
        }

        $this->signature ??= app(InputSignature::class);
        $key = 'er:base:'.$period->id.':'.$this->signature->radiography($period);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            $this->stats['cache_hit']++;

            return $hit;
        }

        $lock = Cache::lock('er:base:calc:'.$period->id, 300);
        try {
            $lock->block(120);
            $hit = Cache::get($key);
            if (is_array($hit)) {
                $this->stats['cache_hit']++;

                return $hit;
            }
            $base = $this->calculate($period);
            try {
                Cache::put($key, $base, max(1, (int) config('estado_resultados.cache.base_ttl_minutos', 720)) * 60);
            } catch (\Throwable $e) {
                Log::warning('ER: no se pudo guardar la base de Radiografía en caché', ['period' => $period->id, 'error' => $e->getMessage()]);
            }

            return $base;
        } catch (LockTimeoutException) {
            return $this->calculate($period);
        } finally {
            $lock->release();
        }
    }

    private function calculate(Period $period): array
    {
        $t = microtime(true);
        $this->stats['computed']++;
        try {
            return $this->build($period);
        } finally {
            $this->stats['ms_computing'] += (microtime(true) - $t) * 1000;
        }
    }

    private function build(Period $period): array
    {
        $dataIds = $this->dataIds->for($period);
        // Periodos mensuales: el cierre (cartera/mora) usa los mismos dataIds (ver
        // RadiographySnapshotBuilder::resolveClosingDataIds()).
        $result = $this->calculator->buildBranches($period, $dataIds, [], $dataIds);
        $global = $this->calculator->sumGlobal($result['branches'], $result['unassigned']);

        $branches = [];
        foreach ($result['branches'] as $b) {
            $branches[(string) $b['sucursal']] = $b;
        }

        return [
            'global' => $global,
            'branches' => $branches,
            'unassigned' => $result['unassigned'],
            'cartera' => $this->calculator->computeCarteraGlobalSinFiltro($dataIds),
            'rotacion' => $this->rotacion($period),
            'data_ids' => $dataIds,
        ];
    }

    /**
     * Plantilla/rotación (informativo) — misma fuente única que Radiografía
     * (RadiographySnapshotBuilder::buildRotationData): fact_rotacion con source='derived_noi'.
     */
    private function rotacion(Period $period): array
    {
        $rows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')->get(['sucursal_nombre', 'altas', 'bajas', 'promedio_personal']);
        $plantilla = (float) $rows->sum('promedio_personal');
        $bajas = (int) $rows->sum('bajas');

        return [
            'disponible' => $rows->isNotEmpty(),
            'altas' => (int) $rows->sum('altas'),
            'bajas' => $bajas,
            'plantilla' => $plantilla,
            'indice' => $plantilla > 0 ? round($bajas / $plantilla * 100, 2) : null,
            'por_sucursal' => $rows->mapWithKeys(fn ($r) => [mb_strtoupper((string) $r->sucursal_nombre) => [
                'altas' => (int) $r->altas, 'bajas' => (int) $r->bajas, 'plantilla' => (float) $r->promedio_personal,
            ]])->all(),
        ];
    }
}

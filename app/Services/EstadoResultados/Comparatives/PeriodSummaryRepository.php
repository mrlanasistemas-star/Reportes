<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Models\Period;
use App\Services\EstadoResultados\Reporting\EstadoResultadosReportResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resúmenes por periodo y alcance para las comparativas, SIEMPRE obtenidos del punto
 * único del módulo (EstadoResultadosReportResolver::eachUnredacted):
 *
 *  - Periodo con versión aprobada vigente → snapshot congelado (cache por id+hash de la versión,
 *    que es inmutable).
 *  - Periodo sin versión vigente → cálculo en vivo, con caché por FIRMA DETERMINISTA de insumos
 *    (InputSignature): cualquier cambio de datos, clasificaciones, ajustes, ERP, reglas o código
 *    produce otra firma y obliga a recalcular. Nunca se sirve un resumen obsoleto.
 *
 * Se resuelven TODOS los alcances del periodo de una vez: la base de Radiografía (lo costoso) se
 * calcula una sola vez por periodo y cada alcance adicional cuesta una fracción.
 * En caché sólo se guarda el resumen ligero (sin movimientos ni datos personales).
 */
class PeriodSummaryRepository
{
    /** Vida de la caché en vivo (1 día); los snapshots aprobados se guardan 30 días. */
    private const TTL_SECONDS = 86400;

    /** @var array<int,array> memo por petición */
    private array $memo = [];

    /** Contadores de diagnóstico (snapshots, aciertos/fallos de caché, alcances calculados, ms). */
    public array $stats = ['snapshot' => 0, 'cache_hit' => 0, 'cache_miss' => 0, 'built_scopes' => 0, 'ms_building' => 0.0];

    /**
     * @param  EstadoResultadosReportResolver  $resolver  punto único que entrega datasets (snapshot o en vivo)
     * @param  PeriodSummaryExtractor  $extractor  reduce cada dataset a su resumen
     * @param  InputSignature  $signature  firma determinista de insumos para la caché en vivo
     */
    public function __construct(
        private readonly EstadoResultadosReportResolver $resolver,
        private readonly PeriodSummaryExtractor $extractor,
        private readonly InputSignature $signature,
    ) {}

    /**
     * Resúmenes de TODOS los alcances de un periodo (memo por petición + caché).
     *
     * Con versión aprobada: caché por id+hash del snapshot (30 días). Sin ella: caché por firma de
     * insumos (1 día), con un candado por periodo (300 s, espera hasta 240 s) para que sólo un
     * proceso calcule; si el candado expira se calcula sin guardar. Eleva memory_limit a 1024M.
     *
     * @param  Period  $period  periodo
     * @return array{source:string, cache:string, signature:?string, scopes:list<array{key:string,label:string}>, summaries:array<string,array>}
     */
    public function bundle(Period $period): array
    {
        if (isset($this->memo[$period->id])) {
            return $this->memo[$period->id];
        }
        @ini_set('memory_limit', '1024M');

        $version = $this->resolver->servedVersion($period);
        if ($version) {
            $this->stats['snapshot']++;
            $key = 'er:comparativas:snapshot:'.$version->id.':'.$version->snapshot_hash.':'.PeriodSummaryExtractor::VERSION;
            $bundle = Cache::get($key);
            if (! is_array($bundle)) {
                $bundle = $this->compute($period, 'snapshot', null);
                Cache::put($key, $bundle, self::TTL_SECONDS * 30);
            }

            return $this->memo[$period->id] = $bundle;
        }

        $sig = $this->signature->for($period, '*');
        $key = 'er:comparativas:vivo:'.$period->id.':'.$sig;
        $bundle = Cache::get($key);
        if (is_array($bundle)) {
            $this->stats['cache_hit']++;

            return $this->memo[$period->id] = ['cache' => 'hit'] + $bundle;
        }

        // Un solo cálculo concurrente por periodo: quien espera reutiliza el resultado.
        $lock = Cache::lock('er:comparativas:calc:'.$period->id, 300);
        try {
            $lock->block(240);
            $bundle = Cache::get($key);
            if (! is_array($bundle)) {
                $this->stats['cache_miss']++;
                $bundle = $this->compute($period, 'en_vivo', $sig);
                Cache::put($key, $bundle, self::TTL_SECONDS);
            } else {
                $this->stats['cache_hit']++;
            }
        } catch (LockTimeoutException) {
            $this->stats['cache_miss']++;
            $bundle = $this->compute($period, 'en_vivo', $sig);
        } finally {
            $lock->release();
        }

        return $this->memo[$period->id] = $bundle;
    }

    /**
     * Resumen de un alcance; null si el alcance no existe en ese periodo.
     *
     * @param  Period  $period  periodo
     * @param  string  $scope  alcance (CONSOLIDADO o sucursal)
     */
    public function summary(Period $period, string $scope): ?array
    {
        return $this->bundle($period)['summaries'][$scope] ?? null;
    }

    /**
     * Todos los alcances del periodo con UNA sola foto de datos, vía el punto único del módulo
     * (EstadoResultadosReportResolver::eachUnredacted): snapshot aprobado → itera sus snapshots;
     * en vivo → una sesión de Radiografía + buildEach (la base costosa se calcula una vez).
     * Si la iteración falla a mitad (p. ej. un snapshot alterado o un alcance ausente), se
     * recurre a alcance por alcance para conservar los demás y reportar el error de ese alcance.
     *
     * @param  Period  $period  periodo
     * @param  string  $source  'snapshot' o 'en_vivo'
     * @param  string|null  $sig  firma de insumos (sólo en vivo)
     * @return array<string, mixed> bundle con source, cache, signature, scopes, summaries y errors
     */
    private function compute(Period $period, string $source, ?string $sig): array
    {
        $t = microtime(true);
        $scopes = [];
        $summaries = [];
        $errors = [];
        try {
            $this->resolver->eachUnredacted($period, null, function (string $key, array $dataset) use (&$scopes, &$summaries) {
                $scopes[] = ['key' => $key, 'label' => (string) ($dataset['meta']['scope']['label'] ?? $key)];
                $summaries[$key] = $this->extractor->extract($dataset);
                $this->stats['built_scopes']++;
            });
        } catch (\Throwable $e) {
            Log::warning('Comparativas ER: falló la iteración de alcances; se calcula alcance por alcance', ['period' => $period->id, 'error' => $e->getMessage()]);
            [$scopes, $summaries, $errors] = $this->computeEach($period);
        }
        $this->stats['ms_building'] += (microtime(true) - $t) * 1000;

        return [
            'source' => $source,
            'cache' => $source === 'snapshot' ? 'snapshot' : 'miss',
            'signature' => $sig,
            'scopes' => array_values(array_map(fn ($s) => ['key' => (string) $s['key'], 'label' => (string) $s['label']], $scopes)),
            'summaries' => $summaries,
            'errors' => $errors,
        ];
    }

    /**
     * Respaldo: alcance por alcance, aislando el error de cada uno (se registra en el log).
     *
     * @param  Period  $period  periodo
     * @return array{0:array, 1:array<string,array>, 2:array<string,string>} [alcances, resúmenes, errores por alcance]
     */
    private function computeEach(Period $period): array
    {
        $scopes = $this->resolver->scopes($period);
        $summaries = [];
        $errors = [];
        foreach ($scopes as $s) {
            try {
                $summaries[$s['key']] = $this->extractor->extract($this->resolver->unredacted($period, $s['key']));
                $this->stats['built_scopes']++;
            } catch (\InvalidArgumentException $e) {
                $errors[$s['key']] = $e->getMessage();
            } catch (\Throwable $e) {
                Log::warning('Comparativas ER: no se pudo obtener el alcance', ['period' => $period->id, 'scope' => $s['key'], 'error' => $e->getMessage()]);
                $errors[$s['key']] = 'No se pudo calcular este alcance: '.$e->getMessage();
            }
        }

        return [$scopes, $summaries, $errors];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Services\EstadoResultados\Comparatives\ComparisonAccessException;
use App\Services\EstadoResultados\Comparatives\ComparisonService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosGovernanceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comparativas del Estado de Resultados (JSON, contrato `er-comparativas/1`).
 *
 * GET /estado-resultados/{period}/comparativas
 *   ?modos=anterior,anio_anterior,evolucion,acumulado,sucursales,personalizado   (o ?modo=uno; por defecto todos menos personalizado)
 *   &alcance=CONSOLIDADO|<SUCURSAL>   (por defecto: consolidado, o la primera sucursal permitida si el usuario está restringido)
 *   &contra={periodId}               (personalizado; obligatorio para ese modo)
 *   &meses=6                         (evolución: 1..13)
 *   &desde={periodId}&hasta={periodId} (evolución por rango, máximo 24 meses)
 *   &graficas=1                      (0 = sin bloque charts)
 */
class EstadoResultadosComparativoController extends Controller
{
    public function __construct(private readonly ComparisonService $service) {}

    public function show(Request $request, Period $period): JsonResponse
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);

        $modes = $request->query('modos', $request->query('modo'));
        $modes = $modes === null || $modes === '' ? null : array_values(array_filter(array_map('trim', explode(',', (string) $modes))));
        if ($modes !== null && array_diff($modes, ComparisonService::MODES) !== []) {
            return response()->json(['message' => 'Modo no válido. Usa: '.implode(', ', ComparisonService::MODES).'.'], 422);
        }
        foreach (['contra', 'desde', 'hasta', 'meses'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '' && ! ctype_digit((string) $v)) {
                return response()->json(['message' => 'El parámetro «'.$k.'» debe ser numérico.'], 422);
            }
        }
        $find = function (string $k) use ($request): ?Period {
            $id = $request->query($k);
            if ($id === null || $id === '') {
                return null;
            }
            $p = Period::query()->find((int) $id);
            abort_unless($p, 422, 'El periodo «'.$k.'» no existe.');

            return $p;
        };

        try {
            $desde = $find('desde');
            $hasta = $find('hasta');
            if (($desde === null) !== ($hasta === null)) {
                return response()->json(['message' => 'Indica «desde» y «hasta» juntos para el rango de evolución.'], 422);
            }
            $payload = $this->service->build($period, $request->query('alcance'), $request->user(), array_filter([
                'modes' => $modes,
                'contra' => $find('contra'),
                'meses' => $request->query('meses') !== null && $request->query('meses') !== '' ? (int) $request->query('meses') : null,
                'desde' => $desde,
                'hasta' => $hasta,
                'charts' => ! in_array((string) $request->query('graficas', '1'), ['0', 'false', 'no'], true),
            ], fn ($v) => $v !== null));
        } catch (ComparisonAccessException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (EstadoResultadosGovernanceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $diag = $payload['diagnostics'] ?? [];

        return response()->json($payload)->header('Server-Timing', sprintf(
            'cmp;desc="Comparativas (%d en cache, %d calculados)";dur=%d',
            (int) ($diag['periods']['cache_hit'] ?? 0) + (int) ($diag['periods']['snapshot'] ?? 0),
            (int) ($diag['periods']['cache_miss'] ?? 0),
            (int) ($diag['ms'] ?? 0),
        ));
    }
}

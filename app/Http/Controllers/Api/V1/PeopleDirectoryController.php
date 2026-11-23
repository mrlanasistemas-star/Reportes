<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Services\Api\ApiValidation;
use App\Services\Api\PeopleDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio de PERSONAS (employees agrupados) y SUCURSALES con sus rutas — ADITIVO
 * (2026-11-17). /employees y /branches no cambian. Ver docs/MANUAL-DESARROLLADOR.md §16.
 */
class PeopleDirectoryController extends Controller
{
    /**
     * @param  PeopleDirectoryService  $directory  Arma el directorio de personas y sucursales con sus rutas.
     */
    public function __construct(private readonly PeopleDirectoryService $directory) {}

    /**
     * GET /api/v1/people — requiere ability employees.read.
     *
     * Lista de personas (registros employees agrupados por nombre normalizado,
     * fusión de variantes y rechazos de homónimos), paginada en memoria y
     * filtrada al alcance de sucursales del consumidor.
     *
     * @param  Request  $request  `per_page` (100 por defecto, máx. 500), `page`, e `include_placeholders`
     *                            (true por defecto; false deja solo elementos con kind = person).
     * @return JsonResponse {data: [persona...], meta: paginación + criterio de agrupación}.
     */
    public function people(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $perPage = ApiValidation::perPage($request, 100, 500);
        $page = max(1, (int) $request->query('page', 1));
        $includePlaceholders = $request->boolean('include_placeholders', true);

        $people = $this->directory->people();

        if (! $includePlaceholders) {
            $people = array_values(array_filter($people, fn (array $p) => $p['kind'] === 'person'));
        }

        $people = $this->scope($consumer, $people);
        $total = count($people);

        return response()->json([
            'data' => array_slice($people, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'grouping' => 'normalized_name+variant_merge+homonym_rejections',
            ],
        ]);
    }

    /**
     * GET /api/v1/sucursales — requiere ability branches.read.
     *
     * Sucursales con sus rutas (según BranchRouteCatalog). Si el consumidor no
     * es global, cada sucursal conserva solo las rutas permitidas y se omiten
     * las que se quedan sin rutas; sin acceso a sucursales → lista vacía.
     *
     * @param  Request  $request  Petición del consumidor de API autenticado.
     * @return JsonResponse {data: [sucursal...], meta: {total, route_mapping}}.
     */
    public function sucursales(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $sucursales = $this->directory->sucursales();

        if (! $consumer->isGlobalAccess()) {
            $allowed = $consumer->hasNoBranchAccess() ? [] : array_map('intval', (array) $consumer->allowed_branch_ids);
            $sucursales = array_values(array_filter(array_map(function (array $s) use ($allowed) {
                $s['routes'] = array_values(array_filter($s['routes'], fn (array $r) => in_array($r['id'], $allowed, true)));

                return $s;
            }, $sucursales), fn (array $s) => $s['routes'] !== []));
        }

        return response()->json(['data' => $sucursales, 'meta' => ['total' => count($sucursales), 'route_mapping' => 'branch_route_catalog']]);
    }

    /**
     * Consumidor restringido: solo personas con alguna ruta permitida, y solo esas rutas.
     *
     * Consumidor global → sin cambios; sin acceso a sucursales → lista vacía.
     *
     * @param  ApiConsumer  $consumer  Consumidor de API autenticado.
     * @param  list<array<string, mixed>>  $people  Personas del directorio (cada una con `routes`).
     * @return list<array<string, mixed>>
     */
    private function scope(ApiConsumer $consumer, array $people): array
    {
        if ($consumer->isGlobalAccess()) {
            return $people;
        }

        if ($consumer->hasNoBranchAccess()) {
            return [];
        }

        $allowed = array_map('intval', (array) $consumer->allowed_branch_ids);
        $result = [];

        foreach ($people as $person) {
            $routes = array_values(array_filter($person['routes'], fn (array $r) => in_array($r['id'], $allowed, true)));
            if ($routes !== []) {
                $person['routes'] = $routes;
                $result[] = $person;
            }
        }

        return $result;
    }
}

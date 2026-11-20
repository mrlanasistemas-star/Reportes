<?php

namespace App\Http\Controllers;

use App\Models\EstadoResultados\EstadoResultadosConceptRule;
use App\Models\Period;
use App\Services\Access\DataScopeService;
use App\Services\EstadoResultados\Classification\ConceptCatalogService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosAbilities;
use App\Services\EstadoResultados\Reporting\EstadoResultadosRedactor;
use App\Services\EstadoResultados\Support\Treatment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de conceptos del Estado de Resultados (global, 20-nov-2026).
 *
 * Lectura: cualquiera con el módulo. Escritura y vista previa: permiso
 * `estado_resultados,catalogo` (ruta) + alcance general (el catálogo afecta a todas
 * las sucursales y la vista previa lee los movimientos de todo el periodo).
 * Las reglas se APLICAN al «Procesar conciliación»; guardar aquí no reprocesa nada.
 */
class EstadoResultadosConceptCatalogController extends Controller
{
    public function __construct(
        private readonly ConceptCatalogService $catalog,
        private readonly DataScopeService $dataScope,
        private readonly EstadoResultadosAbilities $abilities,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'rules' => $this->catalog->list(),
            'options' => ConceptCatalogService::options(),
            'signature' => $this->catalog->signature(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El catálogo de conceptos');
        $data = $this->validateRule($request);

        return $this->write(function () use ($data, $request) {
            $rule = $this->catalog->create($data, $request->user()?->id);

            return ['message' => 'Regla «'.$rule->name.'» creada. Se aplica al procesar la conciliación.', 'rule' => $this->catalog->present($rule->fresh(['creator', 'editor']))];
        }, 201);
    }

    public function update(Request $request, EstadoResultadosConceptRule $rule): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El catálogo de conceptos');
        $data = $this->validateRule($request);

        return $this->write(function () use ($rule, $data, $request) {
            $rule = $this->catalog->update($rule, $data, $request->user()?->id);

            return ['message' => 'Regla «'.$rule->name.'» actualizada. Se aplica al procesar la conciliación.', 'rule' => $this->catalog->present($rule->fresh(['creator', 'editor']))];
        });
    }

    public function toggle(Request $request, EstadoResultadosConceptRule $rule): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El catálogo de conceptos');
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $rule = $this->catalog->setActive($rule, (bool) $active, $request->user()?->id);

        return response()->json([
            'message' => $rule->active ? 'Regla «'.$rule->name.'» activada.' : 'Regla «'.$rule->name.'» desactivada: deja de aplicarse al procesar.',
            'rule' => $this->catalog->present($rule->fresh(['creator', 'editor'])),
        ]);
    }

    public function destroy(Request $request, EstadoResultadosConceptRule $rule): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El catálogo de conceptos');
        $name = $rule->name;
        $this->catalog->delete($rule, $request->user()?->id);

        return response()->json(['message' => 'Regla «'.$name.'» borrada. Queda en la bitácora del catálogo.']);
    }

    public function logs(EstadoResultadosConceptRule $rule): JsonResponse
    {
        return response()->json(['logs' => $this->catalog->logs($rule)]);
    }

    /** Movimientos del periodo que coinciden con la regla en captura (antes de guardar). */
    public function preview(Request $request, Period $period): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'La vista previa del catálogo de conceptos');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'match_text' => ['required', 'string', 'max:200'],
            'match_mode' => ['nullable', 'string', 'in:'.implode(',', array_keys(ConceptCatalogService::MODES))],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'in:'.implode(',', array_keys(ConceptCatalogService::FIELDS))],
            'account_slot' => ['nullable', 'string', 'max:30'],
            'direction' => ['nullable', 'string', 'in:'.implode(',', array_keys(ConceptCatalogService::DIRECTIONS))],
            'treatment' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'rule_id' => ['nullable', 'integer'],
        ], ['match_text.required' => 'Escribe el texto a buscar.']);

        $can = $this->abilities->for($request->user());
        $canNomina = (bool) ($can['ver_nomina'] ?? false);
        $canDg = (bool) ($can['ver_dg'] ?? false);
        $hidden = ($canNomina && $canDg) ? null
            : fn (array $m) => (! $canNomina && EstadoResultadosRedactor::isPayroll($m)) || (! $canDg && EstadoResultadosRedactor::isDg($m));

        return response()->json($this->catalog->preview($period, $data, isset($data['rule_id']) ? (int) $data['rule_id'] : null, 200, $hidden));
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'match_text' => ['required', 'string', 'max:200'],
            'match_mode' => ['required', 'string', 'in:'.implode(',', array_keys(ConceptCatalogService::MODES))],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'in:'.implode(',', array_keys(ConceptCatalogService::FIELDS))],
            'account_slot' => ['nullable', 'string', 'max:30'],
            'direction' => ['required', 'string', 'in:'.implode(',', array_keys(ConceptCatalogService::DIRECTIONS))],
            'treatment' => ['required', 'string', 'in:'.implode(',', Treatment::ASSIGNABLE)],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Escribe el nombre del concepto del reporte.',
            'name.min' => 'El nombre del concepto debe tener al menos 3 caracteres.',
            'match_text.required' => 'Escribe el texto a buscar en el estado de cuenta.',
            'fields.required' => 'Elige al menos un campo donde buscar el texto.',
            'treatment.required' => 'Elige a dónde va el movimiento.',
            'treatment.in' => 'Ese destino no se puede asignar desde el catálogo.',
        ]);
    }

    private function write(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

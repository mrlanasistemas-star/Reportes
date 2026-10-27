<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Services\Api\ApiValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Fase 2.4 — un consumidor restringido por sucursal ve SOLO su catálogo
     * autorizado, salvo que tenga además la ability independiente
     * `branches.read.global` (caso excepcional: un consumidor que necesita el
     * catálogo completo para otro propósito, sin que eso implique acceso
     * financiero/OKR a esas sucursales — esa autorización se sigue validando
     * aparte, por endpoint, nunca aquí).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $perPage = ApiValidation::perPage($request);

        $query = Branch::query()->where('is_active', true);

        $hasGlobalOverride = $consumer->hasAbility('branches.read.global')
            && $consumer->currentAccessToken()?->can('branches.read.global');

        if ($consumer->hasNoBranchAccess()) {
            $query->whereRaw('1 = 0');
        } elseif ($consumer->isRestrictedToBranches() && ! $hasGlobalOverride) {
            $query->whereIn('id', $consumer->allowed_branch_ids);
        }

        $branches = $query->with('sucursal')->orderBy('name')->paginate($perPage);

        return response()->json([
            // Claves originales {id, code, name} intactas byte-a-byte; los campos
            // sucursal/region/active son ADITIVOS (atribución a una de las 11
            // sucursales de OKR). Un branch/ruta no atribuible → sucursal/region null
            // (honesto, nunca se fabrica); `active` es el estado operativo DERIVADO
            // (Branch::derivedActive(): considera is_active y si la ruta del catálogo
            // está inactiva).
            'data' => $branches->getCollection()->map(function (Branch $branch) {
                $attribution = $branch->attributionSummary();

                return [
                    'id' => $branch->id,
                    'code' => $branch->code,
                    'name' => $branch->name,
                    'sucursal' => $attribution['sucursal'],
                    'region' => $attribution['region'],
                    'active' => $attribution['activa_derivada'],
                ];
            })->values(),
            'meta' => [
                'current_page' => $branches->currentPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
                'last_page' => $branches->lastPage(),
            ],
        ]);
    }
}

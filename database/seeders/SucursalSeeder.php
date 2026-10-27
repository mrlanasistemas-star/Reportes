<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Sucursal;
use App\Services\Catalog\BranchRouteCatalog;
use Illuminate\Database\Seeder;

/**
 * Catálogo mantenido por el propietario: las 11 sucursales operativas de OKR
 * (BranchRouteCatalog::OKR_BRANCHES) con su `codigo` institucional estable, su
 * `region` trimestral y activa = true. Luego materializa la atribución
 * branches.sucursal_id desde el catálogo oficial (nunca por parecido en tiempo
 * de ejecución): cada branch que resuelve a una de las 11 recibe su FK; el
 * resto (San Juan del Río, Tenango del Valle, Aguascalientes, Corporativo,
 * agrupaciones de matriz/región, etc.) queda en NULL — jamás se inventa una
 * sucursal.
 *
 * Idempotente: updateOrCreate por `codigo` y comparación antes de escribir la
 * FK; correrlo varias veces no duplica ni reescribe sin cambio.
 */
class SucursalSeeder extends Seeder
{
    /**
     * Las 11, MANTENIDAS POR EL PROPIETARIO (matriz de regiones del propietario):
     * [codigo, nombre, region]. Q1 y Q3; ninguna sucursal en Q2.
     *   - Q1: Cuernavaca, Miacatlán, Atlacomulco, Ixtlahuaca, San Luis Potosí.
     *   - Q3: Atlixco, Córdoba, Huamantla, Orizaba, Tlaxcala, Tula.
     * `nombre` coincide con el nombre oficial del catálogo (acentos como en la
     * BD: CORDOBA, MIACATLAN, SAN LUIS POTOSI). `codigo` es el slug estable.
     *
     * @var list<array{0:string, 1:string, 2:string}>
     */
    private const SUCURSALES = [
        ['CUERNAVACA',      'CUERNAVACA',      'Q1'],
        ['MIACATLAN',       'MIACATLAN',       'Q1'],
        ['ATLACOMULCO',     'ATLACOMULCO',     'Q1'],
        ['IXTLAHUACA',      'IXTLAHUACA',      'Q1'],
        ['SAN_LUIS_POTOSI', 'SAN LUIS POTOSI', 'Q1'],
        ['ATLIXCO',         'ATLIXCO',         'Q3'],
        ['CORDOBA',         'CORDOBA',         'Q3'],
        ['HUAMANTLA',       'HUAMANTLA',       'Q3'],
        ['ORIZABA',         'ORIZABA',         'Q3'],
        ['TLAXCALA',        'TLAXCALA',        'Q3'],
        ['TULA',            'TULA',            'Q3'],
    ];

    public function run(): void
    {
        // 1) Catálogo de las 11 (idempotente por `codigo` estable).
        foreach (self::SUCURSALES as [$codigo, $nombre, $region]) {
            Sucursal::query()->updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'region' => $region, 'activa' => true],
            );
        }

        // 2) Índice nombre-oficial-normalizado → id de sucursal (solo las 11).
        $idPorNombre = [];
        foreach (Sucursal::query()->get(['id', 'nombre']) as $sucursal) {
            $idPorNombre[BranchRouteCatalog::normalize($sucursal->nombre)] = $sucursal->id;
        }

        // 3) Materializa branches.sucursal_id vía catálogo (fuente única).
        Branch::query()->orderBy('id')->chunkById(500, function ($branches) use ($idPorNombre) {
            foreach ($branches as $branch) {
                $sucursalId = $this->resolverSucursalId($branch->name, $idPorNombre);

                if ($branch->sucursal_id !== $sucursalId) {
                    $branch->sucursal_id = $sucursalId;
                    $branch->save();
                }
            }
        });
    }

    /**
     * Resuelve el id de sucursal de un branch por su nombre usando el catálogo
     * oficial. Devuelve null si no corresponde a una de las 11 (incluye los
     * nombres históricos como TENANGO DEL VALLE, que el catálogo reconoce pero
     * no son operativos de OKR).
     *
     * @param  array<string, int>  $idPorNombre
     */
    private function resolverSucursalId(?string $branchName, array $idPorNombre): ?int
    {
        $oficial = BranchRouteCatalog::officialBranchFor($branchName);

        if ($oficial === null || ! BranchRouteCatalog::isOkrOperative($oficial)) {
            return null;
        }

        return $idPorNombre[BranchRouteCatalog::normalize($oficial)] ?? null;
    }
}

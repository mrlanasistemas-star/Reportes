<?php

namespace App\Services\EstadoResultados\Classification;

use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Str;

/**
 * Clasificación automática de filas del ERP que Radiografía EXCLUYE por diseño
 * (sucursal CORPORATIVO, sin sucursal o sucursal no operativa). Estas filas son
 * candidatas a OPEX Corporativo en el Estado de Resultados — Radiografía no se toca.
 *
 * Una requisición ERP de CORPORATIVO con estatus contable válido es evidencia
 * formal de gasto, por eso su regla por defecto es OPEX Corporativo; las
 * excepciones (nómina, pólizas, mobiliario/equipo, Dirección General) se
 * tratan aparte y las ambiguas quedan en revisión.
 */
class ErpOutsideClassifier
{
    /**
     * Clasifica una fila ERP excluida por Radiografía. Orden: Dirección General o gasto personal de
     * socios (retiro, aun sin sucursal) → sin sucursal operativa (pendiente) → nómina (nómina corporativa) → pólizas (pendiente) →
     * mobiliario/equipo (posible CAPEX, pendiente) → OPEX Corporativo por defecto.
     *
     * @param  array  $erp  fila ERP (category, concept, observations, universe, sucursal_raw)
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    public function classify(array $erp): array
    {
        $category = mb_strtoupper(Str::ascii((string) $erp['category']));
        $concept = mb_strtoupper(Str::ascii((string) $erp['concept'].' '.(string) $erp['observations']));

        if (preg_match('/\bDG\b|DIRECCION GENERAL/', $concept) || preg_match(MovementClassifier::PERSONAL_PATTERN, $concept)) {
            // 25-nov-2026: lo que toman DG o los socios es un retiro (debajo de la Utilidad neta), no OPEX.
            return $this->out(T::PERSONAL_SOCIOS, null, 'erp_dg',
                'Requisición ERP de Dirección General / socios: retiro (no es OPEX; se resta después de la Utilidad neta).');
        }
        if ($erp['universe'] !== 'corporativo') {
            return $this->out(T::PENDIENTE, T::OPEX_CORPORATIVO, 'erp_sin_sucursal_operativa',
                'Registro ERP sin sucursal operativa ('.($erp['sucursal_raw'] ?: 'vacía').'). Radiografía lo excluye; confirme a qué nivel pertenece.', true);
        }
        if (str_contains($category, 'NOMINA')) {
            return $this->out(T::NOMINA_CORPORATIVA, null, 'erp_corporativo_nomina',
                'Requisición ERP de Corporativo en categoría Nómina y Capital Humano. Reduce el EBITDA corporativo.');
        }
        if (str_contains($category, 'POLIZA')) {
            return $this->out(T::PENDIENTE, T::OPEX_CORPORATIVO, 'erp_corporativo_polizas',
                'Póliza registrada en ERP para Corporativo. Radiografía excluye pólizas ERP de OPEX; confirme si es un seguro operativo de Corporativo.', true);
        }
        if (str_contains($category, 'MOBILIARIO') || preg_match('/EQUIPO DE COMPUTO|COMPUTADORA|LAPTOP|VEHICULO/', $concept)) {
            return $this->out(T::PENDIENTE, T::CAPEX, 'erp_corporativo_posible_capex',
                'Compra de mobiliario/equipo en Corporativo: puede ser CAPEX (no reduce EBITDA). Confirme el tratamiento.', true);
        }

        return $this->out(T::OPEX_CORPORATIVO, null, 'erp_corporativo_opex',
            'Requisición ERP de Corporativo (estatus contable válido). Radiografía la excluye por diseño; en el ER suma a OPEX Corporativo.');
    }

    /**
     * Resultado de clasificación con la forma estándar.
     *
     * @param  string  $treatment  tratamiento asignado
     * @param  string|null  $suggested  sugerencia para revisión
     * @param  string  $rule  código de regla
     * @param  string  $reason  explicación en español
     * @param  bool  $review  requiere revisión
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    private function out(string $treatment, ?string $suggested, string $rule, string $reason, bool $review = false): array
    {
        return ['treatment' => $treatment, 'suggested' => $suggested, 'rule' => $rule, 'reason' => $reason, 'review' => $review];
    }
}

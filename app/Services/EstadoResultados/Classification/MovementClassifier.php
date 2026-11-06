<?php

namespace App\Services\EstadoResultados\Classification;

use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Str;

/**
 * Reglas AUTOMÁTICAS de clasificación de movimientos complementarios.
 *
 * Principio: sólo hay regla determinista cuando el texto Y el contexto (cargo/abono,
 * sucursal, conciliación) dan evidencia suficiente. Todo lo demás termina en
 * PENDIENTE (requiere revisión) con una SUGERENCIA que el usuario puede aceptar.
 * Ninguna regla convierte un abono en ingreso operativo ni un cargo ambiguo en OPEX.
 *
 * Cada resultado trae rule_code + reason legible: la UI responde "¿por qué sí/no sumó?".
 */
class MovementClassifier
{
    /**
     * @param  array<string,mixed>  $m  movimiento (columnas de estado_resultados_movements)
     * @param  array<string,mixed>|null  $rec  resultado del reconciliador (sólo cargos en periodo)
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    public function classify(array $m, ?array $rec): array
    {
        if (! ($m['in_period'] ?? true)) {
            return $this->out(T::FUERA_PERIODO, null, 'fuera_periodo', 'La fecha del movimiento no cae dentro del periodo; se considera en el periodo al que pertenece.');
        }

        $concept = $this->norm($m['concept'] ?? '');
        $obs = $this->norm($m['observations'] ?? '');
        $ref = $this->norm($m['bank_reference'] ?? '');
        $text = trim($concept.' '.$obs);
        $isCargo = (float) ($m['cargo'] ?? 0) > 0;
        $kind = (string) ($m['branch_kind'] ?? 'vacia');

        // ── Transferencias entre cuentas propias (ambos sentidos) ─────────
        if (str_contains($concept, 'TRASPASO ENTRE CUENTAS')
            || str_contains($ref, 'TRASPASO CUENTAS PROPIAS')
            || preg_match('/\b(EXCEDENTES|ADMINISTRATIVA|PROVISION)\s+A\s+(EXCEDENTES|ADMINISTRATIVA|PROVISION)\b/', $obs)) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'traspaso_cuentas_propias', 'Traspaso entre cuentas propias ('.($m['observations'] ?: 'TRASPASO ENTRE CUENTAS').'). No es ingreso ni gasto; se conserva para conciliar el flujo.');
        }

        if (! $isCargo) {
            return $this->classifyAbono($concept, $text);
        }

        // ── Cargos: primero el resultado de conciliación contra ERP ──────
        $status = $rec['status'] ?? R::SIN_FOLIO;
        if (in_array($status, R::CONCILIADOS, true)) {
            // Aplicación parcial con excedente bancario: sólo lo aplicado se cuenta desde el ERP;
            // el remanente NO se incluye ni se excluye en automático.
            if (isset($rec['pending']) && ! Money::isZeroCents($rec['pending'])) {
                return $this->out(T::PENDIENTE, $this->suggestForCargo($concept, $kind, $m), 'conciliado_parcial_excedente', (string) ($rec['message'] ?? 'Conciliación parcial.'), true);
            }

            return $this->out(T::CONCILIADO_ERP, null, $status, (string) ($rec['message'] ?? 'Conciliado con ERP.'));
        }
        if ($status === R::DUPLICADO_UUID) {
            return $this->out(T::DUPLICADO, null, $status, (string) $rec['message']);
        }
        if (in_array($status, R::REVISION, true)) {
            return $this->out(T::PENDIENTE, $this->suggestForCargo($concept, $kind, $m), $status, (string) $rec['message'], true);
        }

        return $this->classifyCargo($concept, $text, $kind, $m, $status, $rec);
    }

    private function classifyAbono(string $concept, string $text): array
    {
        $noIngreso = ' Un abono bancario nunca se registra como ingreso operativo en automático.';

        if (str_contains($concept, 'RETIRO EXCEDENTE') || str_contains($concept, 'EXCEDENTE')) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'excedente_sucursal', 'Excedente de efectivo enviado por la sucursal a Corporativo (en Radiografía es "Envío de utilidad a corporativo"). Flujo interno, no ingreso.');
        }
        if (str_contains($concept, 'PROVISION IMSS') || str_contains($concept, 'PROVISION')) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'provision_sucursal', 'Provisión depositada por la sucursal (IMSS). Flujo interno; el IMSS patronal ya está en Nómina y Capital Humano de sucursales.');
        }
        if (preg_match('/DEPOSITO.*CREDITO|CREDITO (LEROM|BBVA)/', $concept)) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'fondeo_servicio_deuda', 'Depósito de sucursal para cubrir el servicio de deuda (LEROM/BBVA). Flujo interno, no ingreso.');
        }
        if (preg_match('/^DESC(UENTO)?\b|DESC PRESTAMO|DESC VENTA/', $concept)) {
            return $this->out(T::RECUPERABLE, null, 'descuento_recuperado', 'Recuperación de préstamo/venta a colaborador vía descuento. Es cobro de una cuenta por cobrar, no ingreso.');
        }
        if (preg_match('/DEVOLUCION|REEMBOLSO|REINTEGRO/', $text)) {
            // El procesador lo vincula con el gasto que reembolsa (mismo folio); sin vínculo queda pendiente.
            return $this->out(T::PENDIENTE, T::RECUPERABLE, 'reembolso_sin_vinculo', 'Reintegro/devolución sin gasto reconocido vinculado (mismo folio). No es ingreso; confirme si reduce un gasto o es una cuenta por cobrar.'.$noIngreso, true);
        }
        if (str_contains($concept, 'POLIZA')) {
            return $this->out(T::PUENTE_TERCEROS, null, 'poliza_puente', 'Cobro de pólizas por cuenta de la aseguradora (puente). Mismo criterio que Radiografía: no es ingreso.');
        }
        if (preg_match('/INDEMNIZACION|INTERES(ES)? GANADO|RENDIMIENTO/', $text)) {
            return $this->out(T::PENDIENTE, T::INGRESO_NO_OPERATIVO, 'abono_posible_no_operativo', 'Abono que podría ser ingreso no operativo (indemnización/rendimiento). Requiere confirmación.'.$noIngreso, true);
        }

        return $this->out(T::PENDIENTE, null, 'abono_sin_regla', 'Abono sin regla de clasificación.'.$noIngreso, true);
    }

    private function classifyCargo(string $concept, string $text, string $kind, array $m, string $status, ?array $rec): array
    {
        // Préstamos ("PAGO PRESTAMOS QUINCENA 17") NUNCA son nómina aunque mencionen la quincena.
        $isNomina = ! str_contains($concept, 'PRESTAMO')
            && (bool) preg_match('/\bPAGO NOM\b|\bNOM(INA)?\b|\bQUINCENA\b|\bNOM SEM\b/', $concept);
        $suggested = $this->suggestForCargo($concept, $kind, $m);

        // Retenciones / pasivos de terceros: no son gasto de la empresa.
        if (preg_match('/SAT RETENCION|RETENCIONES|CEDULA FONACOT|FONACOT|PENSION ALIMENTICIA|INFONAVIT/', $concept)) {
            return $this->out(T::BALANCE, null, 'retenciones_terceros', 'Entero de retenciones o descuentos de terceros (SAT/FONACOT/pensión/Infonavit). Es un pasivo que se liquida, no un gasto.');
        }
        // Servicio de deuda bancaria: el pago completo NO es gasto financiero.
        if (preg_match('/CREDITO (LEROM|BBVA)|PAGO \d+ CREDITO|AMORTIZACION (DE )?CREDITO/', $concept)) {
            return $this->out(T::CAPITAL_DEUDA, null, 'servicio_deuda', 'Pago a acreedor ('.($m['concept'] ?? '').'). El capital no es gasto; los intereses se capturan en Ajustes (Intereses BBVA/LEROM) para no meter el pago completo como gasto financiero.');
        }
        // Pólizas cobradas por cuenta de terceros.
        if (preg_match('/POLIZAS? (CRECE|GRUPAL|GALNAG)|PAGO POLIZAS GALNAG/', $concept)) {
            return $this->out(T::PUENTE_TERCEROS, null, 'poliza_puente', 'Pago de pólizas cobradas a clientes (puente a aseguradora). Mismo criterio que Radiografía: no es OPEX.');
        }

        if ($kind === 'operativa' || $kind === 'agrupado') {
            if ($isNomina) {
                return $this->out(T::CUBIERTO_RADIOGRAFIA, null, 'nomina_sucursal_noi', 'Dispersión de nómina de sucursal: el costo ya está en Nómina y Capital Humano (NOI) de Radiografía. Sumarlo otra vez lo duplicaría.');
            }

            $why = $status === R::FOLIO_SIN_ERP
                ? 'Movimiento de sucursal con folio que no aparece en el ERP cargado.'
                : 'Movimiento de sucursal sin conciliación con el ERP.';

            return $this->out(T::PENDIENTE, $suggested, $status === R::FOLIO_SIN_ERP ? 'sucursal_folio_sin_erp' : 'sucursal_sin_erp', $why.' Radiografía ya integra ERP/Lendus/NOI de las sucursales: confirme que no está registrado antes de sumarlo al ER.', true);
        }

        if ($kind === 'corporativo') {
            if ($this->hasDg($concept)) {
                return $this->out(T::PENDIENTE, $suggested, 'dg_requiere_revision', 'Movimiento de Dirección General: puede ser gasto empresarial, remuneración, CAPEX o un gasto personal. Requiere clasificación explícita.', true);
            }
            if ($isNomina || preg_match('/\bFINIQUITO\b/', $concept)) {
                return $this->out(T::NOMINA_CORPORATIVA, null, 'nomina_corporativa', 'Pago de nómina/finiquito del personal de Corporativo (no está en el NOI de sucursales). Reduce el EBITDA corporativo.');
            }
            if ($status === R::FOLIO_SIN_ERP) {
                return $this->out(T::PENDIENTE, $suggested, 'corporativo_folio_sin_erp', 'Requisición de Corporativo cuyo folio no está en el ERP cargado. Confirme que no se registró con otro folio/periodo antes de sumarla.', true);
            }

            return $this->out(T::PENDIENTE, $suggested, 'corporativo_sin_folio', 'Pago de Corporativo sin requisición (S/R). El concepto por sí solo no es evidencia suficiente para clasificarlo en automático.', true);
        }

        return $this->out(T::PENDIENTE, $suggested, 'sin_sucursal', 'Cargo sin sucursal reconocible ('.($m['sucursal_raw'] ?: 'vacía').'). Requiere revisión.', true);
    }

    /** Sugerencia (NUNCA aplicada en automático) para un cargo que requiere revisión. */
    private function suggestForCargo(string $concept, string $kind, array $m): ?string
    {
        if (preg_match('/COLEGIATURA|ESCUELA|PASEADOR|VETERINARIO|JARDINERO|LAVANDERIA|FAMILIA|ENTRENADOR|EQUITACION|JIUJITSU|CASA DG/', $concept)) {
            return T::PERSONAL_SOCIOS;
        }
        if (preg_match('/COMPUTADORA|EQ(UIPO)? (DE )?COMPUTO|LAPTOP|ROUTER|MOBILIARIO|\bBYD\b|FIRMA CAR|VEHICULO|AUTOMOVIL/', $concept)) {
            return T::CAPEX;
        }
        if (preg_match('/PRESTAMO/', $concept)) {
            return T::RECUPERABLE;
        }
        if ($kind === 'operativa' || $kind === 'agrupado') {
            return preg_match('/FINIQUITO|\bNOM/', $concept) ? T::NOMINA_SUCURSAL : T::OPEX_SUCURSAL;
        }
        if ($kind === 'corporativo') {
            if ($this->hasDg($concept)) {
                return T::DG_OPERATIVO;
            }
            if (preg_match('/COMISION/', $concept)) {
                return T::NOMINA_CORPORATIVA;
            }
            if ($concept === 'PAGO' || $concept === '') {
                return null; // "PAGO" genérico: sin evidencia ni para sugerir
            }

            return T::OPEX_CORPORATIVO;
        }

        return null;
    }

    private function hasDg(string $concept): bool
    {
        return (bool) preg_match('/\bDG\b|DIRECCION GENERAL/', $concept);
    }

    private function norm(?string $v): string
    {
        $v = mb_strtoupper(Str::ascii(trim((string) $v)));

        return preg_replace('/\s+/', ' ', $v) ?? $v;
    }

    private function out(string $treatment, ?string $suggested, string $rule, string $reason, bool $review = false): array
    {
        return ['treatment' => $treatment, 'suggested' => $suggested, 'rule' => $rule, 'reason' => $reason, 'review' => $review];
    }
}

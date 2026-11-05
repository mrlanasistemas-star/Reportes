<?php

namespace App\Services\Efectividad\V12;

/**
 * Estado de una corrida V12.9.4 (equivalente a los diccionarios de
 * `Generar_Efectividad_Cobranza`). Lo comparten las etapas del motor; no se
 * persiste nunca.
 *
 * Una CUOTA (fila de la tabla interna de amortizaciones) es un arreglo:
 *  - contrato, numero (texto: número de cuota o "Faaaammdd"), fecha, matura
 *    (vencimiento del contrato), monto (cuota ordinaria), previo (pagado antes
 *    del inicio del periodo), cancela (fecha de cambio contractual, 0 = sin),
 *    tipo (motivo de cancelación), previo_original (lo que reconstruyó la tabla
 *    a partir de las fotografías, antes de conciliar).
 *
 * Un EVENTO (aplicación de ingreso a una cuota) es:
 *  - fe (fecha transacción), fq (fecha cuota), monto, ref (descuento de
 *    refinanciamiento), id (folio, si el archivo lo trae), fila (referencia).
 */
final class V12Corrida
{
    public int $domingo;      // domingo base de la semana

    public int $corte;        // fecha de corte

    public int $fotoInicio;   // índice de la fotografía base (0 = domingo)

    public int $fBase;        // fecha de la fotografía base = domingo + fotoInicio

    public int $repIni;       // primer día reportado

    public int $repFin;       // último día reportado

    public string $modo;

    /** @var array<int, array{columnas: list<string>, filas: list<array<string,mixed>>}> índice = días desde el domingo */
    public array $fotos = [];

    /** @var array{columnas: list<string>, filas: list<array<string,mixed>>} */
    public array $historial = ['columnas' => [], 'filas' => []];

    /** @var array<string, array<int,string>> contrato => [día => grupo "OFICINA~PROMOTOR"] */
    public array $responsables = [];

    /** @var array<string,true> grupos vistos en las fotografías */
    public array $grupos = [];

    /** @var array<string,float> contrato => atraso del corte inicial */
    public array $atrasoBase = [];

    /** @var list<array<string,mixed>> tabla AMORTIZACIONES generada (en orden) */
    public array $amortizaciones = [];

    /** @var array<string, array<string,mixed>> "contrato|numero" => cuota */
    public array $cuotas = [];

    /** @var array<string, list<string>> contrato => claves de sus cuotas (en orden) */
    public array $cuotasDe = [];

    /** @var array<string,string> "contrato|aaaammdd" => clave ('' = ambigua) */
    public array $porFecha = [];

    /** @var array<string, list<array<string,mixed>>> clave cuota => eventos */
    public array $eventos = [];

    /** @var array<string,true> marcas de evidencia (REFI-CONCILIADO|…, FOTO-ATRASO|…, ULTIMA-SALDO|…) */
    public array $notas = [];

    /** @var array<string, list<float|int>> contrato => [saldo, proyección, ajuste, cuotas] */
    public array $conciliados = [];

    /** @var array<string,float> clave cuota => importe asignado por inferencia */
    public array $estimadas = [];

    /** @var array<string, array{fecha:int, saldo_base:float, diario:float}> */
    public array $cierresRefi = [];

    /** @var array<int, array<string, array<int,mixed>>> índice de foto => resumen por contrato */
    private array $resumenes = [];

    /** @var array<string, array<string,mixed>> incidencias de auditoría (no alteran cifras) */
    public array $incidencias = [];

    public function numeroFotoFinal(): int
    {
        return $this->corte - $this->domingo;
    }

    /** MR_Responsable — gestor efectivo del contrato en la fecha (busca hasta 14 días atrás). */
    public function responsable(string $contrato, int $f): string
    {
        $dias = $this->responsables[$contrato] ?? null;
        if ($dias === null) {
            return '';
        }
        for ($i = 0; $i <= 14; $i++) {
            if (isset($dias[$f - $i])) {
                return $dias[$f - $i];
            }
        }

        return '';
    }

    public function incidencia(string $codigo, string $contrato, string $descripcion, array $extra = []): void
    {
        $id = $codigo.'|'.$contrato.(isset($extra['referencia']) ? '|'.$extra['referencia'] : '');
        $this->incidencias[$id] ??= ['id' => $id, 'codigo' => $codigo, 'contrato' => $contrato !== '' ? $contrato : null,
            'referencia' => $extra['referencia'] ?? null, 'fecha' => $extra['fecha'] ?? null,
            'importe' => $extra['importe'] ?? null, 'descripcion' => $descripcion];
    }

    /** Columna presente en la fotografía (equivale a `MR_Col(...) > 0`). */
    public function tieneFoto(int $indice, string ...$campos): bool
    {
        $cols = $this->fotos[$indice]['columnas'] ?? [];
        foreach ($campos as $c) {
            if (! in_array($c, $cols, true)) {
                return false;
            }
        }

        return true;
    }

    public function tieneHist(string ...$campos): bool
    {
        foreach ($campos as $c) {
            if (! in_array($c, $this->historial['columnas'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * MR_ResumenFotos — contrato => [0 EN, 1 atrasado ordinario (EE:EG), 2 estatus,
     * 3 total de pagos (BX), 4 cuota nominal (AZ), 5 saldo ordinario pendiente sin
     * atrasos (DX:DZ), 6 gestor, 7 vencimiento contrato, 8 saldo insoluto ordinario
     * (CC+CG+CK), 9 pagos atrasados (ED)]. La última fila del contrato gana.
     *
     * @return array<string, array<int,mixed>>
     */
    public function resumen(int $indice): array
    {
        if (isset($this->resumenes[$indice])) {
            return $this->resumenes[$indice];
        }
        $out = [];
        if (! isset($this->fotos[$indice])) {
            return $this->resumenes[$indice] = $out;
        }
        if (! $this->tieneFoto($indice, 'contrato', 'numero_siguiente_pago', 'pagos', 'cuota', 'capital_pendiente', 'interes_pendiente', 'impuesto_pendiente')) {
            throw new V12Exception('EC-V12-320', 'Faltan columnas indispensables en '.EfectividadV12Calculator::NOMBRES_FOTO[$indice].'.');
        }
        $conAtr = $this->tieneFoto($indice, 'capital_atrasado', 'interes_atrasado', 'impuesto_atrasado');
        $conED = $this->tieneFoto($indice, 'pagos_atrasados');
        $conAY = $this->tieneFoto($indice, 'fecha_vencimiento');
        $cols = $this->fotos[$indice]['columnas'];
        foreach ($this->fotos[$indice]['filas'] as $r) {
            $ct = V12Vba::norm($r['contrato'] ?? null);
            if ($ct === '') {
                continue;
            }
            $atr = $conAtr ? V12Vba::suma(V12Vba::num($r['capital_atrasado']), V12Vba::num($r['interes_atrasado']), V12Vba::num($r['impuesto_atrasado'])) : 0.0;
            $saldoTotal = 0.0;
            foreach (['saldo_capital', 'saldo_interes', 'saldo_impuesto'] as $c) {
                if (in_array($c, $cols, true)) {
                    $saldoTotal += V12Vba::num($r[$c] ?? null);
                }
            }
            $out[$ct] = [
                V12Vba::clng($r['numero_siguiente_pago'] ?? null),
                max(0.0, $atr),
                V12Vba::norm($r['estatus'] ?? null),
                V12Vba::clng($r['pagos'] ?? null),
                V12Vba::num($r['cuota'] ?? null),
                max(0.0, V12Vba::suma(V12Vba::num($r['capital_pendiente']), V12Vba::num($r['interes_pendiente']), V12Vba::num($r['impuesto_pendiente']))),
                V12Vba::norm($r['gestor'] ?? null),
                $conAY ? (V12Vba::fecha($r['fecha_vencimiento'] ?? null) ?? 0) : 0,
                $saldoTotal,
                $conED ? V12Vba::clng($r['pagos_atrasados'] ?? null) : 0,
            ];
        }

        return $this->resumenes[$indice] = $out;
    }

    /** MR_AtrasoResumen — atraso ordinario; si el contrato ya venció, todo su saldo insoluto. */
    public static function atrasoResumen(array $v, int $f): float
    {
        if ($v[7] > 0 && $v[7] < $f) {
            return max(0.0, (float) $v[8]);
        }

        return (float) $v[1];
    }

    /** Eventos de una cuota cuya fecha de transacción cumple el filtro. */
    public function sumaEventos(string $clave, callable $filtro): float
    {
        $s = 0.0;
        foreach ($this->eventos[$clave] ?? [] as $e) {
            if ($filtro($e)) {
                $s += $e['monto'];
            }
        }

        return $s;
    }

    /** MR_PagoOrdinarioAntesDeLiquidar. */
    public function pagoOrdinarioAntesDeLiquidar(string $clave, int $inicio, int $liquidacion): bool
    {
        foreach ($this->eventos[$clave] ?? [] as $e) {
            if (! $e['ref'] && $e['fe'] >= $inicio && $e['fe'] <= $liquidacion) {
                return true;
            }
        }

        return false;
    }

    /** MR_ImporteOrdinarioEnCancelacion — recuperación ordinaria REAL hasta el cierre contractual. */
    public function importeOrdinarioEnCancelacion(string $clave, int $inicio, int $cancelacion): float
    {
        $s = 0.0;
        foreach ($this->eventos[$clave] ?? [] as $e) {
            if (! $e['ref'] && $e['fe'] >= $inicio && $e['fe'] <= $cancelacion) {
                $s += max(0.0, $e['monto']);
            }
        }

        return $s;
    }
}

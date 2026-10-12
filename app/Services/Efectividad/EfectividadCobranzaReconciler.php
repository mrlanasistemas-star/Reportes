<?php

namespace App\Services\Efectividad;

/**
 * Etapa 6 — conciliación INTERNA del resultado del calculador aprobado.
 *
 * Verifica que la suma de las filas por promotor coincida con cada
 * "TOTAL OFICINA — …" y que la suma de oficinas coincida con el GRAN TOTAL,
 * para cada importe (por cobrar y cobrado de Sano/Atraso/Vencido).
 *
 * Importante: NO es una conciliación contra una fuente externa (Lendus, ERP,
 * contabilidad). Nunca se presenta como tal — el resumen lo dice
 * explícitamente.
 */
class EfectividadCobranzaReconciler
{
    private const FIELDS = ['sano_pc', 'sano_cob', 'atraso_pc', 'atraso_cob', 'vencido_pc', 'vencido_cob'];

    private const TOLERANCE = 0.01;

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{ok:bool, offices:int, promoters:int, differences:array<int,array<string,mixed>>, summary:string}
     */
    public function check(array $rows): array
    {
        $differences = [];
        $officeSum = array_fill_keys(self::FIELDS, 0.0);
        $promoterSum = array_fill_keys(self::FIELDS, 0.0);
        $currentOffice = array_fill_keys(self::FIELDS, 0.0);
        $offices = 0;
        $promoters = 0;
        $grandTotal = null;

        foreach ($rows as $row) {
            $isTotal = (bool) ($row['es_total'] ?? false);
            $label = (string) ($row['promotor'] ?? '');

            if (! $isTotal) {
                $promoters++;
                foreach (self::FIELDS as $f) {
                    $currentOffice[$f] += (float) ($row[$f] ?? 0);
                    $promoterSum[$f] += (float) ($row[$f] ?? 0);
                }

                continue;
            }

            if ($label === 'GRAN TOTAL') {
                $grandTotal = $row;

                continue;
            }

            $offices++;
            foreach (self::FIELDS as $f) {
                if (abs($currentOffice[$f] - (float) ($row[$f] ?? 0)) > self::TOLERANCE) {
                    $differences[] = ['scope' => $label, 'field' => $f, 'expected' => round($currentOffice[$f], 2), 'reported' => round((float) ($row[$f] ?? 0), 2)];
                }
                $officeSum[$f] += (float) ($row[$f] ?? 0);
            }
            $currentOffice = array_fill_keys(self::FIELDS, 0.0);
        }

        if ($grandTotal !== null) {
            foreach (self::FIELDS as $f) {
                foreach (['oficinas' => $officeSum, 'promotores' => $promoterSum] as $basis => $sums) {
                    if (abs($sums[$f] - (float) ($grandTotal[$f] ?? 0)) > self::TOLERANCE) {
                        $differences[] = ['scope' => "GRAN TOTAL vs {$basis}", 'field' => $f, 'expected' => round($sums[$f], 2), 'reported' => round((float) ($grandTotal[$f] ?? 0), 2)];
                    }
                }
            }
        }

        $ok = $differences === [] && $grandTotal !== null;

        return [
            'ok' => $ok,
            'offices' => $offices,
            'promoters' => $promoters,
            'differences' => $differences,
            'summary' => $ok
                ? "Totales internos consistentes: {$promoters} promotores en {$offices} oficinas suman exactamente el resultado general. (Verificación interna del cálculo; no es una comparación contra una fuente externa.)"
                : ($grandTotal === null
                    ? 'El resultado no contiene la fila de resultado general (GRAN TOTAL).'
                    : 'Se detectaron diferencias entre las filas por promotor, los totales por oficina y el resultado general.'),
        ];
    }
}

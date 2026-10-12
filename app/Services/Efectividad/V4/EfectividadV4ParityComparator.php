<?php

namespace App\Services\Efectividad\V4;

/**
 * Compara el resultado del motor PHP contra la hoja EFECTIVIDAD y la bitácora
 * _AUDITORIA_EFECTIVIDAD del libro de referencia, fila por fila y campo por campo.
 *
 * Tolerancias (explícitas, no redondeo):
 *  - Montos: 0.000001 (1e-6). El motor reproduce el mismo orden de sumas en
 *    doble precisión que la macro, así que lo esperado es igualdad exacta; el
 *    margen solo absorbe ruido binario y queda 4 órdenes por debajo del centavo.
 *  - Porcentajes (razón 0-1): 1e-12.
 * Cualquier diferencia mayor es "DIFERENTE", sin excepciones.
 */
class EfectividadV4ParityComparator
{
    public const TOL_MONTO = 0.000001;

    public const TOL_PCT = 1e-12;

    private const CAMPOS = [
        'sano_pc' => 'monto', 'sano_cob' => 'monto', 'sano_pct' => 'pct',
        'atraso_pc' => 'monto', 'atraso_cob' => 'monto', 'atraso_pct' => 'pct',
        'vencido_pc' => 'monto', 'vencido_cob' => 'monto', 'vencido_pct' => 'pct',
        'cobranza_total' => 'monto', 'pct_total' => 'pct', 'mora' => 'monto',
    ];

    /**
     * @return array{filas: list<array<string,mixed>>, resumen: array<string,mixed>, incidencias: array<string,mixed>}
     */
    public function compare(array $esperado, array $calculado, array $incidenciasEsperadas = [], array $incidenciasCalculadas = []): array
    {
        $porLlave = [];
        foreach ($calculado as $fila) {
            $porLlave[$this->llave($fila)] = $fila;
        }

        $detalle = [];
        $comparados = 0;
        $diferentes = 0;
        $exactos = 0;
        $usadas = [];
        foreach ($esperado as $e) {
            $llave = $this->llave($e);
            $c = $porLlave[$llave] ?? null;
            if ($c === null) {
                $detalle[] = ['oficina' => $e['oficina'], 'promotor' => $e['promotor'], 'tipo' => $e['tipo'], 'campo' => '*', 'excel' => null, 'laravel' => null, 'diferencia' => null, 'tolerancia' => null, 'estado' => 'FALTA EN LARAVEL'];
                $diferentes++;

                continue;
            }
            $usadas[$llave] = true;
            foreach (self::CAMPOS as $campo => $tipo) {
                $x = $e[$campo];
                $y = (float) $c[$campo];
                $tol = $tipo === 'monto' ? self::TOL_MONTO : self::TOL_PCT;
                $dif = $x === null ? null : $y - $x;
                $ok = $x !== null && abs($dif) <= $tol;
                $comparados++;
                if ($ok && $dif == 0.0) {
                    $exactos++;
                }
                if (! $ok) {
                    $diferentes++;
                }
                $detalle[] = [
                    'oficina' => $e['oficina'], 'promotor' => $e['promotor'], 'tipo' => $e['tipo'], 'campo' => $campo,
                    'excel' => $x, 'laravel' => $y, 'diferencia' => $dif, 'tolerancia' => $tol,
                    'estado' => $ok ? ($dif == 0.0 ? 'IGUAL' : 'IGUAL (tolerancia)') : 'DIFERENTE',
                ];
            }
        }
        foreach ($porLlave as $llave => $c) {
            if (! isset($usadas[$llave])) {
                $detalle[] = ['oficina' => $c['oficina'], 'promotor' => $c['promotor'], 'tipo' => $c['tipo'], 'campo' => '*', 'excel' => null, 'laravel' => null, 'diferencia' => null, 'tolerancia' => null, 'estado' => 'SOBRA EN LARAVEL'];
                $diferentes++;
            }
        }

        $ordenEsperado = array_map(fn ($f) => $this->llave($f), $esperado);
        $ordenCalculado = array_map(fn ($f) => $this->llave($f), $calculado);

        $idsE = array_column($incidenciasEsperadas, 'descripcion', 'id');
        $idsC = array_column($incidenciasCalculadas, 'descripcion', 'id');
        $soloExcel = array_values(array_diff(array_keys($idsE), array_keys($idsC)));
        $soloLaravel = array_values(array_diff(array_keys($idsC), array_keys($idsE)));
        $textoDistinto = [];
        foreach (array_intersect(array_keys($idsE), array_keys($idsC)) as $id) {
            if ($idsE[$id] !== $idsC[$id]) {
                $textoDistinto[] = $id;
            }
        }
        $porCodigo = [];
        foreach ($incidenciasCalculadas as $i) {
            $porCodigo[$i['codigo']] = ($porCodigo[$i['codigo']] ?? 0) + 1;
        }

        return [
            'filas' => $detalle,
            'resumen' => [
                'filas_excel' => count($esperado),
                'filas_laravel' => count($calculado),
                'valores_comparados' => $comparados,
                'valores_exactos' => $exactos,
                'diferencias' => $diferentes,
                'mismo_orden' => $ordenEsperado === $ordenCalculado,
                'paridad' => $diferentes === 0 && $ordenEsperado === $ordenCalculado,
            ],
            'incidencias' => [
                'excel' => count($idsE),
                'laravel' => count($idsC),
                'solo_excel' => $soloExcel,
                'solo_laravel' => $soloLaravel,
                'texto_distinto' => $textoDistinto,
                'mismo_orden' => array_keys($idsE) === array_keys($idsC),
                'por_codigo' => $porCodigo,
                'paridad' => ! $soloExcel && ! $soloLaravel && ! $textoDistinto,
            ],
        ];
    }

    private function llave(array $fila): string
    {
        return $fila['tipo'].'|'.mb_strtoupper((string) ($fila['oficina'] ?? '')).'|'.$fila['promotor'];
    }
}

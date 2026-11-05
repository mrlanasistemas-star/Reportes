<?php

namespace Tests\Support;

use App\Services\Efectividad\V12\EfectividadV12Calculator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Datos sintéticos del motor V12.9.4 en el formato canónico que recibe
 * `EfectividadV12Calculator::calculate()`.
 *
 * Semana fija: domingo base 25/10/2026 (foto 0) → domingo de corte 01/11/2026 (foto 7).
 *
 * Cada contrato se describe con una especificación y las fotografías se DERIVAN de
 * su calendario y de los pagos (como en Lendus, la foto de un día refleja los pagos
 * aplicados hasta ese día), para que sean coherentes entre sí:
 *  - cuota vencida (fecha < foto) con saldo → atrasado (EE) y cuenta en ED;
 *  - EN = primera cuota no vencida con saldo; DX = saldo de las cuotas no vencidas;
 *  - saldo capital (CC) = atrasado + pendiente (interés e IVA en cero).
 *
 * Especificación de contrato (claves opcionales con valor por defecto):
 *  contrato, oficina ('CORDOBA'), promotor ('ANA'), promotores ([índice foto => promotor]
 *  a partir de esa foto), primer_pago, pagos (10), cuota (1000), periodicidad ('SEMANAL'),
 *  estatus ('VIGENTE'), presente (índices de foto donde aparece; todos por defecto),
 *  pagado_previo ([n => importe] pagado antes de la semana SIN folio en el historial;
 *  por defecto las cuotas que vencen hasta el domingo base están liquidadas y las demás en 0).
 */
final class EfectividadV12Fixtures
{
    public const DOMINGO = '2026-10-25';

    public const CORTE = '2026-11-01';

    public const COLUMNAS_FOTO = [
        'contrato', 'oficina', 'nombre_promotor', 'gestor', 'estatus', 'fecha_primer_pago', 'fecha_vencimiento', 'cuota',
        'periodicidad', 'pagos', 'saldo_capital', 'saldo_interes', 'saldo_impuesto', 'pagos_pendientes', 'capital_pendiente',
        'interes_pendiente', 'impuesto_pendiente', 'pagos_atrasados', 'capital_atrasado', 'interes_atrasado', 'impuesto_atrasado',
        'numero_siguiente_pago', 'fecha_siguiente_pago',
    ];

    public const COLUMNAS_INGRESOS = [
        'contrato', 'operacion', 'concepto', 'transaccion', 'fecha_transaccion', 'fecha_cuota', 'numero_cuota',
        'capital', 'interes', 'impuesto', 'producto',
    ];

    /** Fecha 'Y-m-d' del día `i` de la semana (0 = domingo base, 7 = domingo de corte). */
    public static function dia(int $i): string
    {
        return self::sumarDias(self::DOMINGO, $i);
    }

    public static function contrato(string $contrato, array $o = []): array
    {
        return ['contrato' => $contrato] + $o + [
            'oficina' => 'CORDOBA', 'promotor' => 'ANA', 'promotores' => [], 'primer_pago' => '2026-10-14',
            'pagos' => 10, 'cuota' => 1000.0, 'periodicidad' => 'SEMANAL', 'estatus' => 'VIGENTE',
            'presente' => range(0, 7), 'pagado_previo' => [],
        ];
    }

    /** Calendario contractual: n => 'Y-m-d' (misma regla que MR_SiguienteCuota). */
    public static function fechas(array $spec): array
    {
        $out = [];
        $tipo = strtoupper($spec['periodicidad']);
        $f = $spec['primer_pago'];
        for ($n = 1; $n <= $spec['pagos']; $n++) {
            if ($n > 1) {
                if (str_contains($tipo, 'DIAR')) {
                    do {
                        $f = self::sumarDias($f, 1);
                    } while ((int) self::fecha($f)->format('N') > 5);
                } elseif (str_contains($tipo, 'MENS')) {
                    $f = self::fecha($spec['primer_pago'])->modify('+'.($n - 1).' month')->format('Y-m-d');
                } else {
                    $f = self::sumarDias($spec['primer_pago'], ($n - 1) * (str_contains($tipo, 'QUINC') ? 14 : 7));
                }
            }
            $out[$n] = $f;
        }

        return $out;
    }

    public static function fechaCuota(array $spec, int $n): string
    {
        return self::fechas($spec)[$n];
    }

    /** Movimiento de INGRESOS / historial aplicado a la cuota `n` del contrato. */
    public static function pago(array $spec, int $n, string $fechaTransaccion, float $monto, string $operacion = 'PAGO A CONTRATO', string $transaccion = 'PAGO'): array
    {
        return [
            'contrato' => $spec['contrato'], 'operacion' => $operacion, 'concepto' => '', 'transaccion' => $transaccion,
            'fecha_transaccion' => $fechaTransaccion, 'fecha_cuota' => self::fechaCuota($spec, $n), 'numero_cuota' => $n,
            'capital' => $monto, 'interes' => 0.0, 'impuesto' => 0.0, 'producto' => '',
        ];
    }

    /** Descuento de refinanciamiento que liquida el saldo restante de las cuotas `$desde`..`pagos`. */
    public static function refinanciamiento(array $spec, string $fecha, int $desde, array $importes = []): array
    {
        $out = [];
        for ($n = $desde; $n <= $spec['pagos']; $n++) {
            $out[] = self::pago($spec, $n, $fecha, $importes[$n] ?? (float) $spec['cuota'], 'REFINANCIAMIENTO', 'DESCUENTO');
        }

        return $out;
    }

    /** Fila de la fotografía del día `i` (null si el contrato no aparece ese día). */
    public static function filaFoto(array $spec, int $i, array $pagos): ?array
    {
        if (! in_array($i, $spec['presente'], true)) {
            return null;
        }
        $hoy = self::dia($i);
        $fechas = self::fechas($spec);
        $cuota = (float) $spec['cuota'];
        $pagado = [];
        foreach ($fechas as $n => $f) {
            $pagado[$n] = $spec['pagado_previo'][$n] ?? ($f <= self::DOMINGO ? $cuota : 0.0);
        }
        foreach ($pagos as $p) {
            if ($p['contrato'] === $spec['contrato'] && $p['fecha_transaccion'] <= $hoy) {
                $pagado[(int) $p['numero_cuota']] += $p['capital'] + $p['interes'] + $p['impuesto'];
            }
        }
        $atrasado = 0.0;
        $nAtrasados = 0;
        $pendiente = 0.0;
        $siguiente = null;
        foreach ($fechas as $n => $f) {
            $resta = max(0.0, $cuota - $pagado[$n]);
            if ($f < $hoy) {
                $atrasado += $resta;
                $nAtrasados += $resta > 0.005 ? 1 : 0;
            } else {
                $pendiente += $resta;
                if ($siguiente === null && $resta > 0.005) {
                    $siguiente = $n;
                }
            }
        }
        $siguiente ??= $spec['pagos'] + 1;
        $promotor = $spec['promotor'];
        ksort($spec['promotores']);
        foreach ($spec['promotores'] as $desde => $nombre) {
            if ($i >= $desde) {
                $promotor = $nombre;
            }
        }

        return [
            'contrato' => $spec['contrato'], 'oficina' => $spec['oficina'], 'nombre_promotor' => $promotor, 'gestor' => $promotor,
            'estatus' => $spec['estatus'], 'fecha_primer_pago' => $spec['primer_pago'], 'fecha_vencimiento' => $fechas[$spec['pagos']],
            'cuota' => $cuota, 'periodicidad' => $spec['periodicidad'], 'pagos' => $spec['pagos'],
            'saldo_capital' => $atrasado + $pendiente, 'saldo_interes' => 0.0, 'saldo_impuesto' => 0.0,
            'pagos_pendientes' => $spec['pagos'] - $siguiente + 1,
            'capital_pendiente' => $pendiente, 'interes_pendiente' => 0.0, 'impuesto_pendiente' => 0.0,
            'pagos_atrasados' => $nAtrasados, 'capital_atrasado' => $atrasado, 'interes_atrasado' => 0.0, 'impuesto_atrasado' => 0.0,
            'numero_siguiente_pago' => $siguiente, 'fecha_siguiente_pago' => $fechas[$siguiente] ?? null,
        ];
    }

    /** @return array<int, array{columnas: list<string>, filas: list<array<string,mixed>>}> */
    public static function fotos(array $contratos, array $pagos, array $indices = [0, 1, 2, 3, 4, 5, 6, 7]): array
    {
        $out = [];
        foreach ($indices as $i) {
            $filas = [];
            foreach ($contratos as $spec) {
                $fila = self::filaFoto($spec, $i, $pagos);
                if ($fila !== null) {
                    $filas[] = $fila;
                }
            }
            $out[$i] = ['columnas' => self::COLUMNAS_FOTO, 'filas' => $filas];
        }

        return $out;
    }

    public static function ingresos(array $pagos): array
    {
        return ['columnas' => self::COLUMNAS_INGRESOS, 'filas' => array_values($pagos)];
    }

    /**
     * Corre el motor con trazabilidad. `$parametros` admite modo / fecha_corte; en DIARIO
     * solo se entregan las fotos del día anterior y del corte.
     */
    public static function calcular(array $contratos, array $pagos = [], array $parametros = []): array
    {
        $parametros += ['modo' => 'SEMANAL', 'domingo_base' => self::DOMINGO, 'fecha_corte' => self::CORTE];
        $indices = range(0, 7);
        if ($parametros['modo'] === 'DIARIO') {
            $k = (int) self::fecha(self::DOMINGO)->diff(self::fecha($parametros['fecha_corte']))->days;
            $indices = [$k - 1, $k];
        }

        return (new EfectividadV12Calculator)->calculate(
            $parametros,
            self::fotos($contratos, $pagos, $indices),
            self::ingresos($pagos),
            self::ingresos($pagos),
            true,
        );
    }

    /** Fila de promotor del resultado. */
    public static function filaDe(array $resultado, string $promotor): ?array
    {
        foreach ($resultado['filas'] as $f) {
            if ($f['tipo'] === 'promotor' && $f['promotor'] === $promotor) {
                return $f;
            }
        }

        return null;
    }

    public static function granTotal(array $resultado): ?array
    {
        foreach ($resultado['filas'] as $f) {
            if ($f['tipo'] === 'gran_total') {
                return $f;
            }
        }

        return null;
    }

    public static function conciliacionDe(array $resultado, string $contrato): ?array
    {
        foreach ($resultado['conciliacion'] as $r) {
            if ($r['contrato'] === $contrato) {
                return $r;
            }
        }

        return null;
    }

    /** @return list<string> códigos de incidencia del contrato */
    public static function incidenciasDe(array $resultado, string $contrato): array
    {
        return array_values(array_map(fn ($i) => $i['codigo'], array_filter($resultado['incidencias'], fn ($i) => $i['contrato'] === $contrato)));
    }

    private static function fecha(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd, new DateTimeZone('UTC'));
    }

    private static function sumarDias(string $ymd, int $dias): string
    {
        return self::fecha($ymd)->modify(($dias >= 0 ? '+' : '').$dias.' day')->format('Y-m-d');
    }
}

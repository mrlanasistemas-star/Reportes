<?php

namespace App\Services\Efectividad\V4;

use App\Models\EfectividadCobranzaRun;
use App\Models\EfectividadCobranzaRunIncident;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de incidencias de cálculo (`_AUDITORIA_EFECTIVIDAD`) de una ejecución
 * V4. Los códigos y textos los produce el motor exactamente como la macro; aquí
 * se agrega el tratamiento financiero y la acción recomendada, y se persisten.
 * Ninguna incidencia se "corrige" forzando el movimiento al cálculo.
 */
final class EfectividadV4Incidents
{
    /** código => [etiqueta, tratamiento financiero, acción recomendada] */
    public const CATALOGO = [
        'NO-CARTERA' => ['Contrato sin respaldo en fotografías', 'Pago excluido del cálculo', 'Validar el contrato: si pertenece a la sucursal, carga la fotografía donde aparece; si es de otra sucursal, no requiere acción.'],
        'CUOTA' => ['Pago sin cuota programada', 'Pago excluido del cálculo', 'Revisa en las fotografías la fecha de primer pago, periodicidad y cuota del contrato.'],
        'DUP' => ['Movimiento duplicado', 'Contado una sola vez', 'Revisa el archivo de Ingresos: el mismo movimiento aparece más de una vez.'],
        'GRP' => ['Ingreso sin responsable de ruta', 'Pago excluido del cálculo', 'Verifica Oficina y Promotor del movimiento en Ingresos Cobranza.'],
        'FOTO-DUP' => ['Contrato repetido en fotografía', 'Fila repetida omitida (se usó la primera)', 'Revisa el archivo de cartera de ese día.'],
        'PROM' => ['Promotor no identificado', 'Contrato sin grupo en esa fotografía', 'Verifica la columna Nombre promotor de la fotografía.'],
        'FECHA' => ['Contrato sin fecha de primer pago', 'Sin cuotas Sano programadas', 'Verifica Fecha primer pago / Fecha siguiente pago del contrato.'],
    ];

    public static function etiqueta(string $codigo): string
    {
        return self::CATALOGO[$codigo][0] ?? $codigo;
    }

    public static function tratamiento(string $codigo): string
    {
        return self::CATALOGO[$codigo][1] ?? 'Revisar';
    }

    public static function accion(string $codigo): string
    {
        return self::CATALOGO[$codigo][2] ?? 'Revisar el movimiento.';
    }

    /** Reemplaza (idempotente ante reintentos) la bitácora de la ejecución. */
    public static function persist(EfectividadCobranzaRun $run, array $incidencias): array
    {
        DB::table('efectividad_cobranza_run_incidents')->where('efectividad_cobranza_run_id', $run->id)->delete();
        $now = now();
        $buffer = [];
        foreach (array_values($incidencias) as $pos => $i) {
            $buffer[] = [
                'efectividad_cobranza_run_id' => $run->id,
                'position' => $pos + 1,
                'code' => mb_substr((string) $i['codigo'], 0, 20),
                'incident_key' => (string) $i['id'],
                'contract' => $i['contrato'] !== null ? mb_substr((string) $i['contrato'], 0, 60) : null,
                'reference' => $i['referencia'] !== null ? mb_substr((string) $i['referencia'], 0, 120) : null,
                'occurred_on' => $i['fecha'] ?? null,
                'amount' => $i['importe'] ?? null,
                'description' => (string) $i['descripcion'],
                'treatment' => self::tratamiento((string) $i['codigo']),
                'created_at' => $now,
            ];
            if (count($buffer) >= 500) {
                EfectividadCobranzaRunIncident::query()->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            EfectividadCobranzaRunIncident::query()->insert($buffer);
        }

        return self::resumen($incidencias);
    }

    /** @return array<string, array{codigo:string, etiqueta:string, cantidad:int, importe:float, tratamiento:string}> */
    public static function resumen(array $incidencias): array
    {
        $out = [];
        foreach ($incidencias as $i) {
            $c = (string) $i['codigo'];
            $out[$c] ??= ['codigo' => $c, 'etiqueta' => self::etiqueta($c), 'cantidad' => 0, 'importe' => 0.0, 'tratamiento' => self::tratamiento($c)];
            $out[$c]['cantidad']++;
            $out[$c]['importe'] += (float) ($i['importe'] ?? 0);
        }

        return $out;
    }
}

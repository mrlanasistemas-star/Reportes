<?php

namespace App\Services\EstadoResultados\Sources;

/**
 * Lista ÚNICA de fuentes del periodo (función pura: no consulta la BD) para la pantalla de
 * Fuentes, el aviso de la barra superior, los motivos de «Preliminar» y la aprobación.
 *
 * Cada fuente dice, en palabras de negocio: si está lista, qué falta EXACTAMENTE, dónde se
 * resuelve (aquí en el ER o en Carga de archivos) y qué cifra afecta.
 *
 *   state:  lista | falta | con_problemas
 *   where:  er (se sube aquí) | carga (Carga de archivos) | er_tab (otra pestaña del ER)
 *   action: {kind: upload|carga|tab|process, label, slot?, tab?}
 *   reason: frase completa para listas de motivos («Falta … → qué hacer»)
 */
final class SourceChecklist
{
    public const STATE_LABELS = [
        'lista' => 'Lista',
        'falta' => 'Falta',
        'con_problemas' => 'Con problemas',
    ];

    private const AFFECTS = [
        'radiografia' => 'Ingresos, OPEX y nómina de sucursales (la base del EBITDA).',
        'administrativa' => 'Pagos de Corporativo (gastos y nómina corporativa, gastos financieros) y su cruce contra el ERP.',
        'excedentes' => 'Excedentes y traspasos de sucursales; ingresos y gastos que no están en el ERP.',
        'noi_corporativo' => 'Nómina corporativa: sueldos y prestaciones del personal de Corporativo.',
        'ajustes' => 'Depreciación, intereses e ISR: EBIT, EBT y utilidad neta.',
    ];

    /**
     * @param  array<string,mixed>  $sources  bloque `sources` ya armado (sin checklist)
     * @param  array<string,array>  $diagnosis  UploadModuleInspector::inspect()
     * @param  list<array<string,mixed>>  $adjustments
     * @return array{items:list<array<string,mixed>>, summary:array<string,int>}
     */
    public static function build(string $periodLabel, array $sources, array $diagnosis, array $adjustments, bool $corpRequired = false): array
    {
        $p = $periodLabel;
        $items = [];

        // 1 · Radiografía (Carga de archivos)
        $ok = ($sources['radiografia']['status'] ?? null) === 'procesado';
        $items[] = self::item('radiografia', 'Radiografía del periodo', 'carga', $ok ? 'lista' : 'con_problemas', true,
            $ok ? 'Radiografía de '.$p.' generada: los resultados de sucursales usan sus mismas reglas.'
                : 'La Radiografía de '.$p.' no está generada. Las cifras de sucursales se calculan al momento con las mismas reglas, pero para aprobar se necesita la Radiografía generada.',
            $ok ? null : 'En Carga de archivos → '.$p.', termina «Cargar registros» y después «Generar reporte».',
            self::AFFECTS['radiografia'], ['kind' => 'carga', 'label' => 'Ir a Carga de archivos'], $p);

        // 2 · ERP de gastos y 3 · NOI de sucursales (Carga de archivos, sólo lectura)
        foreach (['erp' => 'el', 'nomina' => 'la'] as $key => $article) {
            $src = (array) ($sources[$key] ?? []);
            $diag = $diagnosis[$key] ?? null;
            $count = (int) ($src['count'] ?? 0);
            $label = $key === 'erp' ? 'ERP de gastos' : 'Nómina NOI de sucursales';
            $affects = $diag['affects'] ?? '';
            if ($count > 0 && ($src['status'] ?? null) === 'con_incidencias') {
                $items[] = self::item($key, $label, 'carga', 'con_problemas', true,
                    'El ERP de '.$p.' cambió después del último procesamiento de la conciliación.',
                    'Pulsa «Procesar conciliación» para volver a cruzar los pagos contra el ERP actualizado.',
                    $affects, ['kind' => 'process', 'label' => 'Procesar conciliación'], $p);

                continue;
            }
            if ($count > 0) {
                $state = ($diag['state'] ?? 'lista') === 'con_problemas' ? 'con_problemas' : 'lista';
                $items[] = self::item($key, $label, 'carga', $state, true, $diag['message'] ?? ($src['detail'] ?? ''), $diag['fix'] ?? null,
                    $affects, ['kind' => 'carga', 'label' => $state === 'lista' ? 'Ver en Carga de archivos' : 'Ir a Carga de archivos'], $p);

                continue;
            }
            $state = ($diag['state'] ?? 'falta') === 'con_problemas' ? 'con_problemas' : 'falta';
            $message = $diag['message'] ?? 'No hay registros de '.$label.' para '.$p.'.';
            $fix = $diag['fix'] ?? 'Súbelo en Carga de archivos → '.$p.' y pulsa «Cargar registros».';
            $reasonCode = $diag['reason'] ?? 'sin_archivo';
            $items[] = self::item($key, $label, 'carga', $state, true, $message, $fix, $affects,
                ['kind' => 'carga', 'label' => 'Ir a Carga de archivos'], $p,
                // Sin registros = la fuente FALTA para el ER (aunque el archivo exista): así lo dice el motivo.
                'Falta '.$article.' '.($key === 'erp' ? 'ERP de gastos' : 'nómina (NOI) de sucursales').' de '.$p.($reasonCode === 'sin_archivo' ? '' : ': '.self::lcfirst($message)).' → '.$fix,
                $diag);
        }

        // 4 · Estados de cuenta (se suben AQUÍ)
        foreach ((array) ($sources['cuentas'] ?? []) as $c) {
            $label = (string) ($c['label'] ?? $c['key']);
            $slot = (string) ($c['key'] ?? '');
            $affects = self::AFFECTS[$slot] ?? self::AFFECTS['administrativa'];
            $upload = $c['upload'] ?? null;
            $status = $c['status'] ?? 'pendiente';
            $pending = (int) ($c['pending_review'] ?? 0);
            [$state, $message, $fix, $action] = match (true) {
                $status === 'pendiente' => ['falta', 'Falta el estado de cuenta «'.$label.'» de '.$p.'.',
                    'Súbelo aquí, en la tarjeta «'.$label.'» (Excel del banco).', ['kind' => 'upload', 'label' => 'Subir estado de cuenta', 'slot' => $slot]],
                ($upload['control_ok'] ?? null) === false => ['con_problemas', 'Los totales del archivo «'.($upload['name'] ?? '').'» no cuadran con el renglón de control del banco («TOTAL EXCEL»).',
                    'Revisa el archivo del banco y vuelve a subirlo en la tarjeta «'.$label.'».', ['kind' => 'upload', 'label' => 'Sustituir archivo', 'slot' => $slot]],
                $status === 'cargado' => ['con_problemas', 'El estado de cuenta «'.$label.'» está cargado pero todavía no se procesa.',
                    'Pulsa «Procesar conciliación».', ['kind' => 'process', 'label' => 'Procesar conciliación']],
                $pending > 0 => ['con_problemas', $pending.' movimiento(s) de «'.$label.'» requieren revisión: no suman a ningún resultado mientras tanto.',
                    'Clasifícalos en la pestaña «Por revisar».', ['kind' => 'tab', 'label' => 'Revisar movimientos', 'tab' => 'revision']],
                ($upload['blocking_warnings'] ?? []) !== [] => ['con_problemas', 'El archivo «'.($upload['name'] ?? '').'» tiene avisos que impiden aprobar.',
                    'Revisa los avisos de la tarjeta «'.$label.'» y, si hace falta, sustituye el archivo.', ['kind' => 'upload', 'label' => 'Sustituir archivo', 'slot' => $slot]],
                default => ['lista', ($upload['movements'] ?? 0).' movimientos de «'.($upload['name'] ?? '').'», procesados.', null, ['kind' => 'upload', 'label' => 'Sustituir archivo', 'slot' => $slot]],
            };
            $items[] = self::item($slot, 'Estado de cuenta: '.$label, 'er', $state, true, $message, $fix, $affects, $action, $p,
                $state === 'falta' ? 'Falta el estado de cuenta «'.$label.'» de '.$p.' → '.$fix : null);
        }

        // 5 · NOI de corporativo (se sube AQUÍ)
        $corp = (array) ($sources['noi_corporativo'] ?? []);
        $cu = $corp['upload'] ?? null;
        if ($cu) {
            $overlap = (int) ($corp['overlap']['count'] ?? 0);
            $items[] = self::item('noi_corporativo', 'NOI de corporativo', 'er', 'lista', $corpRequired,
                ($cu['employees'] ?? 0).' colaborador(es) de Corporativo, percepciones $'.number_format((float) ($cu['total_percepciones'] ?? 0), 2).' (archivo «'.($cu['name'] ?? '').'»).'
                    .($overlap > 0 ? ' '.$overlap.' de ellos ya están en el NOI de sucursales y no se suman otra vez.' : ''),
                null, self::AFFECTS['noi_corporativo'], ['kind' => 'upload', 'label' => 'Sustituir NOI', 'slot' => 'noi_corporativo'], $p);
        } else {
            $fromOther = (string) ($corp['other_sources_amount'] ?? '0.00');
            $items[] = self::item('noi_corporativo', 'NOI de corporativo', 'er', 'falta', $corpRequired,
                'No se ha subido el NOI de corporativo de '.$p.'. Sin él, la nómina corporativa sólo incluye lo que llega por ERP o bancos ($'.number_format((float) $fromOther, 2).').',
                'Súbelo aquí, en la tarjeta «NOI de corporativo» (mismo reporte de nómina del NOI que el de sucursales).',
                self::AFFECTS['noi_corporativo'], ['kind' => 'upload', 'label' => 'Subir NOI de corporativo', 'slot' => 'noi_corporativo'], $p,
                'Falta el NOI de corporativo de '.$p.' (la nómina corporativa puede estar incompleta) → Súbelo en Estado de Resultados → Fuentes, tarjeta «NOI de corporativo».');
        }

        // 6 · Ajustes contables (pestaña Ajustes)
        $noInfo = array_values(array_filter($adjustments, fn ($a) => ($a['state'] ?? null) === 'sin_informacion' && ($a['affects'] ?? true)));
        $labels = implode(', ', array_map(fn ($a) => (string) ($a['label'] ?? $a['key'] ?? ''), $noInfo));
        $items[] = self::item('ajustes', 'Ajustes contables', 'er_tab', $noInfo === [] ? 'lista' : 'falta', true,
            $noInfo === [] ? 'Capturados o confirmados en $0.' : 'Sin información: '.$labels.'. No se asumen $0.',
            $noInfo === [] ? null : 'Captura el importe o confirma $0 en la pestaña «Ajustes».',
            self::AFFECTS['ajustes'], ['kind' => 'tab', 'label' => 'Capturar ajustes', 'tab' => 'ajustes'], $p);

        $summary = ['total' => count($items), 'lista' => 0, 'falta' => 0, 'con_problemas' => 0, 'pendientes_obligatorias' => 0, 'pendientes' => 0];
        foreach ($items as $i) {
            $summary[$i['state']]++;
            if ($i['state'] !== 'lista') {
                $summary['pendientes']++;
                if ($i['required']) {
                    $summary['pendientes_obligatorias']++;
                }
            }
        }

        return ['items' => $items, 'summary' => $summary];
    }

    private static function item(string $key, string $label, string $where, string $state, bool $required, string $message, ?string $fix, string $affects, array $action, string $period, ?string $reason = null, ?array $diagnosis = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'where' => $where,
            'where_label' => match ($where) {
                'er' => 'Se sube aquí (Estado de Resultados → Fuentes)',
                'carga' => 'Se carga en Carga de archivos',
                default => 'Se captura en el Estado de Resultados',
            },
            'state' => $state,
            'state_label' => self::STATE_LABELS[$state].($state !== 'lista' && ! $required ? ' (recomendada)' : ''),
            'required' => $required,
            'message' => $message,
            'fix' => $fix,
            'affects' => $affects,
            'action' => $action,
            'reason' => $state === 'lista' ? null : ($reason ?? $label.' de '.$period.': '.self::lcfirst($message).($fix ? ' → '.$fix : '')),
            'diagnosis' => $diagnosis ? array_intersect_key($diagnosis, array_flip(['reason', 'uploads', 'fact_rows', 'run', 'elsewhere', 'module_label'])) : null,
        ];
    }

    private static function lcfirst(string $s): string
    {
        // Siglas (ERP, NOI…) se respetan: sólo se baja la inicial de una palabra normal.
        if ($s === '' || preg_match('/^\p{Lu}{2}/u', $s) || preg_match('/^\d/', $s)) {
            return $s;
        }

        return mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }
}

@php
/**
 * PDF de Efectividad de Cobranza — reutiliza el sistema visual compartido de
 * PDFs (x-pdf.*, ver resources/views/components/pdf/layout.blade.php), la
 * misma base que Radiografía. Nunca formato nuevo ni colores ajenos al resto
 * del sistema.
 *
 * $run        => App\Models\EfectividadCobranzaRun (con period/branch/createdBy cargados)
 * $rows       => filas de resultado (misma fuente que el Excel — result_rows)
 * $granTotal  => fila GRAN TOTAL (si existe)
 * $engineLabel, $reportLabel, $isPeriodica => ya resueltos por el presenter
 */
$fmt0 = fn ($v) => '$'.number_format((float) $v, 0);
$fmtp = fn ($v) => number_format(((float) $v) * 100, 1).'%';

$granTotal = collect($rows)->firstWhere('promotor', 'GRAN TOTAL');
$detailRows = collect($rows)->reject(fn ($r) => (bool) ($r['es_total'] ?? false))->values();

$efectividadPct = (float) ($granTotal['pct_total'] ?? 0);
$tone = $efectividadPct >= 0.85 ? 'pos' : ($efectividadPct < 0.60 ? 'negv' : '');
$panelTone = $efectividadPct >= 0.85 ? 'ok' : ($efectividadPct < 0.60 ? 'alert' : 'warn');
@endphp
<x-pdf.layout :title="'Efectividad de Cobranza — '.$run->period?->label">

<x-pdf.header
    title="Efectividad de Cobranza"
    :subtitle="$engineLabel"
    :meta="array_filter([
        'Periodo' => $run->period?->label,
        'Sucursal' => $run->branch?->name ?? 'Todas las sucursales',
        'Ejecución' => '#'.$run->id,
        'Generado' => $run->finished_at?->copy()->setTimezone('America/Mexico_City')->format('d/m/Y H:i'),
    ])"
/>

@if($isPeriodica)
<div class="pdf-summary-panel tone-warn pdf-avoid" style="margin-bottom:10px;">
    <h4>Metodología: Aprobada / Periódica (sin fotografías diarias)</h4>
    <div style="font-size:7.6pt; color:#475569; line-height:1.5;">
        Este resultado usa el corte de <b>Saldos por Cliente</b> del cierre del periodo más el histórico completo de
        <b>Ingresos Cobranza</b> — la información que subes cada 4 semanas, sin fotografías diarias de cartera.
        Es una metodología distinta del motor V4 (que sí usa 8 fotografías diarias): <b>no es una paridad exacta</b>
        con V4, es el cálculo aprobado anterior aplicado a tu operación real.
    </div>
</div>
@endif

<div class="pdf-kpi-grid pdf-avoid">
    <x-pdf.kpi-card label="Sano por cobrar" :value="$fmt0($granTotal['sano_pc'] ?? 0)" />
    <x-pdf.kpi-card label="Sano cobrado" :value="$fmt0($granTotal['sano_cob'] ?? 0)" />
    <x-pdf.kpi-card label="Vigente c/atraso cobrado" :value="$fmt0($granTotal['atraso_cob'] ?? 0)" />
    <x-pdf.kpi-card label="Vencido cobrado" :value="$fmt0($granTotal['vencido_cob'] ?? 0)" />
    <x-pdf.kpi-card label="Cobranza total" :value="$fmt0($granTotal['cobranza_total'] ?? 0)" />
    <x-pdf.kpi-card label="Efectividad" :value="$fmtp($efectividadPct)" :tone="$tone" />
    <x-pdf.kpi-card label="Mora" :value="$fmt0($granTotal['mora'] ?? 0)" :tone="($granTotal['mora'] ?? 0) > 0 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Promotores evaluados" :value="number_format($detailRows->count())" />
</div>

<x-pdf.summary-panel title="Resumen" :tone="$panelTone">
    <div style="font-size:7.8pt; line-height:1.55; color:#334155;">
        De <b>{{ $fmt0($granTotal['sano_pc'] ?? 0) }}</b> programado como sano por cobrar, se cobró un total de
        <b>{{ $fmt0($granTotal['cobranza_total'] ?? 0) }}</b> ({{ $fmtp($efectividadPct) }} de efectividad),
        dejando una mora de <b>{{ $fmt0($granTotal['mora'] ?? 0) }}</b>.
        @if(($run->incidents_count ?? 0) > 0)
            Se registraron <b>{{ number_format($run->incidents_count) }}</b> incidencia(s) de cálculo — ver detalle en el módulo.
        @endif
    </div>
</x-pdf.summary-panel>

<x-pdf.section-title title="Resultado por oficina y promotor" subtitle="Mismas columnas que el Excel exportado (A-M)" />

@if($detailRows->isEmpty())
    <x-pdf.empty-state message="No hay movimientos para este periodo/sucursal." />
@else
    <x-pdf.table>
        <x-slot:head>
            <tr>
                <th>Promotor</th>
                @if(collect($rows)->contains(fn ($r) => !empty($r['oficina'])))
                <th>Oficina</th>
                @endif
                <th class="r">Sano x/c</th>
                <th class="r">Sano cobrado</th>
                <th class="r">% Sano</th>
                <th class="r">Atraso x/c</th>
                <th class="r">Atraso cobrado</th>
                <th class="r">Vencido x/c</th>
                <th class="r">Vencido cobrado</th>
                <th class="r">Cobranza total</th>
                <th class="r">% Total</th>
                <th class="r">Mora</th>
            </tr>
        </x-slot:head>

        @foreach($rows as $r)
        <tr @if($r['es_total'] ?? false) style="font-weight:bold;background:#eef2f7;" @endif>
            <td>{{ $r['promotor'] }}</td>
            @if(collect($rows)->contains(fn ($x) => !empty($x['oficina'])))
            <td>{{ $r['oficina'] ?? '' }}</td>
            @endif
            <td class="r">{{ $fmt0($r['sano_pc'] ?? 0) }}</td>
            <td class="r">{{ $fmt0($r['sano_cob'] ?? 0) }}</td>
            <td class="r">{{ $fmtp($r['sano_pct'] ?? 0) }}</td>
            <td class="r">{{ $fmt0($r['atraso_pc'] ?? 0) }}</td>
            <td class="r">{{ $fmt0($r['atraso_cob'] ?? 0) }}</td>
            <td class="r">{{ $fmt0($r['vencido_pc'] ?? 0) }}</td>
            <td class="r">{{ $fmt0($r['vencido_cob'] ?? 0) }}</td>
            <td class="r b">{{ $fmt0($r['cobranza_total'] ?? 0) }}</td>
            <td class="r b">{{ $fmtp($r['pct_total'] ?? 0) }}</td>
            <td class="r" @if(($r['mora'] ?? 0) > 0) style="color:#b91c1c;" @endif>{{ $fmt0($r['mora'] ?? 0) }}</td>
        </tr>
        @endforeach
    </x-pdf.table>
@endif

<p class="pdf-footnote">
    Motor: {{ $run->engine_version }} · Ejecución #{{ $run->id }} · Generado por Reportes MR Lana — este PDF es un
    resumen; el Excel descargable conserva el detalle completo y la bitácora de auditoría.
</p>

</x-pdf.layout>

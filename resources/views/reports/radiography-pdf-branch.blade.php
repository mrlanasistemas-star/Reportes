@php
$fmt  = fn($v) => number_format((float)$v, 2);
$fmt0 = fn($v) => '$' . number_format((float)$v, 0);
$fmt2 = fn($v) => '$' . number_format((float)$v, 2);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$catColors = $ebitdaCategoriaColors ?? ['bg' => 'FFF1F5F9', 'fg' => 'FF475569'];
$catBg = '#' . substr($catColors['bg'], 2);
$catFg = '#' . substr($catColors['fg'], 2);
$sucursal = $branchRow['sucursal'] ?? 'Sucursal';

$percepciones  = $payrollDetail['percepciones'] ?? [];
$deducciones   = $payrollDetail['deducciones'] ?? [];

// Reconciliación defensiva: si el desglose no suma contra el KPI del resumen, no se
// muestra un desglose que lo contradiga (mismo criterio que el PDF de gestor).
$recSum = is_array($recoveryComponents ?? null) ? round(array_sum($recoveryComponents), 2) : null;
$recReconciles = $recSum === null || abs($recSum - round($rec, 2)) <= 0.01;

$placSum = !empty($placementsByProduct) ? round(array_sum(array_column($placementsByProduct, 'colocacion')), 2) : null;
$placReconciles = $placSum === null || abs($placSum - round($coloc, 2)) <= 0.01;

$recoveryComponentLabels = [
    'capital_recuperado' => 'Capital recuperado', 'interes_recuperado' => 'Intereses',
    'impuesto_recuperado' => 'Impuestos', 'charges' => 'Moratorios / Multas',
    'cargos_adicionales' => 'Cargos adicionales', 'cargos_inicio' => 'Cargos al inicio',
    'comision_apertura' => 'Comisión por apertura', 'excedente_recuperado' => 'Excedentes recuperados',
    'seguro_crece_reconocido' => 'Seguro CRECE reconocido (30%)', 'otros_recuperacion' => 'Otros',
];
@endphp
<x-pdf.layout :title="'Radiografía ' . $sucursal . ' — ' . $period->label">

<x-pdf.header
    title="Radiografía financiera — sucursal"
    :subtitle="strtoupper($sucursal)"
    :meta="[
        'Periodo' => strtoupper($period->label),
        'Categoría EBITDA' => $ebitdaCategoria ?? 'MANTENIDO',
        'Generado' => $snap['generated_at'] ?? now()->format('d/m/Y H:i'),
    ]"
/>

<x-pdf.section-title title="KPIs principales" />
<div class="pdf-kpi-grid pdf-avoid">
    <x-pdf.kpi-card label="Recuperación" :value="$fmt0($rec)" />
    <x-pdf.kpi-card label="Colocación" :value="$fmt0($coloc)" />
    <x-pdf.kpi-card label="Cartera" :value="$fmt0($cartera)" />
    <x-pdf.kpi-card label="Cartera vencida" :value="$fmt0($vencida)" :tone="$mora > 25 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Mora %" :value="$fmtp($mora)" :tone="$mora > 25 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Gastos operativos" :value="$fmt0($gastos)" />
    <x-pdf.kpi-card label="Nómina y Capital Humano" :value="$fmt0($nomina)" />
    <x-pdf.kpi-card label="EBITDA estimado" :value="$fmt0($ebitda)" :tone="$ebitda < 0 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Margen EBITDA" :value="$fmtp($margen ?? 0)" :tone="($margen ?? 0) < 0 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Percepciones" :value="$fmt0($payrollDetail['percepciones_total'] ?? 0)" />
    <x-pdf.kpi-card label="Deducciones" :value="$fmt0($payrollDetail['deducciones_total'] ?? 0)" />
    <x-pdf.kpi-card label="Operaciones" :value="$fmt0($ops)" />
</div>

<x-pdf.section-title title="Resumen ejecutivo" alt />
<x-pdf.table>
    <tr><td class="b">Recuperación total</td><td class="r">{{ $fmt2($rec) }}</td></tr>
    <tr><td class="b">Colocación total</td><td class="r">{{ $fmt2($coloc) }}</td></tr>
    <tr><td class="b">Cartera total</td><td class="r">{{ $fmt2($cartera) }}</td></tr>
    <tr><td class="b">Cartera vencida</td><td class="r">{{ $fmt2($vencida) }}</td></tr>
    <tr><td class="b">Índice de mora</td><td class="r">{{ $fmtp($mora) }}</td></tr>
    <tr><td class="b">Gastos operativos</td><td class="r">{{ $fmt2($gastos) }}</td></tr>
    <tr><td class="b">Nómina y Capital Humano</td><td class="r">{{ $fmt2($nomina) }}</td></tr>
    <tr><td class="b">Ingreso base EBITDA</td><td class="r">{{ $fmt2($ingresoBase) }}</td></tr>
    <tr><td class="b">EBITDA</td><td class="r b">{{ $fmt2($ebitda) }}</td></tr>
    <tr><td class="b">Margen EBITDA</td><td class="r b">{{ $fmtp($margen ?? 0) }}</td></tr>
    <tr><td class="b">Excedente enviado a corporativo</td><td class="r">{{ $fmt2($excedente) }}</td></tr>
    <tr><td class="b">Préstamos intersucursales (fondea)</td><td class="r">{{ $fmt2($fondeo) }}</td></tr>
</x-pdf.table>
<div class="pdf-note">Excedente y fondeo son movimientos de liquidez entre sucursales/corporativo — no afectan recuperación, OPEX, nómina ni EBITDA.</div>

<div class="pdf-charts-row">
    @if(!empty($chartRecuperacionVsColocacion))
    <div class="pdf-svg-chart">{!! $chartRecuperacionVsColocacion !!}</div>
    @endif
    @if(!empty($chartEbitda))
    <div class="pdf-svg-chart">{!! $chartEbitda !!}</div>
    @endif
</div>

<x-pdf.section-title title="Recuperación" subtitle="Componentes por sucursal" />
<div class="pdf-note" style="margin-top:0;">Ingresos por cobranza atribuibles directamente a esta sucursal en el periodo.</div>

@if($recSum !== null)
    @if($recReconciles)
    <x-pdf.table>
        <x-slot:head><tr><th>Componente</th><th class="r">Monto</th></tr></x-slot:head>
        @foreach($recoveryComponents as $key => $val)
        @if($val != 0)
        <tr><td>{{ $recoveryComponentLabels[$key] ?? ucfirst(str_replace('_',' ',$key)) }}</td><td class="r">{{ $fmt2($val) }}</td></tr>
        @endif
        @endforeach
        <x-slot:foot><tr><td>Total recuperación</td><td class="r">{{ $fmt2($recSum) }}</td></tr></x-slot:foot>
    </x-pdf.table>
    @else
    <x-pdf.empty-state message="Desglose no disponible: no reconcilia contra la recuperación total del periodo." />
    @endif
@else
<x-pdf.empty-state message="Sin movimientos de recuperación para esta sucursal en el periodo." />
@endif

<x-pdf.empty-state message="No aplica: el desglose de recuperación por producto en este snapshot está agregado a nivel empresa completa — no filtrable por sucursal todavía (mismo límite ya presente en la vista Web de esta sucursal)." />

<x-pdf.section-title title="Colocación" subtitle="Otorgamiento por producto" alt />
@if(!empty($placementsByProduct))
    @if($placReconciles)
    <x-pdf.table>
        <x-slot:head><tr><th>Producto</th><th class="c">Operaciones</th><th class="r">Colocación</th><th class="r">% del total</th></tr></x-slot:head>
        @foreach($placementsByProduct as $pp)
        <tr>
            <td>{{ $pp['producto'] ?? '—' }}</td>
            <td class="c">{{ number_format($pp['operaciones'] ?? 0) }}</td>
            <td class="r">{{ $fmt2($pp['colocacion'] ?? 0) }}</td>
            <td class="r">{{ $coloc > 0 ? $fmtp((($pp['colocacion'] ?? 0) / $coloc) * 100) : '—' }}</td>
        </tr>
        @endforeach
        <x-slot:foot><tr><td>Total</td><td class="c">{{ number_format($ops) }}</td><td class="r">{{ $fmt2($coloc) }}</td><td class="r">100.00%</td></tr></x-slot:foot>
    </x-pdf.table>
    @if(!empty($chartColocacionPorProducto))
    <div class="pdf-svg-chart">{!! $chartColocacionPorProducto !!}</div>
    @endif
    @else
    <x-pdf.empty-state message="Desglose por producto no disponible: no reconcilia contra la colocación total." />
    @endif
@else
<x-pdf.empty-state message="Sin colocación registrada para esta sucursal en el periodo." />
@endif

<x-pdf.section-title title="Cartera y mora" subtitle="Distribución por antigüedad" />
<div class="pdf-kpi-grid pdf-avoid">
    <x-pdf.kpi-card label="Cartera total" :value="$fmt0($cartera)" />
    <x-pdf.kpi-card label="Cartera sana" :value="$fmt0(max(0, $cartera - $vencida))" />
    <x-pdf.kpi-card label="Cartera vencida" :value="$fmt0($vencida)" tone="negv" />
    <x-pdf.kpi-card label="Mora %" :value="$fmtp($mora)" :tone="$mora > 25 ? 'negv' : ''" />
</div>

@if(!empty($moraBuckets))
@php
    $moraLabels = [
        'al_corriente' => 'Al corriente', 'mora_1_30' => 'Mora 1-30', 'mora_31_60' => 'Mora 31-60',
        'mora_61_90' => 'Mora 61-90', 'mora_91_120' => 'Mora 91-120', 'mora_120_plus' => 'Mora 120+',
    ];
@endphp
<x-pdf.table>
    <x-slot:head><tr><th>Bucket de antigüedad</th><th class="c">Contratos</th><th class="r">Monto</th><th class="r">% de cartera</th></tr></x-slot:head>
    @foreach($moraBuckets as $bucketKey => $b)
    <tr>
        <td>{{ $b['label'] ?? ($moraLabels[$bucketKey] ?? $bucketKey) }}</td>
        <td class="c">{{ number_format($b['contratos'] ?? 0) }}</td>
        <td class="r">{{ $fmt2($b['monto'] ?? 0) }}</td>
        <td class="r">{{ $cartera > 0 ? $fmtp((($b['monto'] ?? 0) / $cartera) * 100) : '—' }}</td>
    </tr>
    @endforeach
</x-pdf.table>
<div class="pdf-charts-row">
    @if(!empty($chartMoraPorBucket))
    <div class="pdf-svg-chart">{!! $chartMoraPorBucket !!}</div>
    @endif
    @if(!empty($chartCarteraSanaVsVencida))
    <div class="pdf-svg-chart">{!! $chartCarteraSanaVsVencida !!}</div>
    @endif
</div>
@else
<x-pdf.empty-state message="Sin cartera registrada para esta sucursal en el periodo." />
@endif

<x-pdf.section-title title="Nómina y Capital Humano" subtitle="Percepciones y deducciones" />
@if($percepciones || $deducciones)
<x-pdf.table>
    <x-slot:head><tr><th>Concepto</th><th class="c">Tipo</th><th class="r">Monto</th></tr></x-slot:head>
    @foreach($percepciones as $p)
    <tr><td>{{ $p['concepto'] }}</td><td class="c">Percepción</td><td class="r">{{ $fmt2($p['monto']) }}</td></tr>
    @endforeach
    @foreach($deducciones as $d)
    <tr><td>{{ $d['concepto'] }}</td><td class="c">Deducción</td><td class="r">{{ $fmt2($d['monto']) }}</td></tr>
    @endforeach
    <x-slot:foot>
        <tr><td colspan="2">Percepciones totales</td><td class="r">{{ $fmt2($payrollDetail['percepciones_total'] ?? 0) }}</td></tr>
        <tr><td colspan="2">Deducciones totales</td><td class="r">{{ $fmt2($payrollDetail['deducciones_total'] ?? 0) }}</td></tr>
    </x-slot:foot>
</x-pdf.table>
<div class="pdf-note">Este desglose es el detalle de nómina NOI por concepto de los colaboradores de esta sucursal — un subconjunto informativo del KPI "Nómina y Capital Humano" (que además incluye IMSS patronal y gastos de empleados vía nómina, ajenos a NOI). No están pensados para sumar el mismo total.</div>
@if(!empty($chartNominaComposicion))
<div class="pdf-svg-chart">{!! $chartNominaComposicion !!}</div>
@endif
@else
<x-pdf.empty-state message="Sin movimientos de nómina NOI atribuidos a los colaboradores de esta sucursal en el periodo." />
@endif

<x-pdf.section-title title="Gastos operativos" subtitle="Top conceptos" alt />
@if(!empty($gastosDetalle))
<x-pdf.table>
    <x-slot:head><tr><th>Concepto</th><th class="r">Monto</th></tr></x-slot:head>
    @foreach($gastosDetalle as $concepto => $monto)
    @if($monto > 0)
    <tr><td>{{ $concepto }}</td><td class="r">{{ $fmt2($monto) }}</td></tr>
    @endif
    @endforeach
    <x-slot:foot><tr><td>Total gastos operativos</td><td class="r">{{ $fmt2($gastos) }}</td></tr></x-slot:foot>
</x-pdf.table>
@else
<x-pdf.empty-state message="Sin gastos operativos registrados para esta sucursal en el periodo." />
@endif

@if(($extraAmount ?? 0) > 0)
<x-pdf.section-title title="Ajuste temporal" subtitle="Aplicado a cada colaborador de esta sucursal — no persistido" />
<x-pdf.table>
    <tr><td>{{ $extraNotes ?: 'Sin observación' }}</td><td class="r">{{ $fmt2($extraAmount) }}</td></tr>
</x-pdf.table>
@endif

<x-pdf.section-title title="EBITDA" subtitle="Ingreso base − Gastos totales" />
<x-pdf.table>
    <tr><td class="b">Ingreso base EBITDA</td><td class="r">{{ $fmt2($ingresoBase) }}</td></tr>
    <tr><td class="b">Gastos operativos</td><td class="r">{{ $fmt2($gastos) }}</td></tr>
    <tr><td class="b">Nómina y Capital Humano</td><td class="r">{{ $fmt2($nomina) }}</td></tr>
    <tr><td class="b">Gastos totales (Gastos + Nómina)</td><td class="r">{{ $fmt2($gastosTotales) }}</td></tr>
    <tr><td class="b">EBITDA</td><td class="r b">{{ $fmt2($ebitda) }}</td></tr>
    <tr><td class="b">Margen EBITDA</td><td class="r b">{{ $fmtp($margen ?? 0) }}</td></tr>
</x-pdf.table>
<div class="pdf-note">Fórmula: EBITDA = Ingreso base EBITDA - (Gastos operativos + Nómina y Capital Humano). El capital recuperado NO se cuenta como ingreso EBITDA.</div>

<div style="text-align:center; margin-top:12px;" class="pdf-avoid">
    <div class="pdf-note" style="margin-bottom:6px;">CATEGORÍA EBITDA DE LA SUCURSAL</div>
    <span class="pdf-big-badge" style="background:{{ $catBg }}; color:{{ $catFg }};">{{ $ebitdaCategoria ?? 'MANTENIDO' }}</span>
</div>

<x-pdf.section-title title="Efectividad de cobranza" alt />
@if(!empty($efectividad))
@php($ef = $efectividad['efectividad'] ?? null)
@if($ef)
<div style="text-align:center; margin-bottom:10px;" class="pdf-avoid">
    <div class="pdf-note" style="margin-bottom:4px;">EFECTIVIDAD DE COBRANZA</div>
    <span class="pdf-big-badge" style="background:{{ $ef['efectividad_pct'] === null ? '#e2e8f0' : ($ef['efectividad_pct'] >= 100 ? '#dcfce7' : ($ef['efectividad_pct'] >= 50 ? '#fef3c7' : '#fee2e2')) }}; color:{{ $ef['efectividad_pct'] === null ? '#475569' : ($ef['efectividad_pct'] >= 100 ? '#15803d' : ($ef['efectividad_pct'] >= 50 ? '#92400e' : '#b91c1c')) }};">
        {{ $ef['efectividad_pct'] !== null ? number_format($ef['efectividad_pct'], 1) . '%' : 'N/D' }}
    </span>
    <div style="font-size:7.3pt; color:#64748b; margin-top:4px;">
        Recuperado de mora ({{ $fmt2($ef['recuperado_de_mora']) }}) ÷ cartera en mora al cierre de
        {{ $ef['periodo_anterior_label'] ?? 'periodo anterior' }}
        ({{ $ef['cartera_mora_periodo_anterior'] !== null ? $fmt2($ef['cartera_mora_periodo_anterior']) : 'sin datos' }})
    </div>
</div>
@endif
<x-pdf.table>
    <x-slot:head><tr><th>Estatus</th><th class="c">Contratos</th><th class="r">Capital</th><th class="r">Interés</th><th class="r">Impuesto</th><th class="r">Moratorios</th><th class="r">Total</th></tr></x-slot:head>
    @foreach(['vigente' => 'Vigente', 'atrasado' => 'Atrasado', 'vencido' => 'Vencido'] as $key => $label)
    @php($e = $efectividad[$key] ?? null)
    @if($e)
    <tr>
        <td>{{ $label }}</td><td class="c">{{ number_format($e['contratos']) }}</td>
        <td class="r">{{ $fmt2($e['capital']) }}</td><td class="r">{{ $fmt2($e['interes']) }}</td>
        <td class="r">{{ $fmt2($e['impuesto']) }}</td><td class="r">{{ $fmt2($e['moratorios']) }}</td>
        <td class="r">{{ $fmt2($e['total']) }}</td>
    </tr>
    @endif
    @endforeach
    @if(!empty($efectividad['total']))
    <x-slot:foot>
        <tr>
            <td>Total</td><td class="c">{{ number_format($efectividad['total']['contratos']) }}</td>
            <td class="r">{{ $fmt2($efectividad['total']['capital']) }}</td><td class="r">{{ $fmt2($efectividad['total']['interes']) }}</td>
            <td class="r">{{ $fmt2($efectividad['total']['impuesto']) }}</td><td class="r">{{ $fmt2($efectividad['total']['moratorios']) }}</td>
            <td class="r">{{ $fmt2($efectividad['total']['total']) }}</td>
        </tr>
    </x-slot:foot>
    @endif
</x-pdf.table>
@if(!empty($chartEfectividad))
<div class="pdf-svg-chart">{!! $chartEfectividad !!}</div>
@endif
@else
<x-pdf.empty-state message="Sin datos de efectividad de cobranza para esta sucursal en el periodo." />
@endif

<x-pdf.section-title title="Notas y aclaraciones" alt />
<x-pdf.table>
    <tr><td style="width:22%;" class="b">No aplica</td><td>Recuperación por producto: en este snapshot ese desglose está agregado a nivel empresa completa, no filtrable por sucursal todavía (misma limitación ya presente en la vista Web de esta sucursal).</td></tr>
    <tr><td class="b">Sin datos</td><td>La sucursal no tuvo movimiento real en esa sección durante el periodo — es un cero real, no un error.</td></tr>
    <tr><td class="b">Excedente / Fondeo</td><td>Movimientos de liquidez entre sucursales y corporativo — no afectan recuperación, OPEX, nómina ni EBITDA; se muestran de forma informativa.</td></tr>
</x-pdf.table>

<div class="pdf-footnote">Este documento se generó a partir del mismo snapshot canónico que la vista Web y el Excel de este periodo y alcance — ningún valor se recalculó de forma independiente para este PDF.</div>

</x-pdf.layout>

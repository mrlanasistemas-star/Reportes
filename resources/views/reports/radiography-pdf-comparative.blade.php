<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Comparativo {{ $comparePeriod->label }} vs {{ $period->label }}</title>
<script>window.__PDF_READY__ = false;</script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }
@page { margin: 18mm 14mm 22mm 14mm; }

.brand { text-align: center; padding-bottom: 10px; margin-bottom: 14px; border-bottom: 2px solid #1f2937; }
.brand-mark { font-size: 19pt; font-weight: bold; letter-spacing: 1px; color: #106A59; }
.brand-sub  { font-size: 9.5pt; color: #334155; text-transform: uppercase; letter-spacing: 2px; margin-top: 2px; }
.brand-meta { font-size: 8pt; color: #64748b; margin-top: 8px; }
.brand-meta b { color: #1e293b; }

.section-bar { background: #1f2937; color: #fff; padding: 6px 10px; font-size: 9pt; font-weight: bold; letter-spacing: .3px; text-transform: uppercase; margin-top: 16px; margin-bottom: 8px; }
.avoid { page-break-inside: avoid; }
.page-break { page-break-after: always; }

table.tbl { width: 100%; border-collapse: collapse; font-size: 8pt; }
table.tbl thead th { background: #1f2937; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; border-bottom: 1px solid #1f2937; }
table.tbl tbody td { padding: 5px 6px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
table.tbl tbody tr:nth-child(even) td { background: #f8fafc; }
table.tbl .r { text-align: right; }
table.tbl .c { text-align: center; }
table.tbl .b { font-weight: bold; }
.pos { color: #15803d; }
.negv { color: #b91c1c; }

/* ── Tira de KPIs (retoma 21-sep-2026: PDF "como estado de resultados", no solo tabla) ── */
.kpi-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 4px; }
.kpi-card {
    flex: 1 1 22%; min-width: 110px; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 10px 12px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}
.kpi-card .kpi-label { font-size: 6.8pt; text-transform: uppercase; letter-spacing: .4px; color: #64748b; font-weight: bold; }
.kpi-card .kpi-value { font-size: 12pt; font-weight: bold; color: #106A59; margin-top: 2px; }
.kpi-card .kpi-delta { font-size: 7.3pt; font-weight: bold; margin-top: 2px; }

/* ── Gráficas (Chart.js, renderizado real vía Chrome headless — ver BrowsershotPdfRenderer) ── */
.charts-grid2 { display: flex; gap: 16px; margin-bottom: 16px; margin-top: 12px; }
.chart-card {
    flex: 1; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.chart-card h3 {
    font-size: 10pt; color: #1f2937; text-transform: uppercase; letter-spacing: .4px;
    margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0;
}
.chart-card canvas { width: 100% !important; }
</style>
</head>
<body>

@php
$fmt  = fn($v) => '$' . number_format((float)$v, 2);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$fmti = fn($v) => number_format((float)$v, 0);
$typeLabel = match($reportType) {
    'bimester_vs_bimester' => 'COMPARATIVO BIMESTRE',
    'quarter_vs_quarter'   => 'COMPARATIVO TRIMESTRE',
    default                => 'COMPARATIVO MES VS MES',
};
$headlineLabels = ['Recuperación', 'Colocación', 'EBITDA', 'Margen EBITDA', 'OPEX', 'Mora %', 'Valor cartera', 'Cartera vencida'];
$headlineRows = collect($headlineLabels)->map(fn($l) => collect($rows)->firstWhere('label', $l))->filter()->values();

// Mismo mapa que resources/js/lib/comparative-metrics.ts (DIRECTIONS) — bug real
// documentado ahí: este PDF coloreaba var_pct > 0 = verde / < 0 = rojo para TODA fila
// sin importar la métrica, incorrecto para Mora/OPEX/Cartera vencida/Rotación (donde
// BAJAR es la mejora). Retoma 21-sep-2026: se corrige aquí también — nunca cambia la
// cifra, solo el tono semántico.
$metricDirections = [
    'Recuperación' => 'increase_good', 'Ingreso base EBITDA' => 'increase_good',
    'Colocación' => 'increase_good', 'Valor cartera' => 'increase_good',
    'Cartera vencida' => 'decrease_good', 'Mora %' => 'decrease_good',
    'OPEX' => 'decrease_good', 'Gastos' => 'decrease_good', 'Gastos Totales' => 'decrease_good',
    'EBITDA' => 'increase_good', 'Margen EBITDA' => 'increase_good',
    'Préstamos activos (contratos)' => 'increase_good', 'Bajas del periodo' => 'decrease_good',
    'Rotación %' => 'decrease_good',
];
$toneClass = function (string $label, float $varPct) use ($metricDirections) {
    if ($varPct == 0.0) {
        return '';
    }
    $dir = $metricDirections[$label] ?? 'neutral';
    if ($dir === 'neutral') {
        return '';
    }
    $isIncrease = $varPct > 0;

    return ($dir === 'increase_good') === $isIncrease ? 'pos' : 'negv';
};
@endphp

<div class="brand">
    <div class="brand-mark">MR LANA</div>
    <div class="brand-sub">{{ $typeLabel }}</div>
    <div class="brand-meta">
        <b>Alcance:</b> {{ $scopeLabel }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Periodo comparado:</b> {{ $comparePeriod->label }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Periodo actual:</b> {{ $period->label }}
    </div>
    @if(!empty($compareComposite) || !empty($currentComposite))
    <div class="brand-meta" style="margin-top:2px;">
        @if(!empty($compareComposite)){{ $compareComposite['component_range'] }}@else{{ $comparePeriod->label }}@endif
        &nbsp;vs&nbsp;
        @if(!empty($currentComposite)){{ $currentComposite['component_range'] }}@else{{ $period->label }}@endif
    </div>
    @endif
</div>

<div class="section-bar">Resumen ejecutivo</div>
<div class="kpi-grid avoid">
    @php $rowFmt = fn($v, $f) => $f === 'percent' ? $fmtp($v) : ($f === 'integer' ? $fmti($v) : $fmt($v)); @endphp
    @foreach($headlineRows as $row)
    <div class="kpi-card">
        <div class="kpi-label">{{ $row['label'] }}</div>
        <div class="kpi-value">{{ $rowFmt($row['curr'], $row['fmt']) }}</div>
        <div class="kpi-delta {{ $toneClass($row['label'], $row['var_pct']) }}">
            {{ $row['var_pct'] >= 0 ? '↑ +' : '↓ ' }}{{ number_format($row['var_pct'], 2) }}% vs {{ strtoupper($comparePeriod->label) }}
        </div>
    </div>
    @endforeach
</div>

<div class="section-bar">Gráficas comparativas</div>
<div class="charts-grid2 avoid">
    <div class="chart-card">
        <h3>Indicadores financieros ({{ strtoupper($comparePeriod->label) }} vs {{ strtoupper($period->label) }})</h3>
        <canvas id="chartCurrency" height="200"></canvas>
    </div>
    <div class="chart-card">
        <h3>Indicadores porcentuales</h3>
        <canvas id="chartPercent" height="200"></canvas>
    </div>
</div>
<div class="charts-grid2 avoid">
    <div class="chart-card">
        <h3>Cartera sana vs vencida — {{ strtoupper($comparePeriod->label) }}</h3>
        <canvas id="chartCarteraPrev" height="200"></canvas>
    </div>
    <div class="chart-card">
        <h3>Cartera sana vs vencida — {{ strtoupper($period->label) }}</h3>
        <canvas id="chartCarteraCurr" height="200"></canvas>
    </div>
</div>

<div class="page-break"></div>

<div class="section-bar">Comparativo de métricas — detalle completo</div>
<table class="tbl avoid">
    <thead>
        <tr>
            <th>Métrica</th>
            <th class="r">{{ strtoupper($comparePeriod->label) }}</th>
            <th class="r">{{ strtoupper($period->label) }}</th>
            <th class="r">Diferencia</th>
            <th class="r">Var %</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
        <tr>
            <td class="b">{{ $row['label'] }}</td>
            <td class="r">{{ $rowFmt($row['prev'], $row['fmt']) }}</td>
            <td class="r">{{ $rowFmt($row['curr'], $row['fmt']) }}</td>
            <td class="r">{{ $row['fmt'] === 'percent' ? $rowFmt($row['diff'], $row['fmt']) : ($row['diff'] >= 0 ? '+' : '') . $rowFmt($row['diff'], $row['fmt']) }}</td>
            <td class="r {{ $toneClass($row['label'], $row['var_pct']) }}">{{ $row['var_pct'] >= 0 ? '+' : '' }}{{ number_format($row['var_pct'], 2) }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>

<script>{!! $chartJsInline !!}</script>
<script>
Chart.defaults.font.family = "'Segoe UI', Helvetica, Arial, sans-serif";
Chart.defaults.font.size = 10;
Chart.defaults.animation = false;

new Chart(document.getElementById('chartCurrency'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($chartLabelsCurrency) !!},
        datasets: [
            { label: {!! json_encode($comparePeriod->label) !!}, data: {!! json_encode($chartPrevCurrency) !!}, backgroundColor: '#94A3B8', borderRadius: 4 },
            { label: {!! json_encode($period->label) !!}, data: {!! json_encode($chartCurrCurrency) !!}, backgroundColor: '#106A59', borderRadius: 4 },
        ],
    },
    options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } },
});

new Chart(document.getElementById('chartPercent'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($chartLabelsPercent) !!},
        datasets: [
            { label: {!! json_encode($comparePeriod->label) !!}, data: {!! json_encode($chartPrevPercent) !!}, backgroundColor: '#94A3B8', borderRadius: 4 },
            { label: {!! json_encode($period->label) !!}, data: {!! json_encode($chartCurrPercent) !!}, backgroundColor: '#5B9BD5', borderRadius: 4 },
        ],
    },
    options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } },
});

new Chart(document.getElementById('chartCarteraPrev'), {
    type: 'doughnut',
    data: { labels: ['Sana', 'Vencida'], datasets: [{ data: {!! json_encode($carteraDonutPrev) !!}, backgroundColor: ['#1DC1A2', '#EF4444'] }] },
    options: { plugins: { legend: { position: 'bottom' } } },
});

new Chart(document.getElementById('chartCarteraCurr'), {
    type: 'doughnut',
    data: { labels: ['Sana', 'Vencida'], datasets: [{ data: {!! json_encode($carteraDonutCurr) !!}, backgroundColor: ['#1DC1A2', '#EF4444'] }] },
    options: { plugins: { legend: { position: 'bottom' } } },
});

window.__PDF_READY__ = true;
</script>
</body>
</html>

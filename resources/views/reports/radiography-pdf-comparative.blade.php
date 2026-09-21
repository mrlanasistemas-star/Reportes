@php
use App\Services\Radiography\RadiographyMetricToneHelper as Tone;

$fmt  = fn($v) => '$' . number_format((float)$v, 2);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$fmti = fn($v) => number_format((float)$v, 0);
$rowFmt = fn($v, $f) => $f === 'percent' ? $fmtp($v) : ($f === 'integer' ? $fmti($v) : $fmt($v));
$typeLabel = match($reportType) {
    'bimester_vs_bimester' => 'Comparativo bimestre',
    'quarter_vs_quarter'   => 'Comparativo trimestre',
    default                => 'Comparativo mes vs mes',
};

$headlineLabels = ['Recuperación', 'Colocación', 'EBITDA', 'Margen EBITDA', 'OPEX', 'Mora %', 'Valor cartera', 'Cartera vencida'];
$headlineRows = collect($headlineLabels)->map(fn($l) => collect($rows)->firstWhere('label', $l))->filter()->values();

// "Mayores variaciones" (mismo criterio que ComparativePreview.vue::topVariations) —
// orden determinista por magnitud relativa, presentación pura sobre $rows ya calculado.
$topVariations = collect($rows)->filter(fn($r) => $r['var_pct'] != 0)
    ->sortByDesc(fn($r) => abs($r['var_pct']))->take(6)->values();
@endphp
<x-pdf.layout :title="'Comparativo ' . $comparePeriod->label . ' vs ' . $period->label" :ready-immediately="false">

<x-pdf.header
    :title="$typeLabel"
    :subtitle="strtoupper($comparePeriod->label) . ' → ' . strtoupper($period->label)"
    :meta="array_filter([
        'Alcance' => $scopeLabel,
        'Rango anterior' => $compareComposite['component_range'] ?? null,
        'Rango actual' => $currentComposite['component_range'] ?? null,
    ])"
/>

<x-pdf.section-title title="Resumen ejecutivo" subtitle="Periodo actual vs periodo anterior — variación semántica (↑ no siempre es bueno)" />
<div class="pdf-kpi-grid pdf-avoid">
    @foreach($headlineRows as $row)
    <x-pdf.kpi-card
        :label="$row['label']"
        :value="$rowFmt($row['curr'], $row['fmt'])"
        :sub="($row['var_pct'] >= 0 ? '↑ +' : '↓ ') . number_format($row['var_pct'], 2) . '% vs ' . strtoupper($comparePeriod->label)"
        :sub-tone="Tone::toneClass($row['label'], $row['var_pct'])"
    />
    @endforeach
</div>

<x-pdf.section-title title="Gráficas comparativas" alt />
<div class="pdf-charts-row">
    <x-pdf.chart-card title="Indicadores financieros" :subtitle="strtoupper($comparePeriod->label) . ' vs ' . strtoupper($period->label)" chart-id="chartCurrency" :height="190" />
    <x-pdf.chart-card title="Indicadores porcentuales" :subtitle="'Margen EBITDA · Mora % · Rotación %'" chart-id="chartPercent" :height="190" />
</div>

{{-- "Mayores variaciones" va ANTES de los donuts a propósito (retoma 21-sep-2026,
     punto 33) — una tabla SÍ fragmenta con criterio fila por fila en el motor de
     impresión de Chromium (thead se repite, cada <tr> decide individualmente si
     cabe), a diferencia de una tarjeta de gráfica (unidad atómica por
     page-break-inside:avoid) — así aprovecha el espacio que sobra en la página
     antes de pasar a las gráficas de cartera, en vez de dejarlo en blanco. --}}
@if($topVariations->isNotEmpty())
<x-pdf.section-title title="Mayores variaciones" subtitle="Ordenadas por magnitud relativa — sin interpretación automática" alt />
<x-pdf.table>
    <x-slot:head>
        <tr><th>Métrica</th><th class="r">Variación</th></tr>
    </x-slot:head>
    @foreach($topVariations as $row)
    <tr>
        <td class="b">{{ $row['label'] }}</td>
        <td class="r {{ Tone::toneClass($row['label'], $row['var_pct']) }}">
            {{ $row['var_pct'] > 0 ? '↑' : '↓' }} {{ $row['var_pct'] >= 0 ? '+' : '' }}{{ number_format($row['var_pct'], 2) }}%
        </td>
    </tr>
    @endforeach
</x-pdf.table>
@endif

<x-pdf.section-title title="Comparativo de métricas — detalle completo" />
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Métrica</th>
            <th class="r">{{ strtoupper($comparePeriod->label) }}</th>
            <th class="r">{{ strtoupper($period->label) }}</th>
            <th class="r">Diferencia</th>
            <th class="r">Var %</th>
        </tr>
    </x-slot:head>
    @foreach($rows as $row)
    <tr>
        <td class="b">{{ $row['label'] }}</td>
        <td class="r">{{ $rowFmt($row['prev'], $row['fmt']) }}</td>
        <td class="r">{{ $rowFmt($row['curr'], $row['fmt']) }}</td>
        <td class="r">{{ $row['fmt'] === 'percent' ? $rowFmt($row['diff'], $row['fmt']) : ($row['diff'] >= 0 ? '+' : '') . $rowFmt($row['diff'], $row['fmt']) }}</td>
        <td class="r {{ Tone::toneClass($row['label'], $row['var_pct']) }}">{{ $row['var_pct'] >= 0 ? '+' : '' }}{{ number_format($row['var_pct'], 2) }}%</td>
    </tr>
    @endforeach
</x-pdf.table>

<x-pdf.section-title title="Composición de cartera" subtitle="Sana vs vencida por periodo" alt />
<div class="pdf-charts-row">
    <x-pdf.chart-card :title="'Cartera sana vs vencida — ' . strtoupper($comparePeriod->label)" chart-id="chartCarteraPrev" :height="150" />
    <x-pdf.chart-card :title="'Cartera sana vs vencida — ' . strtoupper($period->label)" chart-id="chartCarteraCurr" :height="150" />
</div>

<div class="pdf-footnote">MR LANA · Reportes · Comparativo generado automáticamente</div>

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
</x-pdf.layout>

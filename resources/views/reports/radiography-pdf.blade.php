@php
use App\Services\Radiography\RadiographyMetricToneHelper as Tone;

$snap = $snapshot;
$sum  = $snap['summary'];
$fmt  = fn($v) => '$' . number_format((float)$v, 2);
$fmt0 = fn($v) => '$' . number_format((float)$v, 0);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$fmtn = fn($v) => number_format((float)$v, 0);

// ── Branch radiography — única fuente de verdad ──────────────────────────────
$brCalc     = $snap['branch_radiography'] ?? null;
$brBranches = $brCalc ? ($brCalc['branches'] ?? []) : [];
$brGlobal   = $brCalc ? ($brCalc['global']   ?? null) : null;

// ── Ingresos / Cobranza — desglose seguros ───────────────────────────────────
$ingrBruta       = (float)($brGlobal['recuperacion_bruta']      ?? $sum['recovery_bruta']            ?? 0);
$ingrSegExcluido = (float)($brGlobal['seguro_excluido_bruto']   ?? $sum['recovery_seguro_excluido']  ?? 0);
$ingrSavehearts  = (float)($brGlobal['seguro_savehearts_bruto'] ?? 0);
$ingrComadres    = (float)($brGlobal['seguro_comadres_bruto']   ?? 0);
$ingrCrece       = (float)($brGlobal['seguro_crece_bruto']      ?? $sum['recovery_crece_bruto']      ?? 0);
$ingrCrece30     = (float)($brGlobal['seguro_crece_reconocido'] ?? $sum['recovery_crece_reconocido'] ?? 0);
$ingrCrece70     = max(0.0, $ingrCrece - $ingrCrece30);
$ingrCanalizadoAseguradora = $ingrSavehearts + $ingrComadres + $ingrCrece70;
$ingrTotal       = (float)($brGlobal['recuperacion_total']      ?? $sum['recovery_total']);

// Componentes de ingresos (desglose B)
$ingrCapital     = (float)($brGlobal['capital_recuperado']   ?? 0);
$ingrInteres     = (float)($brGlobal['interes_recuperado']   ?? 0);
$ingrImpuesto    = (float)($brGlobal['impuesto_recuperado']  ?? 0);
$ingrCharges     = (float)($brGlobal['charges']              ?? 0);
$ingrCargosIni   = (float)($brGlobal['cargos_inicio']        ?? 0);
$ingrComAper     = (float)($brGlobal['comision_apertura']    ?? 0);
$ingrCondonacion = (float)($brGlobal['condonacion_excluida'] ?? 0);
$ingrUnificacion = (float)($brGlobal['unificacion_excluida'] ?? 0);
$ingrCargosAdic  = (float)($brGlobal['cargos_adicionales']   ?? 0);
$ingrExcedRec    = (float)($brGlobal['excedente_recuperado'] ?? 0);
$ingrOtrosDet    = (array)($brGlobal['otros_detalle']        ?? []);

// ── Gastos operativos ─────────────────────────────────────────────────────────
$gastosDetalle = (array)($brGlobal['gastos_detalle'] ?? []);
arsort($gastosDetalle);
$gastosOpTotal = (float)($brGlobal['gastos_operativos'] ?? array_sum($gastosDetalle));
$gastosTopN    = array_slice($gastosDetalle, 0, 10, true);
$gastosOtros   = array_sum(array_slice($gastosDetalle, 10, null, true));

// ── Nómina ─────────────────────────────────────────────────────────────────────
// Fuente única: BranchRadiographyCalculator::nominaTotalFor() — NOI neto (percepciones
// − deducciones). nomina_detalle (deducciones, ya restadas) y nomina_informativo
// (IMSS/Gasolina/Motos/Cascos/Finiquito/Médicos/Formatería) son SIEMPRE informativos.
$nomNomina    = (float)($brGlobal['nomina_total']     ?? 0);
$nomComis     = (float)($brGlobal['comisiones']       ?? 0);
$nomVac       = (float)($brGlobal['vacaciones']       ?? 0);
$nomPrimaVac  = (float)($brGlobal['prima_vacacional'] ?? 0);
$nomBonos     = (float)($brGlobal['bonos']            ?? 0);
$nomDetalle   = (array)($brGlobal['nomina_detalle']   ?? []);
$nomInformativoDet = (array)($brGlobal['nomina_informativo'] ?? []);
$nomTotal = \App\Services\Radiography\BranchRadiographyCalculator::nominaTotalFor($brGlobal ?? []);
$nomDisplay = [
    'Nómina' => $nomNomina, 'Comisiones' => $nomComis, 'Vacaciones' => $nomVac,
    'Prima vacacional' => $nomPrimaVac, 'Bonos' => $nomBonos,
];
foreach ($nomDetalle as $dk => $dv) {
    if ($dv > 0) $nomDisplay[$dk] = ($nomDisplay[$dk] ?? 0) + $dv;
}
foreach ($nomInformativoDet as $dk => $dv) {
    if ($dv > 0) $nomDisplay[$dk] = ($nomDisplay[$dk] ?? 0) + $dv;
}

// ── Préstamos intersucursales ──────────────────────────────────────────────────
$fondeoTotal = (float)($brGlobal['prestamos_fondea'] ?? ($snap['sections']['interbranch_loans']['total'] ?? 0));
$excedentes  = (float)($brGlobal['excedentes']       ?? 0);

// ── Mora buckets ───────────────────────────────────────────────────────────────
$mora0_30   = (float)($brGlobal['mora_0_30']    ?? 0);
$mora31_60  = (float)($brGlobal['mora_31_60']   ?? 0);
$mora61_90  = (float)($brGlobal['mora_61_90']   ?? 0);
$mora91_120 = (float)($brGlobal['mora_91_120']  ?? 0);
$mora120p   = (float)($brGlobal['mora_120_plus']?? 0);
$moraTotal  = $mora0_30 + $mora31_60 + $mora61_90 + $mora91_120 + $mora120p;
$cartera    = (float)($brGlobal['valor_cartera']  ?? $sum['portfolio_total']);
$colocacion = (float)($brGlobal['colocacion']     ?? $sum['placement_total']);
$recTotal   = (float)($brGlobal['recuperacion_total'] ?? $sum['recovery_total']);
$moraPct    = $cartera > 0 ? round($moraTotal / $cartera * 100, 2) : 0.0;

$moraBuckets = [
    ['label' => 'Mora 1-30',   'valor' => $mora0_30],
    ['label' => 'Mora 31-60',  'valor' => $mora31_60],
    ['label' => 'Mora 61-90',  'valor' => $mora61_90],
    ['label' => 'Mora 91-120', 'valor' => $mora91_120],
    ['label' => 'Mora 120+',   'valor' => $mora120p],
];
$moraBucketMax = max(array_column($moraBuckets, 'valor')) ?: 1.0;

// ── EBITDA global — CRITERIO FINAL (2026-07) ──────────────────────────────────
// $nomTotal = percepciones brutas + IMSS operativo + gastos reales de empleados (las
// deducciones NOI ya NO se restan, son solo informativas — ver $nomDetalle abajo).
$nomDescuentosNOI = array_sum(array_values($nomDetalle));
$nomNeto          = $nomTotal;
$gastosTotal      = \App\Services\Radiography\BranchRadiographyCalculator::gastosTotalesFor($brGlobal ?? []);
// Ingreso base EBITDA = Intereses + Impuestos + Moratorios/Multas + Comisión por apertura +
// Cargos adicionales + Excedentes recuperados + Seguro CRECE reconocido (30%) — NUNCA
// capital recuperado, NUNCA Recuperación/Colocación completas.
$ingresoEbitdaBase = \App\Services\Radiography\BranchRadiographyCalculator::ingresoEbitdaBaseFor($brGlobal ?? []);
$utilidad          = \App\Services\Radiography\BranchRadiographyCalculator::ebitdaFinalFor($brGlobal ?? []);
$margenEbitda      = \App\Services\Radiography\BranchRadiographyCalculator::margenEbitdaFor($brGlobal ?? []);

// ── Categoría por EBITDA — fórmula centralizada (idéntica al Excel/UI) ────────
// EBITDA = Ingreso base EBITDA − Gastos Totales (ver BranchRadiographyCalculator::ebitdaFinalFor()).
$categorias = [];
foreach ($brBranches as $b) {
    $bRec  = (float)($b['recuperacion_total'] ?? 0);
    $bCol  = (float)($b['colocacion'] ?? 0);
    $bGas  = (float)($b['gastos_operativos'] ?? 0);
    $bNom  = \App\Services\Radiography\BranchRadiographyCalculator::nominaTotalFor($b);
    $bIngBase = \App\Services\Radiography\BranchRadiographyCalculator::ingresoEbitdaBaseFor($b);
    $bUtil = \App\Services\Radiography\RadiographyStyleHelper::branchEbitdaEstimate($b);
    $categorias[] = [
        'nombre'       => $b['sucursal'],
        'recuperacion' => $bRec,
        'colocacion'   => $bCol,
        'gastos'       => $bGas,
        'ingreso_base' => $bIngBase,
        'nomina'       => $bNom,
        'ebitda'       => $bUtil,
        'categoria'    => \App\Services\Radiography\RadiographyStyleHelper::ebitdaCategory($bUtil),
    ];
}
usort($categorias, fn($x, $y) => strcmp($x['nombre'], $y['nombre']));

// ── Resumen por sucursal (página 2) ────────────────────────────────────────────
$sucursalRows = [];
foreach ($brBranches as $b) {
    $moraSumB = (float)($b['mora_0_30']??0)+(float)($b['mora_31_60']??0)+(float)($b['mora_61_90']??0)+(float)($b['mora_91_120']??0)+(float)($b['mora_120_plus']??0);
    $cartValB = (float)($b['valor_cartera']??0);
    $bNomB    = \App\Services\Radiography\BranchRadiographyCalculator::nominaTotalFor($b);
    $sucursalRows[] = [
        'sucursal'     => $b['sucursal'],
        'recuperacion' => (float)($b['recuperacion_total']??0),
        'colocacion'   => (float)($b['colocacion']??0),
        'cartera'      => $cartValB,
        'vencida'      => $moraSumB,
        'mora_pct'     => $cartValB > 0 ? round($moraSumB / $cartValB * 100, 2) : 0,
        'gastos'       => (float)($b['gastos_operativos']??0),
        'nomina'       => $bNomB,
        'ebitda'       => \App\Services\Radiography\RadiographyStyleHelper::branchEbitdaEstimate($b),
    ];
}
usort($sucursalRows, fn($x, $y) => strcmp($x['sucursal'], $y['sucursal']));

// ── Top sucursales con más cartera vencida (página 3) ─────────────────────────
$topVencida = array_filter($sucursalRows, fn($r) => $r['vencida'] > 0);
usort($topVencida, fn($x, $y) => $y['vencida'] <=> $x['vencida']);
$topVencida    = array_slice($topVencida, 0, 8);
$topVencidaMax = !empty($topVencida) ? max(array_column($topVencida, 'vencida')) : 1.0;

// ── Colocación por sucursal (página 4) ────────────────────────────────────────
$colocPorSucursal = array_filter(array_map(fn($r) => ['sucursal' => $r['sucursal'], 'monto' => $r['colocacion']], $sucursalRows), fn($r) => $r['monto'] > 0);
usort($colocPorSucursal, fn($x, $y) => $y['monto'] <=> $x['monto']);
$colocMax = !empty($colocPorSucursal) ? max(array_column($colocPorSucursal, 'monto')) : 1.0;
$productosRows = $snap['sections']['products'] ?? [];

// ── Gastos por sucursal (página 5) ────────────────────────────────────────────
$gastosPorSucursal = array_filter(array_map(fn($r) => ['sucursal' => $r['sucursal'], 'monto' => $r['gastos']], $sucursalRows), fn($r) => $r['monto'] > 0);
usort($gastosPorSucursal, fn($x, $y) => $y['monto'] <=> $x['monto']);
$gastosPorSucursalMax = !empty($gastosPorSucursal) ? max(array_column($gastosPorSucursal, 'monto')) : 1.0;

// ── Nómina por sucursal — resumen ejecutivo (página 6) — CRITERIO FINAL 2026-07 ──
// 'nomina_capital_humano' es el KPI real (nominaTotalFor: percepciones + IMSS + gastos de
// empleados). 'descuentos' es puramente informativo — NO se resta de ningún total.
$nomPorSucursal = [];
foreach ($brBranches as $b) {
    $bDetalle    = (array)($b['nomina_detalle'] ?? []);
    $bDescuentos = array_sum(array_values($bDetalle));
    $bSueldos    = (float)($b['nomina_total'] ?? 0); // ya bruto (percepciones, sin restar deducciones)
    $bComisiones = (float)($b['comisiones'] ?? 0);
    $bBonos      = (float)($b['bonos'] ?? 0);
    $bOtros      = (float)($b['vacaciones'] ?? 0) + (float)($b['prima_vacacional'] ?? 0);
    $bImssGastosEmp = (float)($b['imss_patronal'] ?? 0) + (float)($b['gastos_empleados_nomina'] ?? 0);
    $bNomCapHumano  = \App\Services\Radiography\BranchRadiographyCalculator::nominaTotalFor($b);
    $bTotalBruto = $bSueldos + $bComisiones + $bBonos + $bOtros;
    if ($bNomCapHumano <= 0) continue;
    $nomPorSucursal[] = [
        'sucursal' => $b['sucursal'], 'sueldos' => $bSueldos, 'comisiones' => $bComisiones,
        'bonos' => $bBonos, 'otros' => $bOtros, 'descuentos' => $bDescuentos,
        'imss_gastos_empleados' => $bImssGastosEmp,
        'total' => $bTotalBruto, 'neto' => $bNomCapHumano,
    ];
}
usort($nomPorSucursal, fn($x, $y) => strcmp($x['sucursal'], $y['sucursal']));

// ── Préstamos activos — resumen por sucursal (página 7) ───────────────────────
$activeLoans = $snap['sections']['active_loans'] ?? [];
$activeLoansByBranch = [];
foreach ($activeLoans as $al) {
    $alBranchKey = $al['sucursal'] ?? 'Sin sucursal';
    if (!isset($activeLoansByBranch[$alBranchKey])) {
        $activeLoansByBranch[$alBranchKey] = ['count' => 0, 'saldo' => 0.0, 'vencido' => 0.0];
    }
    $activeLoansByBranch[$alBranchKey]['count']++;
    $activeLoansByBranch[$alBranchKey]['saldo']   += (float)($al['saldo_activo'] ?? 0);
    $activeLoansByBranch[$alBranchKey]['vencido'] += (float)($al['vencido'] ?? 0);
}
ksort($activeLoansByBranch);
$alTotalCount   = array_sum(array_column($activeLoansByBranch, 'count'));
$alTotalSaldo   = array_sum(array_column($activeLoansByBranch, 'saldo'));
$alTotalVencido = array_sum(array_column($activeLoansByBranch, 'vencido'));

// ── Rotación de personal ───────────────────────────────────────────────────────
$rot       = $snap['sections']['rotation'] ?? [];
$rotDetail = $snap['sections']['rotation_detail'] ?? [];
$rotPrevCount = (float)($rot['prev_count'] ?? 0);
$rotCurrCount = (float)($rot['current_count'] ?? ($rot['promedio'] ?? 0));
$rotVariacion = (float)($rot['variacion_neta'] ?? ($rotCurrCount - $rotPrevCount));
$rotPrevMes   = $rot['prev_mes'] ?? null;
$rotPorSucursal = $rot['por_sucursal'] ?? [];

$manualApplied = $snapshot['summary']['manual_adjustment_applied'] ?? null;
@endphp
<x-pdf.layout :title="'Radiografía ' . $period->label" :ready-immediately="empty($executiveCharts)">

<x-pdf.header
    title="Radiografía financiera"
    :subtitle="!empty($snap['period']['composite']) ? ($snap['period']['composite']['week_range'] . ' · ' . $snap['period']['composite']['date_start'] . ' → ' . $snap['period']['composite']['date_end']) : null"
    :meta="array_filter([
        'Periodo' => strtoupper($period->label),
        'Rango' => $snap['period']['composite']['component_range'] ?? null,
        'Generado' => $snap['generated_at'],
    ])"
/>

<div class="pdf-kpi-grid pdf-avoid">
    <x-pdf.kpi-card label="Utilidad bruta" :value="$fmt0($ingresoEbitdaBase)" />
    <x-pdf.kpi-card label="Gastos Totales" :value="$fmt0($gastosTotal)" />
    <x-pdf.kpi-card label="EBITDA" :value="$fmt0($utilidad)" :tone="$utilidad < 0 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Margen EBITDA" :value="$fmtp($margenEbitda)" :tone="$margenEbitda < 0 ? 'negv' : ''" />
    <x-pdf.kpi-card label="OPEX" :value="$fmt0($gastosOpTotal)" />
    <x-pdf.kpi-card label="Nómina y Capital Humano" :value="$fmt0($nomTotal)" />
    <x-pdf.kpi-card label="Cartera" :value="$fmt0($cartera)" />
    <x-pdf.kpi-card label="Mora %" :value="$fmtp($moraPct)" :tone="$moraPct > 25 ? 'negv' : ''" />
</div>

<div class="pdf-cols-2" style="margin-top:12px;">
    <div>
        <x-pdf.section-title title="Resumen ejecutivo" alt />
        <div style="font-size:7.8pt; line-height:1.55; color:#334155;">
            La cartera total del periodo es de <b>{{ $fmt0($cartera) }}</b>, con una cartera vencida de
            <b>{{ $fmt0($moraTotal) }}</b> ({{ $fmtp($moraPct) }} de la cartera).
            La utilidad bruta (intereses, impuestos, moratorios, comisión por apertura,
            cargos adicionales, excedentes y 30% de Seguro CRECE — sin capital recuperado) fue de
            <b>{{ $fmt0($ingresoEbitdaBase) }}</b>.
            Considerando gastos operativos / OPEX (<b>{{ $fmt0($gastosOpTotal) }}</b>) y Nómina y
            Capital Humano (<b>{{ $fmt0($nomTotal) }}</b>), Gastos Totales suman
            <b>{{ $fmt0($gastosTotal) }}</b>, para un EBITDA del periodo de
            <b>{{ $fmt0($utilidad) }}</b> (margen {{ $fmtp($margenEbitda) }}).
            Como referencia informativa, la recuperación/cobranza total alcanzó
            <b>{{ $fmt0($recTotal) }}</b> contra una colocación de <b>{{ $fmt0($colocacion) }}</b>.
        </div>
    </div>
    <div>
        <x-pdf.section-title title="Estado general" alt />
        <x-pdf.summary-panel tone="ok">Excedente enviado a corporativo: <b>{{ $fmt0($excedentes) }}</b></x-pdf.summary-panel>
        <div style="font-size:7.6pt; color:#475569; margin-top:6px;">
            Préstamos intersucursales (fondea): <b>{{ $fmt0($fondeoTotal) }}</b>
        </div>
        @if($manualApplied && (float) ($manualApplied['amount'] ?? 0) > 0)
        <div style="font-size:7.6pt; color:#475569; margin-top:6px; border-top:0.5pt solid #e2e8f0; padding-top:6px;">
            <b>AJUSTE TEMPORAL</b> (ya incluido arriba): {{ $fmt0($manualApplied['amount']) }}
            ({{ $fmt0($manualApplied['amount_per_employee'] ?? 0) }} × {{ (int) ($manualApplied['employee_count'] ?? 0) }} colaboradores)
            — {{ $manualApplied['notes'] ?: 'Sin observación' }}
        </div>
        @endif
    </div>
</div>

@if(!empty($categorias))
<x-pdf.section-title title="Categoría por EBITDA" />
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Sucursal</th><th class="r">Utilidad bruta</th><th class="r">OPEX</th>
            <th class="r">Nómina</th><th class="r">EBITDA</th><th class="c">Categoría</th>
        </tr>
    </x-slot:head>
    @foreach($categorias as $c)
    <tr>
        <td class="b">{{ $c['nombre'] }}</td>
        <td class="r">{{ $fmt($c['ingreso_base']) }}</td>
        <td class="r">{{ $fmt($c['gastos']) }}</td>
        <td class="r">{{ $fmt($c['nomina']) }}</td>
        <td class="r b" @if($c['ebitda'] < 0) style="color:#b91c1c;" @endif>{{ $fmt($c['ebitda']) }}</td>
        <td class="c"><x-pdf.badge :label="$c['categoria']" :category="$c['categoria']" /></td>
    </tr>
    @endforeach
</x-pdf.table>
<div class="pdf-note">EBITDA = Utilidad bruta (intereses + impuestos + moratorios + comisión por apertura + cargos adicionales + excedentes + 30% Seguro CRECE) − Gastos Totales (OPEX + Nómina y Capital Humano) por sucursal. No incluye capital recuperado. Categorías: Diamante ≥$1M / Máster ≥$600K / Sénior ≥$300K / Júnior ≥$100K / Mantenido &lt;$100K.</div>
@endif

<x-pdf.section-title title="Resumen por sucursal" />
@if(!empty($sucursalRows))
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Sucursal</th><th class="r">Recuperación</th><th class="r">Colocación</th><th class="r">Cartera</th>
            <th class="r">Vencida</th><th class="r">Mora %</th><th class="r">Gastos</th><th class="r">Nómina</th><th class="r">EBITDA</th>
        </tr>
    </x-slot:head>
    @foreach($sucursalRows as $r)
    <tr>
        <td class="b">{{ $r['sucursal'] }}</td>
        <td class="r">{{ $fmt($r['recuperacion']) }}</td>
        <td class="r">{{ $fmt($r['colocacion']) }}</td>
        <td class="r">{{ $fmt($r['cartera']) }}</td>
        <td class="r" @if($r['vencida'] > 0) style="color:#b91c1c;" @endif>{{ $fmt($r['vencida']) }}</td>
        <td class="r" @if($r['mora_pct'] > 25) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmtp($r['mora_pct']) }}</td>
        <td class="r">{{ $fmt($r['gastos']) }}</td>
        <td class="r">{{ $fmt($r['nomina']) }}</td>
        <td class="r b" @if($r['ebitda'] < 0) style="color:#b91c1c;" @endif>{{ $fmt($r['ebitda']) }}</td>
    </tr>
    @endforeach
    <x-slot:foot>
        <tr>
            <td>GLOBAL ({{ count($sucursalRows) }} sucursales)</td>
            <td class="r">{{ $fmt($recTotal) }}</td>
            <td class="r">{{ $fmt($colocacion) }}</td>
            <td class="r">{{ $fmt($cartera) }}</td>
            <td class="r">{{ $fmt($moraTotal) }}</td>
            <td class="r">{{ $fmtp($moraPct) }}</td>
            <td class="r">{{ $fmt($gastosOpTotal) }}</td>
            <td class="r">{{ $fmt($nomTotal) }}</td>
            <td class="r">{{ $fmt($utilidad) }}</td>
        </tr>
    </x-slot:foot>
</x-pdf.table>
@else
<x-pdf.empty-state message="Sin datos por sucursal para este periodo." />
@endif

<x-pdf.section-title title="Mora por bucket" />
<div class="pdf-cols-2">
    <div>
        @foreach($moraBuckets as $mb)
        <div class="pdf-bar-row">
            <div class="pdf-bar-label">{{ $mb['label'] }}</div>
            <div class="pdf-bar-track"><span class="pdf-bar-fill pdf-bar-fill-red" style="width:{{ $moraBucketMax > 0 ? min(100, round($mb['valor'] / $moraBucketMax * 100)) : 0 }}%;"></span></div>
            <div class="pdf-bar-value">{{ $fmt0($mb['valor']) }}</div>
        </div>
        @endforeach
    </div>
    <div>
        <div class="pdf-bar-row">
            <div class="pdf-bar-label">Cartera total</div>
            <div class="pdf-bar-track"><span class="pdf-bar-fill pdf-bar-fill-teal" style="width:100%;"></span></div>
            <div class="pdf-bar-value">{{ $fmt0($cartera) }}</div>
        </div>
        <div class="pdf-bar-row">
            <div class="pdf-bar-label">Cartera vencida</div>
            <div class="pdf-bar-track"><span class="pdf-bar-fill pdf-bar-fill-red" style="width:{{ $cartera > 0 ? min(100, round($moraTotal / $cartera * 100)) : 0 }}%;"></span></div>
            <div class="pdf-bar-value">{{ $fmt0($moraTotal) }} ({{ $fmtp($moraPct) }})</div>
        </div>
    </div>
</div>

@if(!empty($snap['sections']['portfolio_buckets']))
<x-pdf.section-title title="Distribución de cartera por días vencidos" alt />
<x-pdf.table>
    <x-slot:head><tr><th>Bucket</th><th class="r">Contratos</th><th class="r">Balance</th><th class="r">Vencido</th></tr></x-slot:head>
    @foreach($snap['sections']['portfolio_buckets'] as $b)
    <tr>
        <td class="b">{{ $b['label'] }}</td>
        <td class="r">{{ $fmtn($b['contratos']) }}</td>
        <td class="r">{{ $fmt($b['balance']) }}</td>
        <td class="r" @if($b['vencida'] > 0 && $b['label'] !== 'Al corriente') style="color:#b91c1c;" @endif>{{ $fmt($b['vencida']) }}</td>
    </tr>
    @endforeach
</x-pdf.table>
@endif

@if(!empty($topVencida))
<x-pdf.section-title title="Top sucursales con más cartera vencida" alt />
@foreach($topVencida as $tv)
<div class="pdf-bar-row">
    <div class="pdf-bar-label">{{ $tv['sucursal'] }}</div>
    <div class="pdf-bar-track"><span class="pdf-bar-fill pdf-bar-fill-red" style="width:{{ $topVencidaMax > 0 ? min(100, round($tv['vencida'] / $topVencidaMax * 100)) : 0 }}%;"></span></div>
    <div class="pdf-bar-value">{{ $fmt0($tv['vencida']) }} &nbsp;·&nbsp; Mora {{ $fmtp($tv['mora_pct']) }}</div>
</div>
@endforeach
@endif

<x-pdf.section-title title="Ingresos / Recuperación" />

{{-- A) Desglose por componente --}}
@if($ingrCapital > 0 || $ingrInteres > 0)
<x-pdf.section-title title="A) Desglose por componente" alt />
<x-pdf.table>
    <x-slot:head><tr><th>Componente</th><th class="r">Monto</th></tr></x-slot:head>
    @if($ingrCapital > 0)<tr><td>Capital recuperado</td><td class="r">{{ $fmt($ingrCapital) }}</td></tr>@endif
    @if($ingrInteres > 0)<tr><td>Intereses</td><td class="r">{{ $fmt($ingrInteres) }}</td></tr>@endif
    @if($ingrImpuesto > 0)<tr><td>Impuestos</td><td class="r">{{ $fmt($ingrImpuesto) }}</td></tr>@endif
    @if($ingrCharges > 0)<tr><td>Moratorios / Multas</td><td class="r">{{ $fmt($ingrCharges) }}</td></tr>@endif
    @if($ingrCargosIni > 0)<tr><td>Cargos al inicio</td><td class="r">{{ $fmt($ingrCargosIni) }}</td></tr>@endif
    @if($ingrComAper > 0)<tr><td>Comisión por apertura</td><td class="r">{{ $fmt($ingrComAper) }}</td></tr>@endif
    @if($ingrCargosAdic > 0)<tr><td>Cargos adicionales</td><td class="r">{{ $fmt($ingrCargosAdic) }}</td></tr>@endif
    @if($ingrExcedRec > 0)<tr><td>Excedentes recuperados</td><td class="r">{{ $fmt($ingrExcedRec) }}</td></tr>@endif
    @if($ingrCrece30 > 0)<tr><td>Seguro CRECE reconocido (30%)</td><td class="r">{{ $fmt($ingrCrece30) }}</td></tr>@endif
    @foreach($ingrOtrosDet as $otrosLabel => $otrosVal)
        @if($otrosVal != 0)<tr><td>{{ $otrosLabel }}</td><td class="r">{{ $fmt($otrosVal) }}</td></tr>@endif
    @endforeach
    <x-slot:foot><tr><td><b>Total Recuperación</b></td><td class="r">{{ $fmt($ingrTotal) }}</td></tr></x-slot:foot>
</x-pdf.table>
@endif

{{-- B) Desglose por sucursal --}}
@if(!empty($brBranches))
<x-pdf.section-title title="B) Desglose por sucursal" alt />
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Sucursal</th><th class="r">Capital</th><th class="r">Intereses</th><th class="r">Impuestos</th>
            <th class="r">Moratorios</th><th class="r">Cargos adic.</th><th class="r">Cargos inicio</th>
            <th class="r">Com. apertura</th><th class="r">Excedentes</th><th class="r">Seguro CRECE 30%</th>
            <th class="r">Otros</th><th class="r">Total</th>
        </tr>
    </x-slot:head>
    @foreach($brBranches as $bb)
    <tr>
        <td class="b">{{ $bb['sucursal'] }}</td>
        <td class="r">{{ $fmt((float)($bb['capital_recuperado'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['interes_recuperado'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['impuesto_recuperado'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['charges'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['cargos_adicionales'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['cargos_inicio'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['comision_apertura'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['excedente_recuperado'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['seguro_crece_reconocido'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($bb['otros_recuperacion'] ?? 0)) }}</td>
        <td class="r b">{{ $fmt((float)($bb['recuperacion_total'] ?? 0)) }}</td>
    </tr>
    @endforeach
    <x-slot:foot>
        <tr>
            <td>TOTAL</td>
            <td class="r">{{ $fmt((float)($brGlobal['capital_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['interes_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['impuesto_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['charges'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['cargos_adicionales'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['cargos_inicio'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['comision_apertura'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['excedente_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['seguro_crece_reconocido'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['otros_recuperacion'] ?? 0)) }}</td>
            <td class="r">{{ $fmt($ingrTotal) }}</td>
        </tr>
    </x-slot:foot>
</x-pdf.table>
@endif

{{-- C) Recuperación por producto --}}
@php $recoveryByProduct = $snap['sections']['recovery_by_product']['rows'] ?? []; @endphp
@if(!empty($recoveryByProduct))
<x-pdf.section-title title="C) Recuperación por producto" alt />
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Producto</th><th class="r">Capital</th><th class="r">Intereses</th><th class="r">Impuestos</th>
            <th class="r">Moratorios</th><th class="r">Cargos adic.</th><th class="r">Com. apertura</th>
            <th class="r">Excedentes</th><th class="r">Seguro CRECE 30%</th><th class="r">Otros</th><th class="r">Total</th>
        </tr>
    </x-slot:head>
    @foreach($recoveryByProduct as $pr)
    <tr>
        <td class="b">{{ $pr['product'] }}</td>
        <td class="r">{{ $fmt((float)($pr['capital'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['interes'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['impuesto'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['moratorios'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['cargos_adicionales'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['comision_apertura'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['excedente_recuperado'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['seguro_crece_reconocido'] ?? 0)) }}</td>
        <td class="r">{{ $fmt((float)($pr['otros'] ?? 0)) }}</td>
        <td class="r b">{{ $fmt((float)($pr['total'] ?? 0)) }}</td>
    </tr>
    @endforeach
    <x-slot:foot><tr><td>TOTAL</td><td colspan="8"></td><td class="r">{{ $fmt($ingrTotal) }}</td></tr></x-slot:foot>
</x-pdf.table>
@endif

{{-- Seguros y coberturas canalizadas — informativo, no afecta recuperación, OPEX, nómina ni EBITDA --}}
@if($ingrCrece > 0 || $ingrSavehearts > 0 || $ingrComadres > 0)
<x-pdf.section-title title="Seguros y coberturas canalizadas" alt />
<x-pdf.table>
    <x-slot:head><tr><th>Concepto</th><th class="r">Monto</th></tr></x-slot:head>
    @if($ingrSavehearts > 0)<tr><td>Cobertura Savehearts</td><td class="r">{{ $fmt($ingrSavehearts) }}</td></tr>@endif
    @if($ingrComadres > 0)<tr><td>Cobertura Crédito Grupal / Comadres</td><td class="r">{{ $fmt($ingrComadres) }}</td></tr>@endif
    @if($ingrCrece > 0)<tr><td>Seguro CRECE total</td><td class="r">{{ $fmt($ingrCrece) }}</td></tr>@endif
    @if($ingrCrece30 > 0)<tr><td>&nbsp;&nbsp;Reconocido como ingreso MR Lana (30%)</td><td class="r" style="color:#065f46;">{{ $fmt($ingrCrece30) }}</td></tr>@endif
    @if($ingrCrece70 > 0)<tr><td>&nbsp;&nbsp;Canalizado a aseguradora (70%)</td><td class="r">{{ $fmt($ingrCrece70) }}</td></tr>@endif
    <x-slot:foot><tr><td><b>Total canalizado a aseguradora</b></td><td class="r">{{ $fmt($ingrCanalizadoAseguradora) }}</td></tr></x-slot:foot>
</x-pdf.table>
@endif

{{-- Colocación: info complementaria --}}
<x-pdf.section-title title="Colocación del periodo (informativo)" alt />
<x-pdf.table>
    <x-slot:head><tr><th>Concepto</th><th class="r">Monto</th></tr></x-slot:head>
    <tr><td>Préstamos intersucursales (fondea)</td><td class="r">{{ $fmt($fondeoTotal) }}</td></tr>
    <x-slot:foot><tr><td>Total colocación</td><td class="r">{{ $fmt($colocacion) }}</td></tr></x-slot:foot>
</x-pdf.table>

@if(!empty($productosRows))
<x-pdf.section-title title="Colocación por producto" alt />
<x-pdf.table>
    <x-slot:head><tr><th>Producto</th><th class="r">Operaciones</th><th class="r">Colocación</th><th class="r">Recuperación</th></tr></x-slot:head>
    @foreach($productosRows as $p)
    <tr>
        <td class="b">{{ $p['producto'] }}</td>
        <td class="r">{{ $fmtn($p['operaciones']) }}</td>
        <td class="r">{{ $fmt($p['colocacion']) }}</td>
        <td class="r">{{ $fmt($p['recuperacion'] ?? 0) }}</td>
    </tr>
    @endforeach
</x-pdf.table>
@endif

@if(!empty($colocPorSucursal))
<x-pdf.section-title title="Colocación por sucursal" alt />
@foreach($colocPorSucursal as $cp)
<div class="pdf-bar-row">
    <div class="pdf-bar-label">{{ $cp['sucursal'] }}</div>
    <div class="pdf-bar-track"><span class="pdf-bar-fill pdf-bar-fill-blue" style="width:{{ $colocMax > 0 ? min(100, round($cp['monto'] / $colocMax * 100)) : 0 }}%;"></span></div>
    <div class="pdf-bar-value">{{ $fmt0($cp['monto']) }}</div>
</div>
@endforeach
@endif

<x-pdf.section-title title="Gastos operativos" />

@if(!empty($gastosTopN))
<x-pdf.section-title title="Top gastos por categoría" alt />
<x-pdf.table>
    <x-slot:head><tr><th>Categoría</th><th class="r">Monto</th></tr></x-slot:head>
    @foreach($gastosTopN as $concepto => $monto)
    <tr><td class="b">{{ $concepto }}</td><td class="r">{{ $fmt((float)$monto) }}</td></tr>
    @endforeach
    @if($gastosOtros > 0)
    <tr><td>Resto de categorías (fuera del top 10)</td><td class="r">{{ $fmt($gastosOtros) }}</td></tr>
    @endif
    <x-slot:foot><tr><td>Total gastos operativos</td><td class="r">{{ $fmt($gastosOpTotal) }}</td></tr></x-slot:foot>
</x-pdf.table>
@endif

@if(!empty($gastosPorSucursal))
<x-pdf.section-title title="Gastos por sucursal" alt />
@foreach($gastosPorSucursal as $gp)
<div class="pdf-bar-row">
    <div class="pdf-bar-label">{{ $gp['sucursal'] }}</div>
    <div class="pdf-bar-track"><span class="pdf-bar-fill pdf-bar-fill-teal" style="width:{{ $gastosPorSucursalMax > 0 ? min(100, round($gp['monto'] / $gastosPorSucursalMax * 100)) : 0 }}%;"></span></div>
    <div class="pdf-bar-value">{{ $fmt0($gp['monto']) }}</div>
</div>
@endforeach
@endif

<x-pdf.section-title title="Nómina y capital humano" />
@if(!empty($nomPorSucursal))
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Sucursal</th><th class="r">Sueldos</th><th class="r">Comisiones</th><th class="r">Bonos</th>
            <th class="r">Vac./Prima/Otras</th><th class="r">Descuentos (informativo)</th>
            <th class="r">IMSS + Gasto empleado</th><th class="r">Total Nómina y Capital Humano</th>
        </tr>
    </x-slot:head>
    @foreach($nomPorSucursal as $n)
    <tr>
        <td class="b">{{ $n['sucursal'] }}</td>
        <td class="r">{{ $fmt($n['sueldos']) }}</td>
        <td class="r">{{ $fmt($n['comisiones']) }}</td>
        <td class="r">{{ $fmt($n['bonos']) }}</td>
        <td class="r">{{ $fmt($n['otros']) }}</td>
        <td class="r" style="color:#64748b;">{{ $fmt($n['descuentos']) }}</td>
        <td class="r">{{ $fmt($n['imss_gastos_empleados']) }}</td>
        <td class="r b">{{ $fmt($n['neto']) }}</td>
    </tr>
    @endforeach
    <x-slot:foot>
        <tr>
            <td>TOTAL GLOBAL</td>
            <td class="r">{{ $fmt($nomNomina) }}</td>
            <td class="r">{{ $fmt($nomComis) }}</td>
            <td class="r">{{ $fmt($nomBonos) }}</td>
            <td class="r">{{ $fmt($nomTotal - $nomNomina - $nomComis - $nomBonos - (float)($brGlobal['imss_patronal'] ?? 0) - (float)($brGlobal['gastos_empleados_nomina'] ?? 0)) }}</td>
            <td class="r">{{ $fmt($nomDescuentosNOI) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['imss_patronal'] ?? 0) + (float)($brGlobal['gastos_empleados_nomina'] ?? 0)) }}</td>
            <td class="r">{{ $fmt($nomTotal) }}</td>
        </tr>
    </x-slot:foot>
</x-pdf.table>
<div class="pdf-note">Descuentos NOI: informativos, no se restan del total. Gastos de empleados sí están incluidos.</div>
@else
<x-pdf.empty-state message="Sin datos de nómina por sucursal para este periodo." />
@endif

<x-pdf.section-title title="Préstamos activos — resumen por sucursal" />
@if(!empty($activeLoansByBranch))
<x-pdf.table>
    <x-slot:head><tr><th>Sucursal</th><th class="r">Créditos activos</th><th class="r">Saldo activo</th><th class="r">Vencido</th><th class="r">% Vencido</th></tr></x-slot:head>
    @foreach($activeLoansByBranch as $alBranchName => $alData)
    @php $alPct = $alData['saldo'] > 0 ? round($alData['vencido'] / $alData['saldo'] * 100, 2) : 0; @endphp
    <tr>
        <td class="b">{{ $alBranchName === 'Sin sucursal' ? '—' : $alBranchName }}</td>
        <td class="r">{{ $fmtn($alData['count']) }}</td>
        <td class="r">{{ $fmt($alData['saldo']) }}</td>
        <td class="r" @if($alData['vencido'] > 0) style="color:#b91c1c;" @endif>{{ $fmt($alData['vencido']) }}</td>
        <td class="r" @if($alPct > 25) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmtp($alPct) }}</td>
    </tr>
    @endforeach
    <x-slot:foot>
        <tr>
            <td>TOTAL GENERAL</td>
            <td class="r">{{ $fmtn($alTotalCount) }}</td>
            <td class="r">{{ $fmt($alTotalSaldo) }}</td>
            <td class="r">{{ $fmt($alTotalVencido) }}</td>
            <td class="r">{{ $alTotalSaldo > 0 ? $fmtp(round($alTotalVencido / $alTotalSaldo * 100, 2)) : '0.00%' }}</td>
        </tr>
    </x-slot:foot>
</x-pdf.table>
@else
<x-pdf.empty-state message="Sin préstamos activos registrados para este periodo." />
@endif

<x-pdf.section-title title="Rotación de personal" />
<div class="pdf-kpi-grid pdf-avoid">
    <x-pdf.kpi-card :label="'Plantilla ' . ($rotPrevMes ?: 'mes anterior')" :value="$fmtn($rotPrevCount)" />
    <x-pdf.kpi-card :label="'Plantilla ' . ($rot['mes'] ?? 'mes actual')" :value="$fmtn($rotCurrCount)" />
    <x-pdf.kpi-card label="Altas" :value="$fmtn($rot['altas'] ?? 0)" />
    <x-pdf.kpi-card label="Bajas" :value="$fmtn($rot['bajas'] ?? 0)" />
    <x-pdf.kpi-card label="Variación neta de plantilla" :value="($rotVariacion >= 0 ? '+' : '') . $fmtn($rotVariacion)" :tone="$rotVariacion < 0 ? 'negv' : ''" />
    <x-pdf.kpi-card label="Índice de rotación" :value="$fmtp($rot['indice'] ?? 0)" :tone="($rot['indice'] ?? 0) > 5 ? 'negv' : ''" />
</div>

<x-pdf.section-title title="Detalle por sucursal" alt />
@if(!empty($rotPorSucursal))
<x-pdf.table>
    <x-slot:head>
        <tr>
            <th>Sucursal</th><th class="r">Plantilla anterior</th><th class="r">Plantilla actual</th>
            <th class="r">Altas</th><th class="r">Bajas</th><th class="r">Variación</th><th class="r">Índice</th>
        </tr>
    </x-slot:head>
    @foreach($rotPorSucursal as $rs)
    @php $rsVar = (float)($rs['variacion_plantilla'] ?? ((float)($rs['promedio_personal'] ?? 0) - (float)($rs['plantilla_anterior'] ?? 0))); @endphp
    <tr>
        <td class="b">{{ $rs['sucursal'] ?? '' }}</td>
        <td class="r">{{ $fmtn($rs['plantilla_anterior'] ?? 0) }}</td>
        <td class="r">{{ $fmtn($rs['promedio_personal'] ?? 0) }}</td>
        <td class="r">{{ $fmtn($rs['altas'] ?? 0) }}</td>
        <td class="r">{{ $fmtn($rs['bajas'] ?? 0) }}</td>
        <td class="r" @if($rsVar < 0) style="color:#b91c1c;" @elseif($rsVar > 0) style="color:#106A59;" @endif>{{ $rsVar >= 0 ? '+' : '' }}{{ $fmtn($rsVar) }}</td>
        <td class="r">{{ $fmtp($rs['indice_rotacion'] ?? 0) }}</td>
    </tr>
    @endforeach
</x-pdf.table>
@else
<x-pdf.empty-state message="Sin datos de rotación disponibles para este periodo." />
@endif

<x-pdf.section-title :title="'Altas (' . count($rotDetail['altas'] ?? []) . ')'" alt />
@if(!empty($rotDetail['altas']))
<x-pdf.table>
    <x-slot:head><tr><th>Sucursal</th><th>Clave</th><th>Colaborador</th></tr></x-slot:head>
    @foreach($rotDetail['altas'] as $a)
    <tr><td>{{ $a['sucursal'] ?? '' }}</td><td>{{ $a['clave'] ?? '—' }}</td><td>{{ $a['nombre'] ?? '' }}</td></tr>
    @endforeach
</x-pdf.table>
@else
<x-pdf.empty-state message="Sin altas en este periodo." />
@endif

<x-pdf.section-title :title="'Bajas (' . count($rotDetail['bajas'] ?? []) . ')'" alt />
@if(!empty($rotDetail['bajas']))
<x-pdf.table>
    <x-slot:head><tr><th>Sucursal</th><th>Clave</th><th>Colaborador</th></tr></x-slot:head>
    @foreach($rotDetail['bajas'] as $b)
    <tr><td>{{ $b['sucursal'] ?? '' }}</td><td>{{ $b['clave'] ?? '—' }}</td><td>{{ $b['nombre'] ?? '' }}</td></tr>
    @endforeach
</x-pdf.table>
@else
<x-pdf.empty-state message="Sin bajas en este periodo." />
@endif

<div class="pdf-footnote">MR LANA · Reportes · Radiografía generada automáticamente · {{ $period->label }}</div>

<!-- Pie de página + numeración: los dibuja BrowsershotPdfRenderer vía el footer nativo
     de Chrome (Puppeteer page.pdf({displayHeaderFooter})) — ver footer_left en
     RadiografiaExportService::exportPdf()/exportPdfWithConfig(). -->

@if(!empty($executiveCharts))
@include('reports.partials.radiography-pdf-charts-section')
@endif

</x-pdf.layout>

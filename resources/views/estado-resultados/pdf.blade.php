@php
/**
 * PDF ejecutivo del Estado de Resultados. Recibe el dataset canónico ($d) de
 * EstadoResultadosDatasetBuilder y NO recalcula ninguna cifra: sólo da formato.
 * Mismo hash de dataset que la web y el Excel (pie de cada página).
 */
$money = fn ($v) => $v === null ? '—' : (((float) $v < 0) ? '-$' : '$').number_format(abs((float) $v), 2);
$pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 2).'%';
$logoPath = public_path('logo-mr-lana.png');
$logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
$r = $d['results'];
$m = $d['meta'];
$consolidated = $m['scope']['consolidated'];
$section = fn (string $key) => collect($d['statement'])->firstWhere('key', $key);
$resultBlock = fn (string $key) => collect($d['statement'])->first(fn ($b) => $b['type'] === 'result' && $b['key'] === $key);
$steps = [
    ['n' => '1', 'title' => 'Ingresos operativos', 'sub' => 'Intereses + IVA (criterio gerencial) + moratorios + cargos + comisiones. Sin capital recuperado.', 'amount' => $r['ingresos'], 'kind' => 'line'],
    ['n' => '2', 'title' => 'OPEX y nómina de sucursales', 'sub' => 'OPEX '.$money($r['opex_sucursales']).' + Nómina y Capital Humano '.$money($r['nomina_sucursales']), 'amount' => (string) round((float) $r['opex_sucursales'] + (float) $r['nomina_sucursales'], 2), 'kind' => 'line'],
    ['n' => '=', 'title' => 'EBITDA SUCURSALES', 'sub' => 'Conciliado con Radiografía · diferencia '.$money($d['radiography_reconciliation']['difference']), 'amount' => $r['ebitda_sucursales'], 'kind' => 'result', 'key' => 'ebitda_sucursales'],
];
if ($consolidated) {
    $steps[] = ['n' => '3', 'title' => 'Corporativo', 'sub' => 'OPEX corporativo '.$money($r['opex_corporativo']).' + Nómina corporativa '.$money($r['nomina_corporativa']), 'amount' => (string) round((float) $r['opex_corporativo'] + (float) $r['nomina_corporativa'], 2), 'kind' => 'line'];
    $steps[] = ['n' => '=', 'title' => 'EBITDA CORPORATIVO', 'sub' => 'EBITDA sucursales − Corporativo', 'amount' => $r['ebitda_corporativo'], 'kind' => 'result', 'key' => 'ebitda_corporativo'];
    $steps[] = ['n' => '4', 'title' => 'Dirección General', 'sub' => 'Sólo gasto empresarial y remuneración DG. Lo personal/patrimonial queda fuera.', 'amount' => $r['dg'], 'kind' => 'line'];
    $steps[] = ['n' => '=', 'title' => 'EBITDA FINAL', 'sub' => 'EBITDA corporativo − DG operativo', 'amount' => $r['ebitda_final'], 'kind' => 'result', 'key' => 'ebitda_final'];
}
$steps[] = ['n' => $consolidated ? '5' : '3', 'title' => 'Depreciación y amortización', 'sub' => 'Activos propios + sistemas capitalizados.', 'amount' => $r['depreciacion_amortizacion'], 'kind' => 'line'];
$steps[] = ['n' => '=', 'title' => 'EBIT', 'sub' => 'EBITDA − D&A', 'amount' => $r['ebit'], 'kind' => 'result', 'key' => 'ebit'];
$steps[] = ['n' => $consolidated ? '6' : '4', 'title' => 'Resultado financiero y no operativo', 'sub' => 'Intereses BBVA / LEROM + otros − ingresos financieros. Nunca el capital del préstamo.', 'amount' => $r['resultado_financiero_neto'], 'kind' => 'line'];
$steps[] = ['n' => '=', 'title' => 'EBT', 'sub' => 'EBIT − resultado financiero neto', 'amount' => $r['ebt'], 'kind' => 'result', 'key' => 'ebt'];
$steps[] = ['n' => $consolidated ? '7' : '5', 'title' => 'ISR', 'sub' => 'Impuesto sobre la renta del periodo.', 'amount' => $r['isr'], 'kind' => 'line'];
$steps[] = ['n' => '=', 'title' => 'UTILIDAD NETA', 'sub' => 'EBT − ISR', 'amount' => $r['utilidad_neta'], 'kind' => 'result final', 'key' => 'utilidad_neta'];
$bars = function (array $lines) {
    $max = max(1, ...array_map(fn ($l) => abs((float) $l['amount']), $lines ?: [['amount' => 1]]));
    return array_map(fn ($l) => $l + ['w' => max(0.5, abs((float) $l['amount']) / $max * 100)], $lines);
};
$c = $d['conciliation'];
$rec = $d['radiography_reconciliation'];
$failedChecks = collect($d['checks'])->where('ok', false);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Estado de Resultados — {{ $m['period']['label'] }}</title>
<script>window.__PDF_READY__ = true;</script>
<style>
    @include('reports.partials.montserrat-font-face')
    * { box-sizing: border-box; margin: 0; padding: 0; }
    @page { size: Letter portrait; }
    html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'Montserrat', Helvetica, Arial, sans-serif; color: #0f172a; font-size: 8.2pt; background: #fff; }
    .brand { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #10b981; padding-bottom: 8px; margin-bottom: 10px; }
    .brand img { height: 42px; }
    .brand .meta { text-align: right; font-size: 7.2pt; color: #475569; line-height: 1.5; }
    .brand .meta b { color: #0f172a; }
    .hero { text-align: center; margin: 4px 0 10px; }
    .hero h1 { font-size: 22pt; font-weight: 800; letter-spacing: -.5px; line-height: 1.05; }
    .hero h1 .g1 { color: #059669; } .hero h1 .g2 { color: #0891b2; }
    .hero .sub { color: #64748b; font-size: 8.5pt; margin-top: 3px; }
    .step { display: flex; align-items: stretch; gap: 8px; margin-bottom: 5px; page-break-inside: avoid; }
    .step .num { width: 30px; border-radius: 8px; background: linear-gradient(135deg, #22c55e, #059669); color: #fff; font-weight: 800; font-size: 13pt; display: flex; align-items: center; justify-content: center; }
    .step .body { flex: 1; border: 1px solid #e2e8f0; border-radius: 9px; padding: 6px 10px; background: #fff; }
    .step .body .t { font-weight: 800; font-size: 9.6pt; }
    .step .body .s { color: #64748b; font-size: 7pt; margin-top: 1px; }
    .step .amt { width: 150px; border-radius: 9px; background: #f0fdfa; border: 1px solid #ccfbf1; display: flex; align-items: center; justify-content: flex-end; padding: 0 12px; font-weight: 800; font-size: 11pt; }
    .step.result .num { background: #0b3b36; }
    .step.result .body { background: linear-gradient(90deg, #0f766e, #06b6d4); border: none; color: #fff; }
    .step.result .body .s { color: #ccfbf1; }
    .step.result .amt { background: #fff; border: 2px solid #06b6d4; color: #047857; font-size: 13pt; }
    .step.result .amt.neg { color: #b91c1c; }
    .step.final .body { background: linear-gradient(90deg, #065f46, #10b981 60%, #06b6d4); }
    .badge { display: inline-block; padding: 1px 6px; border-radius: 99px; font-size: 6.4pt; font-weight: 700; }
    .badge.warn { background: #fef3c7; color: #92400e; } .badge.ok { background: #d1fae5; color: #065f46; } .badge.bad { background: #fee2e2; color: #991b1b; } .badge.mute { background: #f1f5f9; color: #475569; }
    .keys { display: flex; gap: 6px; margin-top: 8px; page-break-inside: avoid; }
    .keys .k { flex: 1; border: 1px solid #e2e8f0; border-radius: 9px; padding: 6px 8px; background: #f8fafc; font-size: 7pt; }
    .keys .k b { color: #047857; }
    .page-break { page-break-before: always; }
    h2 { font-size: 11pt; font-weight: 800; color: #0f766e; margin: 10px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #10b981; page-break-after: avoid; }
    h2 .hint { font-size: 7pt; color: #64748b; font-weight: 500; margin-left: 6px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: #0f766e; color: #fff; font-size: 6.8pt; text-transform: uppercase; letter-spacing: .4px; padding: 4px 6px; text-align: left; }
    td { padding: 3px 6px; border-bottom: 1px solid #eef2f7; font-size: 7.4pt; vertical-align: top; }
    td.n, th.n { text-align: right; white-space: nowrap; }
    tr.sec td { background: #ecfdf5; font-weight: 800; color: #0f766e; }
    tr.tot td { font-weight: 800; border-top: 1.5px solid #0f766e; background: #f8fafc; }
    tr.res td { background: #0f766e; color: #fff; font-weight: 800; font-size: 8.4pt; }
    tr.res.l2 td { background: #0e7490; } tr.res.l3 td { background: #0b4f4a; } tr.res.l6 td { background: #065f46; }
    tr.muted td { color: #94a3b8; font-style: italic; }
    .grid2 { display: flex; gap: 10px; }
    .grid2 > div { flex: 1; min-width: 0; }
    .bar-row { display: flex; align-items: center; gap: 6px; margin: 2px 0; page-break-inside: avoid; }
    .bar-row .l { width: 42%; font-size: 7pt; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bar-row .b { flex: 1; height: 8px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
    .bar-row .b span { display: block; height: 100%; background: linear-gradient(90deg, #10b981, #06b6d4); border-radius: 99px; }
    .bar-row .v { width: 82px; text-align: right; font-size: 7pt; font-weight: 700; }
    .card { border: 1px solid #e2e8f0; border-radius: 9px; padding: 8px 10px; page-break-inside: avoid; margin-bottom: 8px; }
    .kpis { font-size: 0; }
    .kpis .kpi { display: inline-block; width: 24%; margin: 0 1% 6px 0; vertical-align: top; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 8px; font-size: 8pt; page-break-inside: avoid; }
    .kpis .kpi .l { font-size: 6.3pt; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: .4px; }
    .kpis .kpi .v { font-size: 11pt; font-weight: 800; color: #047857; margin-top: 2px; }
    .kpis .kpi .v.neg { color: #b91c1c; }
    .note { font-size: 6.8pt; color: #64748b; margin-top: 4px; }
    .hash { font-family: monospace; font-size: 6.5pt; color: #475569; word-break: break-all; }
</style>
</head>
<body>

{{-- ═══════════════ PÁGINA 1 — ARQUITECTURA DEL RESULTADO ═══════════════ --}}
<div class="brand">
    @if($logo)<img src="{{ $logo }}" alt="MR LANA">@else<b style="font-size:16pt;color:#0f766e">MR LANA</b>@endif
    <div class="meta">
        <b>{{ $m['scope']['label'] }}</b> · {{ $m['period']['label'] }}<br>
        Periodo {{ $m['period']['code'] }} ({{ \Carbon\Carbon::parse($m['period']['start'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($m['period']['end'])->format('d/m/Y') }}) · Cifras en MXN<br>
        Generado {{ $m['generated_at'] }}
    </div>
</div>
<div class="hero">
    <h1><span class="g1">ESTADO DE</span> <span class="g2">RESULTADOS</span></h1>
    <div class="sub">Tres niveles de EBITDA: sucursales → corporativo → final</div>
</div>

@foreach($steps as $s)
    @php $block = isset($s['key']) ? $resultBlock($s['key']) : null; @endphp
    <div class="step {{ $s['kind'] }}">
        <div class="num">{{ $s['n'] }}</div>
        <div class="body">
            <div class="t">{{ $s['title'] }}
                @if($block && !$block['complete'])<span class="badge warn">Preliminar</span>@endif
                @if(($s['key'] ?? null) === 'ebitda_sucursales')<span class="badge {{ $rec['ok'] ? 'ok' : 'warn' }}">{{ $rec['ok'] ? 'Cuadra con Radiografía' : 'Diferencia explicada' }}</span>@endif
            </div>
            <div class="s">{{ $s['sub'] }}@if($block && !$block['complete']) · Falta: {{ implode(', ', $block['missing']) }}@endif</div>
        </div>
        <div class="amt {{ (float) $s['amount'] < 0 ? 'neg' : '' }}">{{ $money($s['amount']) }}</div>
    </div>
@endforeach

<div class="keys">
    <div class="k"><b>OPEX</b> sí afecta EBITDA.</div>
    <div class="k"><b>CAPEX</b> no afecta EBITDA al comprarse.</div>
    <div class="k"><b>D&amp;A</b> afectan EBIT.</div>
    <div class="k"><b>Intereses</b> afectan EBT; el capital de deuda no entra.</div>
    <div class="k"><b>Movimientos personales</b> y traspasos quedan fuera del ER.</div>
</div>
@if($c['pendientes']['count'] > 0)
    <p class="note" style="color:#92400e;margin-top:6px">⚠ {{ $c['pendientes']['count'] }} movimiento(s) por {{ $money($c['pendientes']['amount']) }} requieren revisión y NO están sumados. Las cifras pueden cambiar al clasificarlos.</p>
@endif

{{-- ═══════════════ PÁGINA 2 — ESTADO DETALLADO ═══════════════ --}}
<div class="page-break"></div>
<h2>Estado de Resultados detallado <span class="hint">importe y % sobre ingresos operativos</span></h2>
<table>
    <thead><tr><th>Concepto</th><th class="n">Importe</th><th class="n">% ingresos</th></tr></thead>
    <tbody>
    @foreach($d['statement'] as $b)
        @if($b['type'] === 'section')
            <tr class="sec"><td colspan="3">{{ $b['number'] }} · {{ $b['title'] }}</td></tr>
            @if(!$b['applies'])
                <tr class="muted"><td colspan="3">{{ $b['note'] }}</td></tr>
            @else
                @foreach($b['lines'] as $l)
                    <tr class="{{ $l['state'] === 'sin_informacion' ? 'muted' : '' }}">
                        <td>&nbsp;&nbsp;{{ $l['label'] }} @if($l['state'] === 'sin_informacion')<span class="badge warn">Sin información</span>@endif</td>
                        <td class="n">{{ $l['state'] === 'sin_informacion' ? '—' : $money($l['amount']) }}</td>
                        <td class="n">{{ $pct($l['pct']) }}</td>
                    </tr>
                @endforeach
                <tr class="tot"><td>{{ $b['total']['label'] }}</td><td class="n">{{ $money($b['total']['amount']) }}</td><td class="n">{{ $pct($b['total']['pct']) }}</td></tr>
            @endif
        @else
            <tr class="res l{{ $b['level'] }}"><td>= {{ $b['label'] }} @if(!$b['complete'])(preliminar)@endif <span style="font-weight:500;font-size:6.6pt">· {{ $b['formula'] }}</span></td><td class="n">{{ $money($b['amount']) }}</td><td class="n">{{ $pct($b['pct']) }}</td></tr>
        @endif
    @endforeach
    </tbody>
</table>

{{-- ═══════════════ COMPOSICIÓN ═══════════════ --}}
<div class="page-break"></div>
<div class="grid2">
    <div>
        <h2>Composición de ingresos</h2>
        <div class="card">
            @foreach($bars($section('ingresos')['lines']) as $l)
                <div class="bar-row"><div class="l">{{ $l['label'] }}</div><div class="b"><span style="width: {{ $l['w'] }}%"></span></div><div class="v">{{ $money($l['amount']) }}</div></div>
            @endforeach
        </div>
    </div>
    <div>
        <h2>Composición OPEX sucursales</h2>
        <div class="card">
            @foreach(array_slice($bars($section('opex_sucursales')['lines']), 0, 14) as $l)
                <div class="bar-row"><div class="l">{{ $l['label'] }}</div><div class="b"><span style="width: {{ $l['w'] }}%"></span></div><div class="v">{{ $money($l['amount']) }}</div></div>
            @endforeach
            @if(count($section('opex_sucursales')['lines']) > 14)<p class="note">+ {{ count($section('opex_sucursales')['lines']) - 14 }} conceptos más (ver Excel).</p>@endif
        </div>
    </div>
</div>
<div class="grid2">
    <div>
        <h2>Nómina y Capital Humano</h2>
        <div class="card">
            @foreach($bars(array_merge($section('nomina_sucursales')['lines'], $consolidated ? $section('nomina_corporativa')['lines'] : [])) as $l)
                <div class="bar-row"><div class="l">{{ $l['label'] }}</div><div class="b"><span style="width: {{ $l['w'] }}%"></span></div><div class="v">{{ $money($l['amount']) }}</div></div>
            @endforeach
        </div>
    </div>
    <div>
        <h2>OPEX Corporativo y DG</h2>
        <div class="card">
            @if($consolidated)
                @forelse($bars(array_merge($section('opex_corporativo')['lines'], $section('dg')['lines'])) as $l)
                    <div class="bar-row"><div class="l">{{ $l['label'] }}</div><div class="b"><span style="width: {{ $l['w'] }}%"></span></div><div class="v">{{ $money($l['amount']) }}</div></div>
                @empty
                    <p class="note">Sin gastos corporativos clasificados.</p>
                @endforelse
            @else
                <p class="note">Corporativo y DG sólo se presentan en el consolidado.</p>
            @endif
        </div>
    </div>
</div>

<h2>Indicadores</h2>
<div class="kpis">
    @foreach($d['indicators']['financial'] as $i)
        @if($i['value'] !== null)
            <div class="kpi"><div class="l">{{ $i['label'] }}</div><div class="v {{ (float) $i['value'] < 0 ? 'neg' : '' }}">{{ $pct($i['value']) }}</div></div>
        @endif
    @endforeach
</div>
<div class="kpis">
    @foreach($d['indicators']['informative'] as $i)
        <div class="kpi"><div class="l">{{ $i['label'] }}</div><div class="v" style="color:#0f172a">{{ $i['value'] === null ? 'Sin información' : ($i['unit'] === 'MXN' ? $money($i['value']) : ($i['unit'] === '%' ? $pct($i['value']) : $i['value'])) }}</div></div>
    @endforeach
</div>
<p class="note">Cartera, mora, colocación, recuperación, plantilla y rotación son informativos: no entran a la fórmula del Estado de Resultados.</p>

{{-- ═══════════════ CONCILIACIÓN ═══════════════ --}}
<div class="page-break"></div>
<h2>Conciliación con Radiografía <span class="hint">EBITDA sucursales</span></h2>
<div class="card">
    <table>
        <thead><tr><th>Componente</th><th class="n">Estado de Resultados</th><th class="n">Radiografía</th></tr></thead>
        <tbody>
        @foreach($rec['components'] as $row)
            <tr><td>{{ $row['label'] }}</td><td class="n">{{ $money($row['er']) }}</td><td class="n">{{ $money($row['radiografia']) }}</td></tr>
        @endforeach
        <tr class="tot"><td>EBITDA sucursales</td><td class="n">{{ $money($rec['er_ebitda_sucursales']) }}</td><td class="n">{{ $money($rec['radiografia_ebitda']) }}</td></tr>
        <tr><td><b>Diferencia</b></td><td class="n" colspan="2"><span class="badge {{ $rec['ok'] ? 'ok' : 'warn' }}">{{ $money($rec['difference']) }}</span></td></tr>
        @foreach($rec['explanations'] as $e)
            <tr><td>· {{ $e['label'] }}</td><td class="n" colspan="2">{{ $money($e['amount']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @foreach($rec['informative'] as $txt)<p class="note">{{ $txt }}</p>@endforeach
</div>

<h2>Conciliación de estados de cuenta</h2>
<div class="kpis">
    @foreach([
        ['Movimientos leídos', $c['movimientos_leidos'], null],
        ['Cargos en periodo', null, $c['total_cargos']],
        ['Abonos en periodo', null, $c['total_abonos']],
        ['Conciliados con ERP', $c['conciliados_erp']['count'], $c['conciliados_erp']['amount']],
        ['Nuevos (sin ERP)', $c['nuevos']['count'], $c['nuevos']['amount']],
        ['Transferencias', $c['transferencias']['count'], $c['transferencias']['amount']],
        ['Pendientes de revisión', $c['pendientes']['count'], $c['pendientes']['amount']],
        ['Incorporado al ER (bancos)', null, $c['incorporado_bancos']],
    ] as [$l, $n, $a])
        <div class="kpi"><div class="l">{{ $l }}</div><div class="v" style="color:#0f172a">{{ $a !== null ? $money($a) : number_format($n) }}</div>@if($a !== null && $n !== null)<div class="note" style="margin:0">{{ number_format($n) }} mov.</div>@endif</div>
    @endforeach
</div>

<h2>Movimientos fuera del Estado de Resultados</h2>
<table>
    <thead><tr><th>Tratamiento</th><th class="n">Mov.</th><th class="n">Cargos</th><th class="n">Abonos</th><th>Por qué no afecta el resultado</th></tr></thead>
    <tbody>
    @foreach($d['outside']['groups'] as $g)
        <tr><td><b>{{ $g['label'] }}</b></td><td class="n">{{ $g['count'] }}</td><td class="n">{{ $money($g['cargos']) }}</td><td class="n">{{ $money($g['abonos']) }}</td><td>{{ $g['explain'] }}</td></tr>
    @endforeach
    @foreach($d['outside']['manual_flows'] as $f)
        <tr><td><b>{{ $f['label'] }}</b> (ajuste)</td><td class="n">—</td><td class="n">{{ $f['state'] === 'sin_informacion' ? 'Sin información' : $money($f['amount']) }}</td><td class="n">—</td><td>{{ $f['effect'] }}</td></tr>
    @endforeach
    </tbody>
</table>

{{-- ═══════════════ NOTAS DE FUENTE Y CONTROL ═══════════════ --}}
<h2>Notas de fuente y control</h2>
<div class="card">
    <table>
        <tbody>
        @foreach([$d['sources']['radiografia'], $d['sources']['erp'], $d['sources']['nomina']] as $s)
            <tr><td style="width:32%"><b>{{ $s['label'] }}</b></td><td>{{ $s['detail'] }}</td></tr>
        @endforeach
        @foreach($d['sources']['cuentas'] as $cta)
            <tr><td><b>{{ $cta['label'] }}</b></td><td>{{ $cta['upload'] ? $cta['upload']['name'].' · '.$cta['upload']['movements'].' movimientos · control '.($cta['upload']['control_ok'] === null ? 'sin totales' : ($cta['upload']['control_ok'] ? 'cuadra con el banco' : 'NO cuadra')) : 'Sin archivo cargado' }}</td></tr>
        @endforeach
        <tr><td><b>{{ $d['sources']['ajustes']['label'] }}</b></td><td>{{ $d['sources']['ajustes']['detail'] }}</td></tr>
        <tr><td><b>Validaciones matemáticas</b></td><td>{{ count($d['checks']) - $failedChecks->count() }} de {{ count($d['checks']) }} en $0.00 @if($failedChecks->count()) · <span class="badge bad">Revisar: {{ $failedChecks->pluck('label')->implode('; ') }}</span>@endif</td></tr>
        </tbody>
    </table>
    <p class="note">Hash del dataset (idéntico en web, Excel y PDF):</p>
    <p class="hash">{{ $m['dataset_hash'] }}</p>
    <p class="note">Reglas {{ $m['rules_version'] }} · tolerancia de fechas en conciliación: {{ $m['tolerance_days'] }} día(s).</p>
</div>
</body>
</html>

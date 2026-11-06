@php
/**
 * PDF ejecutivo del Estado de Resultados (modelo de EBITDA único).
 * $x   = EstadoResultadosExportContext::make() — el mismo dataset que la web (ya redactado).
 * $svg = gráficas SVG generadas en el servidor con las series de ComparisonService (las de la web).
 * Esta vista NO recalcula cifras: sólo da formato.
 */
use App\Services\EstadoResultados\Export\ErFormat as F;

$money = fn ($v, $null = 'Pendiente') => F::money($v, $null);
$neg = fn ($v) => $v !== null && (float) $v < 0 ? 'neg' : '';
$pct = fn ($v) => F::pct($v);
$logoPath = public_path('logo-mr-lana.png');
$logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
$m = $x['meta'];
$rep = $x['report'];
$r = $x['results'];
$cmp = $x['comparisons'];
$prev = $cmp['pairs']['anterior'] ?? null;
$prevRow = fn (string $k) => $prev ? collect($prev['rows'])->firstWhere('key', $k) : null;
$assess = fn (?string $a) => match ($a) { 'favorable' => 'ok', 'desfavorable' => 'bad', 'neutral' => 'mute', default => '' };
$period = $m['period'];
$fmtDate = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—';
// Fórmula con importes (como el ejemplo práctico): «$1,000,000.00 − $600,000.00 =».
$formulaWith = [
    'ebitda' => [$r['ingresos_operativos'], '−', $r['opex_total']],
    'ebit' => [$r['ebitda'], '−', $r['depreciacion_amortizacion']],
    'ebt' => [$r['ebit'], '+', $r['resultado_financiero_neto']],
    'utilidad_neta' => [$r['ebt'], '−', $r['isr']],
];
$numbered = ['ingresos_operativos' => 1, 'opex_total' => 2, 'depreciacion_amortizacion' => 3, 'resultado_financiero_neto' => 4, 'isr' => 5];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Estado de Resultados — {{ $period['label'] }}</title>
<script>window.__PDF_READY__ = true;</script>
<style>
    @include('reports.partials.montserrat-font-face')
    * { box-sizing: border-box; margin: 0; padding: 0; }
    @page { size: Letter portrait; }
    html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'Montserrat', Helvetica, Arial, sans-serif; color: #0f172a; font-size: 8.2pt; background: #fff; }
    .neg { color: #b91c1c !important; }
    .muted { color: #64748b; }
    .page-break { break-before: page; page-break-before: always; }
    .avoid { break-inside: avoid; page-break-inside: avoid; }

    /* Portada */
    .cover { height: 228mm; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; padding-top: 22mm; text-align: center; position: relative; overflow: hidden; }
    .cover .band { position: absolute; left: 0; right: 0; top: 0; height: 8mm; background: linear-gradient(90deg, #10b981, #06b6d4); border-radius: 6px; }
    .cover img { width: 80mm; margin-bottom: 10mm; }
    .cover h1 { font-size: 34pt; font-weight: 800; letter-spacing: -1px; line-height: 1.02; }
    .cover h1 .g1 { color: #059669; } .cover h1 .g2 { color: #0891b2; }
    .cover .period { font-size: 17pt; font-weight: 800; margin-top: 8mm; color: #0f172a; }
    .cover .scope { font-size: 12pt; font-weight: 600; color: #0f766e; margin-top: 2mm; }
    .cover .status { margin-top: 9mm; display: inline-block; padding: 6px 18px; border-radius: 99px; font-weight: 800; font-size: 12pt; letter-spacing: 1px; }
    .status.ok { background: #d1fae5; color: #065f46; border: 2px solid #10b981; }
    .status.warn { background: #fef3c7; color: #92400e; border: 2px solid #f59e0b; }
    .status.bad { background: #fee2e2; color: #991b1b; border: 2px solid #dc2626; }
    .status.mute { background: #f1f5f9; color: #475569; border: 2px solid #94a3b8; }
    .cover .facts { margin-top: 9mm; font-size: 9pt; color: #334155; line-height: 1.7; }
    .cover .facts b { color: #0f172a; }
    .cover .hash { font-family: monospace; font-size: 7.5pt; color: #64748b; margin-top: 6mm; }
    .cover .reasons { margin-top: 6mm; max-width: 150mm; text-align: left; border: 2px solid #b91c1c; background: #fef2f2; color: #991b1b; border-radius: 10px; padding: 8px 12px; font-size: 8pt; }
    .legacy { border: 1.5px solid #f59e0b; background: #fffbeb; color: #92400e; border-radius: 9px; padding: 6px 10px; font-size: 7.6pt; margin-bottom: 8px; }

    /* Encabezado de sección */
    .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #10b981; padding-bottom: 6px; margin-bottom: 9px; }
    .head img { height: 30px; }
    .head .meta { text-align: right; font-size: 7pt; color: #475569; line-height: 1.45; }
    .head .meta b { color: #0f172a; }
    h2 { font-size: 13pt; font-weight: 800; color: #0f172a; margin: 2px 0 8px; break-after: avoid; }
    h2 .g { color: #0f766e; }
    h3 { font-size: 9.6pt; font-weight: 800; color: #0f766e; margin: 10px 0 5px; padding-bottom: 2px; border-bottom: 1.5px solid #ccfbf1; break-after: avoid; }
    h3 .hint { font-size: 7pt; color: #64748b; font-weight: 500; margin-left: 6px; }
    .note { font-size: 6.9pt; color: #64748b; margin-top: 4px; line-height: 1.4; }
    .warnnote { font-size: 7pt; color: #92400e; margin: 4px 0; }

    /* KPI */
    .kpis { display: flex; gap: 6px; margin-bottom: 6px; }
    .kpi { flex: 1; border: 1px solid #e2e8f0; border-radius: 10px; padding: 7px 9px; background: #fff; break-inside: avoid; }
    .kpi.main { background: linear-gradient(135deg, #f0fdfa, #ecfeff); border-color: #99f6e4; }
    .kpi .l { font-size: 6.6pt; color: #475569; text-transform: uppercase; font-weight: 700; letter-spacing: .4px; }
    .kpi .v { font-size: 13.5pt; font-weight: 800; color: #047857; margin-top: 2px; white-space: nowrap; }
    .kpi.small .v { font-size: 10pt; color: #0f172a; }
    .kpi .s { font-size: 6.8pt; color: #475569; margin-top: 2px; }
    .kpi .pend { color: #b45309; }

    /* Pasos del resultado */
    .step { display: flex; align-items: stretch; gap: 7px; margin-bottom: 4px; break-inside: avoid; }
    .step .num { width: 28px; border-radius: 8px; background: linear-gradient(135deg, #22c55e, #059669); color: #fff; font-weight: 800; font-size: 12pt; display: flex; align-items: center; justify-content: center; }
    .step .body { flex: 1; border: 1px solid #e2e8f0; border-radius: 9px; padding: 5px 10px; background: #fff; }
    .step .body .t { font-weight: 800; font-size: 9.2pt; }
    .step .body .s { color: #64748b; font-size: 6.9pt; margin-top: 1px; }
    .step .amt { width: 150px; border-radius: 9px; background: #f0fdfa; border: 1px solid #ccfbf1; display: flex; align-items: center; justify-content: flex-end; padding: 0 11px; font-weight: 800; font-size: 10.5pt; white-space: nowrap; }
    .step.result .num { background: #0b3b36; }
    .step.result .body { background: linear-gradient(90deg, #0f766e, #06b6d4); border: none; color: #fff; }
    .step.result .body .s { color: #ccfbf1; }
    .step.result .amt { background: #fff; border: 2px solid #06b6d4; color: #047857; font-size: 12pt; }
    .step.final .body { background: linear-gradient(90deg, #065f46, #10b981 60%, #06b6d4); }
    .step .amt.pend { color: #b45309 !important; background: #fffbeb; border-color: #f59e0b; }
    .badge { display: inline-block; padding: 1px 6px; border-radius: 99px; font-size: 6.3pt; font-weight: 700; vertical-align: middle; }
    .badge.warn { background: #fef3c7; color: #92400e; } .badge.ok { background: #d1fae5; color: #065f46; } .badge.bad { background: #fee2e2; color: #991b1b; } .badge.mute { background: #f1f5f9; color: #475569; }
    .keys { display: flex; gap: 6px; margin-top: 6px; break-inside: avoid; }
    .keys .k { flex: 1; border: 1px solid #e2e8f0; border-radius: 9px; padding: 5px 7px; background: #f8fafc; font-size: 6.8pt; }
    .keys .k b { color: #047857; }

    /* Tablas */
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr, .card, .chart { break-inside: avoid; page-break-inside: avoid; }
    th { background: #0f766e; color: #fff; font-size: 6.7pt; text-transform: uppercase; letter-spacing: .3px; padding: 4px 6px; text-align: left; }
    td { padding: 2.6px 6px; border-bottom: 1px solid #eef2f7; font-size: 7.3pt; vertical-align: top; }
    td.n, th.n { text-align: right; white-space: nowrap; }
    tr.sec td { background: #ecfdf5; font-weight: 800; color: #0f766e; border-top: 1px solid #a7f3d0; }
    tr.cat td { font-weight: 700; color: #0e7490; background: #f8fafc; }
    tr.sub td { font-weight: 700; border-top: 1px solid #cbd5e1; }
    tr.tot td { font-weight: 800; border-top: 1.5px solid #0f766e; background: #f0fdfa; }
    tr.res td { background: #0f766e; color: #fff; font-weight: 800; font-size: 8.2pt; }
    tr.res.final td { background: #065f46; }
    tr.res td .f { font-weight: 500; font-size: 6.4pt; color: #ccfbf1; }
    tr.res td.neg { color: #fecaca !important; }
    tr.dim td { color: #94a3b8; font-style: italic; }
    td.l1 { padding-left: 14px; } td.l2 { padding-left: 24px; }
    .card { border: 1px solid #e2e8f0; border-radius: 9px; padding: 7px 9px; margin-bottom: 8px; }
    .chart { border: 1px solid #e2e8f0; border-radius: 10px; padding: 7px 9px 4px; margin-bottom: 8px; }
    .chart .ct { font-size: 8.4pt; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
    .chart .cs { font-size: 6.6pt; color: #64748b; margin-bottom: 3px; }
    .chart svg { display: block; }
    .grid2 { display: flex; gap: 8px; } .grid2 > div { flex: 1; min-width: 0; }
    .ev.ok { color: #047857; font-weight: 700; } .ev.bad { color: #b91c1c; font-weight: 700; } .ev.mute { color: #64748b; }
    .hashline { font-family: monospace; font-size: 6.6pt; color: #475569; word-break: break-all; }
    .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 92pt; font-weight: 800; color: #b91c1c; opacity: .07; transform: rotate(-28deg); z-index: 0; pointer-events: none; }
</style>
</head>
<body>
@if($rep['preliminary'])<div class="watermark">PRELIMINAR</div>@endif

{{-- ═══════════════ PORTADA ═══════════════ --}}
<section class="cover">
    <div class="band"></div>
    @if($logo)<img src="{{ $logo }}" alt="MR LANA">@else<div style="font-size:30pt;font-weight:800;color:#0f766e;margin-bottom:14mm">MR LANA</div>@endif
    <h1><span class="g1">ESTADO DE</span><br><span class="g2">RESULTADOS</span></h1>
    <div class="period">{{ $period['label'] }}</div>
    <div class="scope">{{ $m['scope']['consolidated'] ? 'Alcance: General (consolidado MR LANA)' : 'Alcance: '.$m['scope']['label'] }}</div>
    <div class="status {{ $rep['tone'] }}">{{ $rep['display_status'] }}</div>
    <div class="facts">
        Periodo {{ $period['code'] }} · del {{ $fmtDate($period['start']) }} al {{ $fmtDate($period['end']) }} · cifras en MXN<br>
        <b>Versión {{ $rep['version'] }}</b> · Estado: {{ $rep['status_label'] }}@if($rep['approved_at']) · aprobada por <b>{{ $rep['approved_by'] ?? '—' }}</b> el {{ $rep['approved_at'] }}@endif<br>
        Cifras: {{ $rep['source_label'] }} · generadas {{ $m['generated_at'] }} · documento emitido {{ $rep['issued_at'] }}
    </div>
    <div class="hash">Hash del dataset: {{ $m['dataset_hash'] }}</div>
    @if($rep['preliminary'])
        <div class="reasons"><b>Documento preliminar:</b> faltan datos obligatorios o hay partidas por conciliar; las cifras pueden cambiar.
            @foreach(array_slice($rep['preliminary_reasons'], 0, 5) as $reason)<br>· {{ \Illuminate\Support\Str::limit($reason, 150) }}@endforeach
            @if(count($rep['preliminary_reasons']) > 5)<br>· y {{ count($rep['preliminary_reasons']) - 5 }} motivo(s) más: ver «Conciliación y fuentes».@endif
        </div>
    @endif
    @if($x['legacy'])<div class="legacy" style="margin-top:6mm;max-width:150mm;text-align:left">{{ $x['legacy_notice'] }}</div>@endif
</section>

@php
$head = function () use ($logo, $m, $rep, $period) {
    return '<div class="head">'.($logo ? '<img src="'.$logo.'" alt="MR LANA">' : '<b style="font-size:13pt;color:#0f766e">MR LANA</b>')
        .'<div class="meta"><b>'.e($m['scope']['label']).'</b> · '.e($period['label']).' · '.e($period['code']).'<br>Versión '.e($rep['version']).' · '.e($rep['status_label'])
        .($rep['preliminary'] ? ' · <b style="color:#b91c1c">'.e($rep['display_status']).'</b>' : '').' · dataset '.e($m['hash_short']).'</div></div>';
};
@endphp

{{-- ═══════════════ RESUMEN EJECUTIVO ═══════════════ --}}
<section class="page-break">
    {!! $head() !!}
    <h2><span class="g">Resumen ejecutivo</span></h2>
    @if($x['legacy'])<div class="legacy">{{ $x['legacy_notice'] }}</div>@endif
    @if(!empty($x['redaction_message']))<p class="warnnote">{{ $x['redaction_message'] }}</p>@endif
    <div class="kpis">
        @foreach(['ebitda' => 'ebitda', 'ebit' => 'ebit', 'ebt' => 'ebt', 'utilidad_neta' => 'neto'] as $k => $mk)
            @php $row = $prevRow($k); $step = collect($x['steps'])->firstWhere('key', $k); @endphp
            <div class="kpi main">
                <div class="l">{{ ['ebitda' => 'EBITDA', 'ebit' => 'EBIT', 'ebt' => 'EBT', 'utilidad_neta' => 'Utilidad neta'][$k] }}</div>
                <div class="v {{ $neg($r[$k]) }} {{ $r[$k] === null ? 'pend' : '' }}">{{ $money($r[$k]) }}</div>
                <div class="s">Margen {{ $pct($x['margins'][$mk]) }}@if(! $step['complete'] && $r[$k] !== null) · <span class="badge warn">Incompleto</span>@endif</div>
                @if($row && $row['variation'] !== null)
                    <div class="s">vs {{ $prev['comparison_label'] }}: <span class="ev {{ $assess($row['assessment']) }}">{{ F::delta($row['variation']) }}@if($row['variation_pct'] !== null) ({{ F::pct($row['variation_pct'], '—', true) }})@endif</span></div>
                @endif
            </div>
        @endforeach
    </div>
    <div class="kpis">
        @foreach(['ingresos_operativos' => 'Ingresos operativos', 'opex_total' => 'OPEX', 'depreciacion_amortizacion' => 'Depreciación y amortización', 'resultado_financiero_neto' => 'Resultado financiero neto', 'isr' => 'ISR'] as $k => $label)
            <div class="kpi small"><div class="l">{{ $label }}</div><div class="v {{ $neg($r[$k]) }} {{ $r[$k] === null ? 'pend' : '' }}">{{ $money($r[$k]) }}</div><div class="s">{{ $pct(collect($x['steps'])->firstWhere('key', $k)['pct']) }} de ingresos</div></div>
        @endforeach
    </div>

    <h3>Arquitectura del resultado <span class="hint">del ingreso a la utilidad neta</span></h3>
    @foreach($x['steps'] as $s)
        @php
            $isFinal = $s['key'] === 'utilidad_neta';
            $sub = $s['is_result'] && isset($formulaWith[$s['key']]) && $formulaWith[$s['key']][0] !== null && $formulaWith[$s['key']][2] !== null
                ? $money($formulaWith[$s['key']][0]).' '.$formulaWith[$s['key']][1].' '.($s['key'] === 'ebt' && (float) $formulaWith['ebt'][2] < 0 ? '('.$money($formulaWith['ebt'][2]).')' : $money($formulaWith[$s['key']][2])).' ='
                : $s['formula'];
        @endphp
        <div class="step {{ $s['is_result'] ? 'result' : '' }} {{ $isFinal ? 'final' : '' }}">
            <div class="num">{{ $s['is_result'] ? '=' : $numbered[$s['key']] }}</div>
            <div class="body">
                <div class="t">{{ $s['label'] }}
                    @if($s['pending'])<span class="badge warn">Pendiente</span>@elseif(! $s['complete'])<span class="badge warn">Incompleto</span>@endif
                </div>
                <div class="s">{{ $sub }}@if(! $s['complete'] && $s['missing']) · Falta: {{ implode(', ', $s['missing']) }}@endif</div>
            </div>
            <div class="amt {{ $s['pending'] ? 'pend' : $neg($s['amount']) }}">{{ $money($s['amount']) }}</div>
        </div>
    @endforeach
    <div class="keys">
        <div class="k"><b>OPEX</b> sí afecta el EBITDA (incluye nómina, corporativo y Dirección General).</div>
        <div class="k"><b>D&amp;A</b> afecta el EBIT.</div>
        <div class="k"><b>Intereses</b> afectan el EBT; el capital de deuda no entra.</div>
        <div class="k"><b>CAPEX</b>, capital de deuda y gastos personales quedan fuera del Estado de Resultados.</div>
    </div>
    @if(($x['validation']['conciliation']['pendientes']['count'] ?? 0) > 0)
        <p class="warnnote">⚠ {{ $x['validation']['conciliation']['pendientes']['count'] }} movimiento(s) por {{ $money($x['validation']['conciliation']['pendientes']['amount']) }} requieren revisión y no están sumados.</p>
    @endif
</section>

{{-- ═══════════════ ESTADO DE RESULTADOS DETALLADO ═══════════════ --}}
<section class="page-break">
    {!! $head() !!}
    <h2><span class="g">Estado de Resultados</span> detallado <span class="muted" style="font-size:8pt;font-weight:500">importe y % sobre ingresos operativos</span></h2>
    <table>
        <thead><tr><th>Concepto</th><th class="n" style="width:110px">Importe</th><th class="n" style="width:62px">% ingresos</th></tr></thead>
        <tbody>
        @foreach($x['statement'] as $b)
            @if(($b['type'] ?? '') === 'section')
                <tr class="sec"><td colspan="3">{{ $b['number'] ?? '' }} · {{ $b['title'] ?? '' }}</td></tr>
                @if(! ($b['applies'] ?? true))
                    <tr class="dim"><td colspan="3">{{ $b['note'] ?? '' }}</td></tr>
                    @continue
                @endif
                @if(! empty($b['categories']))
                    @foreach($b['categories'] as $cat)
                        <tr class="cat"><td class="l1">{{ $cat['label'] }}@if(in_array($cat['key'], ['opex_corporativo', 'nomina_corporativa', 'dg'], true)) <span class="badge mute">Centralizado</span>@endif</td><td></td><td></td></tr>
                        @forelse($cat['lines'] as $l)
                            <tr class="{{ in_array($l['state'] ?? null, ['sin_informacion', 'restringido'], true) ? 'dim' : '' }}">
                                <td class="l2">{{ $l['label'] }}@if(($l['state'] ?? null) === 'sin_informacion') <span class="badge warn">Sin información</span>@endif</td>
                                <td class="n {{ $neg($l['amount']) }}">{{ ($l['state'] ?? null) === 'sin_informacion' ? '—' : $money($l['amount']) }}</td>
                                <td class="n">{{ $pct($l['pct'] ?? null) }}</td>
                            </tr>
                        @empty
                            <tr class="dim"><td class="l2" colspan="3">Sin gastos en esta categoría.</td></tr>
                        @endforelse
                        <tr class="sub"><td class="l1">{{ $cat['total']['label'] }}</td><td class="n {{ $neg($cat['total']['amount']) }}">{{ $money($cat['total']['amount']) }}</td><td class="n">{{ $pct($cat['total']['pct']) }}</td></tr>
                    @endforeach
                @else
                    @foreach($b['lines'] as $l)
                        <tr class="{{ in_array($l['state'] ?? null, ['sin_informacion', 'restringido'], true) ? 'dim' : '' }}">
                            <td class="l1">{{ $l['label'] }}@if(($l['state'] ?? null) === 'sin_informacion') <span class="badge warn">Sin información</span>@endif</td>
                            <td class="n {{ $neg($l['amount']) }}">{{ ($l['state'] ?? null) === 'sin_informacion' ? '—' : $money($l['amount']) }}</td>
                            <td class="n">{{ $pct($l['pct'] ?? null) }}</td>
                        </tr>
                    @endforeach
                    @foreach($b['subtotals'] ?? [] as $st)
                        <tr class="dim"><td class="l1">Subtotal: {{ $st['label'] }}</td><td class="n">{{ $money($st['amount']) }}</td><td></td></tr>
                    @endforeach
                @endif
                @php
                    $unknown = collect($b['lines'] ?? [])->contains(fn ($l) => ($l['state'] ?? null) === 'sin_informacion');
                    $total = $b['total']['amount'] ?? null;
                    $totalText = $total === null ? 'Pendiente' : (($unknown && (float) $total == 0.0) ? 'Sin información' : $money($total));
                @endphp
                <tr class="tot"><td>{{ $b['total']['label'] ?? 'Total' }}@if($unknown && $total !== null && (float) $total != 0.0) <span class="badge warn">Incompleto</span>@endif</td><td class="n {{ $neg($total) }}">{{ $totalText }}</td><td class="n">{{ $pct($b['total']['pct'] ?? null) }}</td></tr>
                @if(! empty($b['note']) && ($b['applies'] ?? true))<tr class="dim"><td colspan="3">{{ $b['note'] }}</td></tr>@endif
            @else
                <tr class="res {{ ($b['key'] ?? '') === 'utilidad_neta' ? 'final' : '' }}">
                    <td>= {{ $b['label'] }} <span class="f">· {{ $b['formula'] ?? '' }}@if(! ($b['complete'] ?? true) && ($b['amount'] ?? null) !== null) · incompleto, falta: {{ implode(', ', $b['missing'] ?? []) }}@endif</span></td>
                    <td class="n {{ $neg($b['amount'] ?? null) }}">{{ $money($b['amount'] ?? null) }}</td>
                    <td class="n">{{ $pct($b['pct'] ?? null) }}</td>
                </tr>
                @if(! empty($b['note']))<tr class="dim"><td colspan="3">{{ $b['note'] }}</td></tr>@endif
            @endif
        @endforeach
        </tbody>
    </table>
    <p class="note">«Pendiente» = sin información (nunca se asume $0.00). «Sin información» = ajuste contable no capturado; el resultado que depende de él se marca incompleto.</p>
</section>

{{-- ═══════════════ COMPARATIVAS ═══════════════ --}}
<section class="page-break">
    {!! $head() !!}
    <h2><span class="g">Comparativas</span></h2>
    @if(! $cmp['available'])
        <div class="card"><p class="warnnote">{{ $cmp['error'] }}</p></div>
    @else
        @if($cmp['notice'])<p class="warnnote">{{ $cmp['notice'] }}</p>@endif
        @foreach(['anterior', 'anio_anterior', 'acumulado'] as $pk)
            @php $p = $cmp['pairs'][$pk] ?? null; @endphp
            @continue(! $p)
            <div class="avoid">
                <h3>{{ $p['title'] }} <span class="hint">{{ $p['current_label'] }} vs {{ $p['comparison_label'] }}@if($p['partial']) · <b style="color:#b45309">acumulado parcial</b>@endif</span></h3>
                @if(! $p['available'])
                    <p class="warnnote">{{ $p['unavailable'] ?? 'Sin datos de comparación.' }}</p>
                @else
                    @if(! $p['comparable'] && $p['reasons'])<p class="warnnote">No comparable: {{ implode(' ', $p['reasons']) }}</p>@endif
                    <table>
                        <thead><tr><th>Concepto</th><th class="n">{{ \Illuminate\Support\Str::limit($p['current_label'], 26) }}</th><th class="n">{{ \Illuminate\Support\Str::limit($p['comparison_label'], 26) }}</th><th class="n">Var $ / pp</th><th class="n">Var %</th><th>Evaluación</th></tr></thead>
                        <tbody>
                        @foreach($p['rows'] as $row)
                            @php $isPct = $row['unit'] === '%'; $isRes = in_array($row['key'], ['ebitda', 'ebit', 'ebt', 'utilidad_neta'], true); @endphp
                            <tr class="{{ $isRes ? 'sub' : '' }}">
                                <td>{{ $row['label'] }}@if($row['reason'] && ! in_array($row['reason']['code'], ['margen_en_puntos'], true)) <span class="muted" style="font-size:6pt">· {{ $row['reason']['message'] }}</span>@endif</td>
                                <td class="n {{ $neg($row['current']) }}">{{ $isPct ? $pct($row['current']) : $money($row['current']) }}</td>
                                <td class="n {{ $neg($row['previous']) }}">{{ $isPct ? $pct($row['previous']) : $money($row['previous'], $p['available'] ? 'Pendiente' : 'Sin datos') }}</td>
                                <td class="n">{{ $isPct ? F::points($row['variation']) : F::delta($row['variation']) }}</td>
                                <td class="n">{{ $isPct ? '—' : F::pct($row['variation_pct'], '—', true) }}</td>
                                <td><span class="ev {{ $assess($row['assessment']) }}">{{ $row['assessment_label'] ?? '—' }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if($p['partial'])<p class="note">Acumulado parcial: incluye sólo los meses con datos del año{{ $p['missing_months'] ? '; faltan '.implode(', ', $p['missing_months']) : '' }}. No representa el año completo.</p>@endif
                @endif
            </div>
        @endforeach
        <p class="note">Var % = (actual − comparación) / |comparación|; no se calcula si la base es $0.00, si algún dato está pendiente o si los periodos no son comparables. Los márgenes se comparan en puntos porcentuales (pp). Favorable/desfavorable considera el tipo de concepto (un gasto que sube es desfavorable).</p>
    @endif
</section>

{{-- ═══════════════ GRÁFICAS ═══════════════ --}}
<section class="page-break">
    {!! $head() !!}
    <h2><span class="g">Gráficas</span></h2>
    <div class="chart"><div class="ct">Evolución de EBITDA, EBIT, EBT y utilidad neta</div><div class="cs">{{ $svgMeta['evolucion'] }}</div>{!! $svg['evolucion'] !!}</div>
    <div class="chart"><div class="ct">Ingresos operativos vs OPEX</div><div class="cs">{{ $svgMeta['evolucion'] }}</div>{!! $svg['ingresos_opex'] !!}</div>
    <div class="chart"><div class="ct">Cascada del resultado · {{ $period['label'] }}</div><div class="cs">Ingresos − OPEX = EBITDA − D&amp;A = EBIT ± resultado financiero = EBT − ISR = utilidad neta. Un paso pendiente no se dibuja como $0.00.</div>{!! $svg['cascada'] !!}</div>
</section>
<section class="page-break">
    {!! $head() !!}
    <div class="chart"><div class="ct">Composición del OPEX · {{ $period['label'] }}</div><div class="cs">Categorías tal como las presenta el Estado de Resultados.</div>{!! $svg['composicion'] !!}</div>
    <div class="chart"><div class="ct">Márgenes EBITDA, EBIT y neto</div><div class="cs">% sobre ingresos operativos · {{ $svgMeta['evolucion'] }}</div>{!! $svg['margenes'] !!}</div>
    @if($m['scope']['consolidated'] && $svg['sucursales'])
        <div class="chart"><div class="ct">EBITDA por sucursal · {{ $period['label'] }}</div><div class="cs">EBITDA de sucursal = ingresos − OPEX directo de la sucursal. Corporativo y Dirección General son centralizados y no se asignan.</div>{!! $svg['sucursales'] !!}</div>
    @endif
</section>

{{-- ═══════════════ SUCURSALES (consolidado) ═══════════════ --}}
@php $br = $cmp['branches'] ?? null; @endphp
@if($m['scope']['consolidated'] && $br && $br['rows'])
<section class="page-break">
    {!! $head() !!}
    <h2><span class="g">Sucursales</span> <span class="muted" style="font-size:8pt;font-weight:500">{{ $br['label'] }}</span></h2>
    <table>
        <thead><tr><th>Sucursal</th><th class="n">Ingresos</th><th class="n">OPEX directo</th><th class="n">EBITDA sucursal</th><th class="n">Margen</th><th class="n">Part. ingresos</th><th class="n">Var EBITDA vs ant.</th></tr></thead>
        <tbody>
        @foreach($br['rows'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="n">{{ $money($row['ingresos_operativos'], 'Sin datos') }}</td>
                <td class="n">{{ $money($row['opex_total'], 'Sin datos') }}</td>
                <td class="n {{ $neg($row['ebitda']) }}">{{ $money($row['ebitda'], 'Sin datos') }}</td>
                <td class="n">{{ $pct($row['margen_ebitda']) }}</td>
                <td class="n">{{ $pct($row['participacion_ingresos']) }}</td>
                <td class="n"><span class="ev {{ $assess($row['variation']['ebitda']['assessment'] ?? null) }}">{{ F::delta($row['variation']['ebitda']['variation'] ?? null) }}</span></td>
            </tr>
        @endforeach
        @if($t = $br['totals']['sucursales'])
            <tr class="tot"><td>{{ $t['label'] }}</td><td class="n">{{ $money($t['ingresos_operativos']) }}</td><td class="n">{{ $money($t['opex_total']) }}</td><td class="n {{ $neg($t['ebitda']) }}">{{ $money($t['ebitda']) }}</td><td class="n">{{ $pct($t['margen_ebitda']) }}</td><td></td><td></td></tr>
        @endif
        </tbody>
    </table>
    @if($c = $br['totals']['centralizados'])
        <h3>Gastos centralizados <span class="hint">Corporativo y Dirección General · no se asignan a sucursales</span></h3>
        <table>
            <thead><tr><th>Categoría</th><th class="n">Importe</th></tr></thead>
            <tbody>
            @foreach($c['categories'] as $cat)<tr><td>{{ $cat['label'] }}@if($cat['restricted']) <span class="badge mute">Detalle restringido</span>@endif</td><td class="n">{{ $money($cat['amount']) }}</td></tr>@endforeach
            <tr class="tot"><td>Total centralizados</td><td class="n">{{ $money($c['total']) }}</td></tr>
            </tbody>
        </table>
    @endif
    @if($k = $br['totals']['conciliacion'])
        <h3>Conciliación al EBITDA consolidado</h3>
        <table>
            <tbody>
            <tr><td>Σ EBITDA de sucursales</td><td class="n">{{ $money($k['suma_ebitda_sucursales']) }}</td></tr>
            <tr><td>− Gastos centralizados</td><td class="n">{{ $money($k['menos_centralizados']) }}</td></tr>
            <tr><td>± Ingresos / gastos no asignados a sucursal</td><td class="n">{{ $money($k['no_asignado_a_sucursal']) }}</td></tr>
            <tr class="tot"><td>= EBITDA consolidado</td><td class="n {{ $neg($k['ebitda_consolidado']) }}">{{ $money($k['ebitda_consolidado']) }}</td></tr>
            </tbody>
        </table>
        <p class="note">{{ $k['note'] }}</p>
    @endif
</section>
@endif

{{-- ═══════════════ CONCILIACIÓN Y FUENTES ═══════════════ --}}
@php $v = $x['validation']; @endphp
<section class="page-break">
    {!! $head() !!}
    <h2><span class="g">Conciliación</span> y fuentes</h2>
    <div class="card">
        <b>Estado de validación:</b> <span class="badge {{ $v['status'] === 'ok' ? 'ok' : ($v['status'] === 'con_pendientes' ? 'warn' : 'bad') }}">{{ $v['status_label'] }}</span>
        · Validaciones matemáticas: {{ $v['checks_ok'] }} de {{ $v['checks_total'] }} en $0.00
        · Partición de movimientos: {{ $v['partition_ok'] ? 'cuadra' : 'con diferencia' }}
        @if($v['failed'])<p class="warnnote">Revisar: @foreach($v['failed'] as $f){{ $f['label'] }} (diferencia {{ $money($f['difference']) }}); @endforeach</p>@endif
        @if($v['unresolved'])
            @if($rep['preliminary'] && $rep['preliminary_reasons'])
                <p class="warnnote" style="margin-top:5px"><b>Motivos del estado preliminar:</b>@foreach($rep['preliminary_reasons'] as $reason)<br>· {{ $reason }}@endforeach</p>
            @endif
            <p class="note" style="margin-top:5px"><b>Pendientes por resolver:</b>
                @foreach($v['unresolved'] as $u)<br>· {{ $u['label'] }}: {{ $u['count'] }} · {{ $money($u['amount']) }}@if(! $u['blocking']) (informativo)@endif @endforeach
            </p>
        @endif
    </div>

    @if($rad = $v['radiography'])
        <h3>Conciliación con Radiografía <span class="hint">EBITDA de sucursales (control)</span></h3>
        <table>
            <thead><tr><th>Componente</th><th class="n">Estado de Resultados</th><th class="n">Radiografía</th></tr></thead>
            <tbody>
            @foreach($rad['components'] as $row)<tr><td>{{ $row['label'] }}</td><td class="n">{{ $money($row['er']) }}</td><td class="n">{{ $money($row['radiografia']) }}</td></tr>@endforeach
            <tr class="tot"><td>EBITDA de sucursales</td><td class="n">{{ $money($rad['er']) }}</td><td class="n">{{ $money($rad['radiografia']) }}</td></tr>
            <tr><td><b>Diferencia</b></td><td class="n" colspan="2"><span class="badge {{ $rad['ok'] ? 'ok' : 'warn' }}">{{ $money($rad['difference']) }}</span></td></tr>
            @foreach($rad['explanations'] as $e)<tr><td>· {{ $e['label'] }}</td><td class="n" colspan="2">{{ $money($e['amount']) }}</td></tr>@endforeach
            </tbody>
        </table>
        @foreach($rad['informative'] as $txt)<p class="note">{{ $txt }}</p>@endforeach
    @endif

    <h3>Fuentes del periodo</h3>
    <table>
        <thead><tr><th style="width:26%">Fuente</th><th style="width:14%">Estado</th><th style="width:16%">Periodo</th><th>Detalle</th></tr></thead>
        <tbody>
        @foreach($x['sources'] as $s)
            <tr><td><b>{{ $s['label'] }}</b></td><td><span class="badge {{ $s['status'] === 'procesado' ? 'ok' : ($s['status'] === 'pendiente' ? 'mute' : 'warn') }}">{{ $s['status_label'] }}</span></td><td>{{ $s['period'] }}</td><td>{{ $s['detail'] }}</td></tr>
        @endforeach
        </tbody>
    </table>

    @php $c = $v['conciliation']; @endphp
    @if($c)
        <h3>Conciliación de estados de cuenta</h3>
        <table>
            <tbody>
            <tr><td>Movimientos leídos</td><td class="n">{{ number_format((int) ($c['movimientos_leidos'] ?? 0)) }}</td><td>Conciliados con ERP</td><td class="n">{{ number_format((int) ($c['conciliados_erp']['count'] ?? 0)) }} · {{ $money($c['conciliados_erp']['amount'] ?? null) }}</td></tr>
            <tr><td>Cargos del periodo</td><td class="n">{{ $money($c['total_cargos'] ?? null) }}</td><td>Nuevos (sin ERP)</td><td class="n">{{ number_format((int) ($c['nuevos']['count'] ?? 0)) }} · {{ $money($c['nuevos']['amount'] ?? null) }}</td></tr>
            <tr><td>Abonos del periodo</td><td class="n">{{ $money($c['total_abonos'] ?? null) }}</td><td>Transferencias internas</td><td class="n">{{ number_format((int) ($c['transferencias']['count'] ?? 0)) }} · {{ $money($c['transferencias']['amount'] ?? null) }}</td></tr>
            <tr><td>Incorporado al ER desde bancos</td><td class="n">{{ $money($c['incorporado_bancos'] ?? null) }}</td><td>Pendientes de revisión (no suman)</td><td class="n">{{ number_format((int) ($c['pendientes']['count'] ?? 0)) }} · {{ $money($c['pendientes']['amount'] ?? null) }}</td></tr>
            </tbody>
        </table>
    @endif

    <h3>Trazabilidad</h3>
    <div class="card">
        <p class="note"><b>Versión {{ $rep['version'] }}</b> · {{ $rep['status_label'] }}@if($rep['approved_at']) · aprobada por {{ $rep['approved_by'] ?? '—' }} el {{ $rep['approved_at'] }}@endif · {{ $rep['source_label'] }} · emitido {{ $rep['issued_at'] }}</p>
        @if($rep['version_id'])<p class="note">Identificador de la versión aprobada: {{ $rep['version_id'] }}</p>@endif
        <p class="note">Hash del dataset (idéntico en web, Excel y PDF):</p>
        <p class="hashline">{{ $m['dataset_hash'] }}</p>
        @if($rep['snapshot_hash'])<p class="note">Hash del snapshot aprobado:</p><p class="hashline">{{ $rep['snapshot_hash'] }}</p>@endif
        <p class="note">Reglas {{ $rep['rules_version'] }}@if($x['model_version']) · modelo {{ $x['model_version'] }}@endif @if($m['tolerance_days'] !== null)· tolerancia de fechas en conciliación: {{ $m['tolerance_days'] }} día(s)@endif</p>
        <p class="note"><b>Criterio gerencial aplicado:</b> {{ $rep['criterion'] }}</p>
        @if($cmp['available'] && $cmp['sources_note'])<p class="note">{{ $cmp['sources_note'] }}</p>@endif
        @foreach($x['warnings'] as $w)<p class="warnnote">{{ $w }}</p>@endforeach
    </div>
</section>
</body>
</html>

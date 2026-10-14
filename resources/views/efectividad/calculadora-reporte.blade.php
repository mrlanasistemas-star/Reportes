@php
/**
 * Plantilla ÚNICA del reporte de la calculadora de Efectividad (PDF y PNG).
 * Recibe el MISMO resultado ya calculado ($r); nunca recalcula importes.
 *
 * $r        => resultado (titulo, parametros, filas, notas, avisos, filtro, incidencias)
 * $filas    => filas a pintar en esta página/imagen
 * $imagen   => true para PNG (ancho fijo, sin paginación de impresión)
 * $pagina, $paginas => numeración de la imagen (solo PNG)
 * $conCierre => true en la última página/imagen (notas metodológicas)
 */
$money = fn ($v) => ((float) $v < 0 ? '-$' : '$').number_format(abs((float) $v), 2);
$pct = fn ($v) => number_format(((float) $v) * 100, 2).'%';
$gt = collect($r['filas'])->firstWhere('tipo', 'gran_total') ?? [];
$sem = function ($row) {
    if (($row['sano_pc'] ?? 0) <= 0) {
        return '';
    }
    $p = (float) $row['pct_total'];

    return $p >= 0.95 ? 'ok' : ($p >= 0.90 ? 'warn' : 'bad');
};
$modos = ['DIARIO' => 'Diario', 'SEMANAL' => 'Semanal', 'MENSUAL' => 'Mensual'];
$p = $r['parametros'];
$d = fn ($ymd) => \Carbon\Carbon::parse($ymd)->format('d/m/Y');
$alcance = isset($r['filtro']['promotor']) ? 'Promotor: '.$r['filtro']['promotor'] : (isset($r['filtro']['oficina']) ? 'Oficina: '.$r['filtro']['oficina'] : 'Todas las oficinas');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Efectividad de Cobranza — {{ $r['titulo'] }}</title>
<style>
    * { box-sizing: border-box; }
    @page { size: A4 landscape; }
    html, body { margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Helvetica, Arial, sans-serif; color: #0f172a; font-size: 8.4pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .page { padding: {{ $imagen ? '28px 32px' : '0' }}; background: #fff; {{ $imagen ? 'width: 1400px;' : '' }} }
    .brand { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #0f766e; padding-bottom: 10px; margin-bottom: 12px; }
    .brand .co { font-size: 9pt; font-weight: 700; letter-spacing: .14em; color: #0f766e; }
    .brand h1 { margin: 2px 0 0; font-size: 17pt; font-weight: 700; }
    .brand .sub { color: #475569; font-size: 9pt; margin-top: 2px; }
    .brand .meta { text-align: right; font-size: 8pt; color: #475569; line-height: 1.55; }
    .brand .meta b { color: #0f172a; }
    .kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-bottom: 12px; }
    .kpi { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; background: #f8fafc; }
    .kpi .l { font-size: 7pt; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
    .kpi .v { font-size: 12pt; font-weight: 700; margin-top: 2px; white-space: nowrap; }
    .kpi.ok .v { color: #047857; } .kpi.warn .v { color: #b45309; } .kpi.bad .v { color: #b91c1c; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; break-inside: avoid; }
    th { background: #0f172a; color: #fff; font-weight: 600; font-size: 7.2pt; padding: 5px 4px; text-align: right; line-height: 1.2; }
    th.grp { text-align: center; background: #1e293b; border-left: 1px solid #334155; }
    th:first-child { text-align: left; width: 17%; }
    td { padding: 4px 4px; border-bottom: 1px solid #eef2f7; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; overflow: hidden; text-overflow: ellipsis; }
    td:first-child { text-align: left; }
    tr.office td { background: #f1f5f9; font-weight: 700; color: #0f766e; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #cbd5e1; }
    tr.subtotal td { background: #e2e8f0; font-weight: 700; border-bottom: 1px solid #94a3b8; }
    tr.total td { background: #0f766e; color: #fff; font-weight: 700; font-size: 8.8pt; }
    td.neg { color: #b91c1c; } tr.total td.neg { color: #fecaca; }
    td.pos-mora { color: #047857; }
    td.ok { color: #047857; font-weight: 700; } td.warn { color: #b45309; font-weight: 700; } td.bad { color: #b91c1c; font-weight: 700; }
    tr.total td.ok, tr.total td.warn, tr.total td.bad { color: #fff; }
    .notes { margin-top: 12px; display: grid; grid-template-columns: 2fr 1fr; gap: 12px; page-break-inside: avoid; }
    .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 7.6pt; color: #334155; line-height: 1.5; }
    .box h4 { margin: 0 0 4px; font-size: 8pt; color: #0f172a; }
    .box ul { margin: 0; padding-left: 14px; }
    .warnbox { border-color: #fcd34d; background: #fffbeb; }
    .foot { margin-top: 10px; display: flex; justify-content: space-between; font-size: 7pt; color: #94a3b8; }
</style>
</head>
<body>
<div class="page">
    <div class="brand">
        <div>
            <div class="co">MR LANA</div>
            <h1>Efectividad de Cobranza</h1>
            <div class="sub">{{ $r['titulo'] }} · {{ $r['leyenda'] }}</div>
        </div>
        <div class="meta">
            <div>Modalidad: <b>{{ $modos[$r['modo']] ?? $r['modo'] }}</b></div>
            <div>Periodo de ingresos: <b>{{ $d($p['inicio_ingresos']) }} al {{ $d($p['fin_ingresos']) }}</b></div>
            <div>Fecha de corte: <b>{{ $d($p['fecha_corte']) }}</b> · Domingo base: <b>{{ $d($p['domingo_base']) }}</b></div>
            <div>{{ $alcance }}</div>
        </div>
    </div>

    @if(($pagina ?? 1) === 1)
    <div class="kpis">
        <div class="kpi"><div class="l">Sano por cobrar</div><div class="v">{{ $money($gt['sano_pc'] ?? 0) }}</div></div>
        <div class="kpi"><div class="l">Sano cobrado</div><div class="v">{{ $money($gt['sano_cob'] ?? 0) }}</div></div>
        <div class="kpi"><div class="l">Atraso + vencido cobrado</div><div class="v">{{ $money(($gt['atraso_cob'] ?? 0) + ($gt['vencido_cob'] ?? 0)) }}</div></div>
        <div class="kpi"><div class="l">Cobranza total</div><div class="v">{{ $money($gt['cobranza_total'] ?? 0) }}</div></div>
        <div class="kpi {{ $sem($gt) }}"><div class="l">% Cobranza total</div><div class="v">{{ $pct($gt['pct_total'] ?? 0) }}</div></div>
        <div class="kpi {{ ($gt['mora'] ?? 0) > 0 ? 'bad' : 'ok' }}"><div class="l">Mora</div><div class="v">{{ $money($gt['mora'] ?? 0) }}</div></div>
    </div>
    @endif

    <table>
        <thead>
            <tr>
                <th rowspan="2">Oficina / Promotor</th>
                <th class="grp" colspan="3">Sano</th>
                <th class="grp" colspan="3">Vigente con atraso</th>
                <th class="grp" colspan="3">Vencido con atraso</th>
                <th class="grp" colspan="3">Total</th>
            </tr>
            <tr>
                <th>Por cobrar</th><th>Cobrado</th><th>%</th>
                <th>Por cobrar</th><th>Cobrado</th><th>%</th>
                <th>Por cobrar</th><th>Cobrado</th><th>%</th>
                <th>Cobranza</th><th>%</th><th>Mora</th>
            </tr>
        </thead>
        <tbody>
        @php $oficinaActual = null; @endphp
        @foreach($filas as $row)
            @if($row['tipo'] === 'promotor' && $row['oficina'] !== $oficinaActual)
                @php $oficinaActual = $row['oficina']; @endphp
                <tr class="office"><td colspan="13">{{ $row['oficina_titulo'] }}</td></tr>
            @endif
            <tr class="{{ $row['tipo'] === 'gran_total' ? 'total' : ($row['tipo'] === 'subtotal' ? 'subtotal' : '') }}">
                <td title="{{ $row['promotor'] }}">{{ $row['promotor'] }}</td>
                @foreach(['sano_pc', 'sano_cob'] as $c)<td class="{{ $row[$c] < 0 ? 'neg' : '' }}">{{ $money($row[$c]) }}</td>@endforeach
                <td>{{ $pct($row['sano_pct']) }}</td>
                @foreach(['atraso_pc', 'atraso_cob'] as $c)<td class="{{ $row[$c] < 0 ? 'neg' : '' }}">{{ $money($row[$c]) }}</td>@endforeach
                <td>{{ $pct($row['atraso_pct']) }}</td>
                @foreach(['vencido_pc', 'vencido_cob'] as $c)<td class="{{ $row[$c] < 0 ? 'neg' : '' }}">{{ $money($row[$c]) }}</td>@endforeach
                <td>{{ $pct($row['vencido_pct']) }}</td>
                <td class="{{ $row['cobranza_total'] < 0 ? 'neg' : '' }}">{{ $money($row['cobranza_total']) }}</td>
                <td class="{{ $sem($row) }}">{{ $pct($row['pct_total']) }}</td>
                <td class="{{ $row['mora'] > 0 ? 'neg' : ($row['mora'] < 0 ? 'pos-mora' : '') }}">{{ $money($row['mora']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($conCierre)
    <div class="notes">
        <div class="box">
            <h4>Notas metodológicas</h4>
            <ul>@foreach($r['notas'] as $n)<li>{{ $n }}</li>@endforeach</ul>
        </div>
        <div class="box {{ $r['avisos'] ? 'warnbox' : '' }}">
            <h4>Control</h4>
            <div>Incidencias de auditoría: <b>{{ number_format(count($r['incidencias'])) }}</b></div>
            @foreach(collect($r['incidencias'])->countBy('codigo') as $codigo => $n)
                <div>{{ $codigo }}: {{ number_format($n) }}</div>
            @endforeach
            @foreach($r['avisos'] as $a)<div style="margin-top:4px;">⚠ {{ $a }}</div>@endforeach
            <div style="margin-top:4px;">Semáforo % total: ≥95% verde · ≥90% ámbar · resto rojo.</div>
        </div>
    </div>
    @endif

    @if($imagen)
    <div class="foot">
        <span>MR LANA · Reportes — generado {{ $r['generado'] }}</span>
        <span>Imagen {{ $pagina }} de {{ $paginas }}</span>
    </div>
    @endif
</div>
<script>window.__PDF_READY__ = true;</script>
</body>
</html>

@php
/**
 * PDF ejecutivo «Datos duros». $d = HardDataService::dataset() (filas ya filtradas),
 * $single = una fila (ficha individual) o null (lista), $totals = HardDataPdfExporter::totals().
 * Solo da formato: no recalcula cifras.
 */
use App\Services\HardData\HardDataLabels as L;

$money = fn ($v, int $dec = 0) => ((float) $v < 0 ? '-$' : '$').number_format(abs((float) $v), $dec);
$compact = function ($v) {
    $a = abs((float) $v); $s = (float) $v < 0 ? '-$' : '$';
    return match (true) {
        $a >= 1_000_000 => $s.number_format($a / 1_000_000, $a >= 10_000_000 ? 1 : 2).'M',
        $a >= 1_000 => $s.number_format($a / 1_000, $a >= 100_000 ? 0 : 1).'K',
        default => $s.number_format($a, 0),
    };
};
$pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 2).'%';
$moraClass = fn ($v) => (float) ($v ?? 0) >= 15 ? 'bad' : ((float) ($v ?? 0) >= 5 ? 'warn' : 'ok');
$logoPath = public_path('logo-mr-lana.png');
$logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
$p = $d['period'];
$t = $totals;
$generated = now()->locale('es')->translatedFormat('j M Y, H:i');
$branches = collect($d['rows'])->groupBy(fn ($r) => $r['branch'] ?? 'Sin sucursal')->map(function ($g, $name) {
    $pf = $g->sum('portfolio'); $inc = $g->sum('income_base');
    return ['name' => $name, 'count' => $g->count(), 'placement' => $g->sum('placement'), 'recovery' => $g->sum('recovery'),
        'portfolio' => $pf, 'mora' => $pf > 0 ? $g->sum('overdue') / $pf * 100 : 0, 'payroll' => $g->sum('payroll'),
        'ebitda' => $g->sum('ebitda'), 'margin' => $inc > 0 ? $g->sum('ebitda') / $inc * 100 : 0];
})->sortByDesc('ebitda')->values();
$maxBranch = max(1, (float) $branches->max(fn ($b) => abs($b['ebitda'])));
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Datos duros · {{ $p['name'] }}</title>
<script>window.__PDF_READY__ = true;</script>
<style>
    @include('reports.partials.montserrat-font-face')
    @page { size: Letter {{ $single ? 'portrait' : 'landscape' }}; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Montserrat', Helvetica, Arial, sans-serif; color: #0f172a; font-size: 7.4pt; background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .neg { color: #dc2626; }
    .muted { color: #64748b; }

    /* Encabezado */
    .hero { position: relative; overflow: hidden; border-radius: 10px; padding: 12px 16px; color: #fff;
        background: linear-gradient(120deg, #0b4d41 0%, #106a59 55%, #1dc1a2 140%); display: flex; align-items: center; gap: 14px; }
    .hero::after { content: ''; position: absolute; right: -60px; top: -80px; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,.08); }
    .hero .logo { height: 34px; width: 34px; border-radius: 8px; background: #fff; padding: 3px; object-fit: contain; }
    .hero h1 { font-size: 15pt; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
    .hero .sub { font-size: 7.6pt; opacity: .85; margin-top: 2px; }
    .hero .meta { margin-left: auto; text-align: right; font-size: 7pt; line-height: 1.55; position: relative; z-index: 1; }
    .chip { display: inline-block; border-radius: 999px; padding: 1.5px 7px; font-weight: 700; font-size: 6.6pt; background: rgba(255,255,255,.16); }

    /* KPIs */
    .kpis { display: grid; gap: 6px; margin-top: 8px; }
    .kpi { border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 8px; background: #fff; }
    .kpi .l { font-size: 6pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #64748b; }
    .kpi .v { font-size: 11.5pt; font-weight: 800; letter-spacing: -.02em; margin-top: 1px; font-variant-numeric: tabular-nums; }
    .kpi .h { font-size: 6.2pt; color: #64748b; margin-top: 1px; }
    .kpi.hero-kpi { background: #0f172a; border-color: #0f172a; color: #fff; }
    .kpi.hero-kpi .l, .kpi.hero-kpi .h { color: #94a3b8; }
    .kpi.hero-kpi .v { font-size: 14pt; color: #5eead4; }

    /* Secciones */
    h2 { font-size: 8.4pt; font-weight: 800; letter-spacing: .02em; text-transform: uppercase; color: #106a59; margin: 9px 0 4px; display: flex; align-items: center; gap: 6px; }
    h2::before { content: ''; width: 3px; height: 10px; border-radius: 2px; background: #1dc1a2; }
    .card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 9px; break-inside: avoid; }

    /* Tablas */
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
    th { background: #f1f5f9; color: #475569; font-size: 6pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 4px 5px; text-align: left; border-bottom: 1.5px solid #106a59; }
    td { padding: 2.6px 5px; border-bottom: 1px solid #eef2f6; vertical-align: middle; }
    tbody tr:nth-child(even) td { background: #fafcfd; }
    tr.total td, tfoot td { font-weight: 800; background: #ecfdf5 !important; border-top: 1.5px solid #106a59; border-bottom: 0; }
    .name { font-weight: 700; white-space: nowrap; }
    td.nw { white-space: nowrap; }
    .routes { font-size: 5.8pt; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 190px; }
    .evo { display: flex; align-items: flex-end; gap: 6px; height: 70px; padding: 0 2px; border-bottom: 1px solid #e2e8f0; }
    .evo .col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
    .evo .b { width: 70%; border-radius: 3px 3px 0 0; min-height: 2px; }
    .evo .v { font-size: 5.8pt; font-weight: 800; margin-bottom: 2px; white-space: nowrap; }
    .evo-l { display: flex; gap: 6px; padding: 3px 2px 0; }
    .evo-l span { flex: 1; text-align: center; font-size: 5.8pt; color: #64748b; font-weight: 700; }
    .now td { background: #ecfdf5 !important; font-weight: 800; }
    .rank { color: #94a3b8; font-weight: 700; width: 16px; }
    .cat { display: inline-block; border-radius: 999px; padding: 1px 6px; font-size: 5.8pt; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: .03em; }
    .pill { display: inline-block; border-radius: 999px; padding: 1px 6px; font-weight: 800; font-size: 6.4pt; }
    .pill.ok { background: #dcfce7; color: #15803d; } .pill.warn { background: #fef3c7; color: #b45309; } .pill.bad { background: #fee2e2; color: #b91c1c; }
    .bar { height: 5px; border-radius: 3px; background: #eef2f6; overflow: hidden; min-width: 60px; }
    .bar > i { display: block; height: 100%; border-radius: 3px; }
    .baja { font-size: 5.6pt; font-weight: 800; color: #b91c1c; background: #fee2e2; border-radius: 999px; padding: 0 4px; margin-left: 3px; }

    /* Ficha */
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-top: 7px; }
    .row { display: flex; justify-content: space-between; gap: 8px; padding: 2.4px 0; border-bottom: 1px dashed #eef2f6; }
    .row:last-child { border-bottom: 0; }
    .stack { display: flex; height: 9px; border-radius: 5px; overflow: hidden; background: #eef2f6; margin: 4px 0 6px; }
    .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
    .ebitda-eq { display: flex; align-items: stretch; gap: 6px; margin-top: 7px; }
    .ebitda-eq .op { display: flex; align-items: center; font-size: 13pt; font-weight: 800; color: #94a3b8; }
    .ebitda-eq .kpi { flex: 1; }
</style>
</head>
<body>

<div class="hero">
    @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
    <div style="position:relative;z-index:1">
        @if ($single)
            <h1>{{ $single['name'] }}</h1>
            <div class="sub">{{ $single['branch'] ?? 'Sin sucursal' }}@if ($single['routes']) · {{ implode(', ', $single['routes']) }}@endif
                @if ($single['position']) · {{ $single['position'] }}@endif</div>
        @else
            <h1>Datos duros por colaborador</h1>
            <div class="sub">Cifras finales del periodo: colocación, recuperación, cartera, mora, nómina, OPEX y EBITDA</div>
        @endif
    </div>
    <div class="meta">
        <span class="chip">{{ $p['name'] }}</span>@if ($p['range']) <span class="chip">{{ $p['range'] }}</span>@endif<br>
        {{ $d['scope']['label'] }} · {{ $single ? 'Ficha individual' : $t['count'].' colaboradores' }}<br>
        <span style="opacity:.75">Generado {{ $generated }}</span>
    </div>
</div>

@if ($single)
    @php
        $r = $single;
        $buckets = collect($r['buckets'] ?? [])->filter(fn ($b) => (float) ($b['amount'] ?? 0) > 0);
        $bucketTotal = max(1, (float) $buckets->sum('amount'));
        $recovery = collect($r['recovery_components'] ?? [])->filter(fn ($v) => (float) $v != 0.0)->sortDesc();
        $payroll = collect($r['payroll_concepts'] ?? [])->filter(fn ($v) => (float) $v != 0.0)->sortDesc();
        $products = collect($r['placement_by_product'] ?? [])->sortByDesc('amount');
        $opexItems = collect($r['opex_items'] ?? [])->sortByDesc('amount');
        $isBaja = mb_strtoupper((string) $r['status']) === 'BAJA';
    @endphp

    <div class="ebitda-eq">
        <div class="kpi"><div class="l">Ingreso base</div><div class="v">{{ $money($r['income_base']) }}</div><div class="h">Utilidad bruta del periodo</div></div>
        <div class="op">−</div>
        <div class="kpi"><div class="l">Gasto total</div><div class="v">{{ $money($r['total_expenses']) }}</div><div class="h">Nómina {{ $compact($r['payroll']) }} + OPEX {{ $compact($r['opex']) }}</div></div>
        <div class="op">=</div>
        <div class="kpi hero-kpi"><div class="l">EBITDA</div><div class="v {{ $r['ebitda'] < 0 ? 'neg' : '' }}">{{ $money($r['ebitda']) }}</div>
            <div class="h">Margen {{ $pct($r['margin_pct']) }} · <span class="cat" style="background: {{ L::categoryColor($r['category']) }}">{{ $r['category'] }}</span>@if ($isBaja)<span class="baja">BAJA</span>@endif</div></div>
    </div>

    <div class="kpis" style="grid-template-columns: repeat(4, 1fr)">
        <div class="kpi"><div class="l">Colocación</div><div class="v">{{ $money($r['placement']) }}</div><div class="h">{{ number_format($r['operations']) }} operaciones</div></div>
        <div class="kpi"><div class="l">Recuperación</div><div class="v">{{ $money($r['recovery']) }}</div><div class="h">Cobranza del periodo</div></div>
        <div class="kpi"><div class="l">Cartera</div><div class="v">{{ $money($r['portfolio']) }}</div><div class="h">Vencida {{ $money($r['overdue']) }}</div></div>
        <div class="kpi"><div class="l">Mora</div><div class="v"><span class="pill {{ $moraClass($r['mora_pct']) }}" style="font-size:10pt">{{ $pct($r['mora_pct']) }}</span></div><div class="h">Vencida / cartera</div></div>
    </div>

    <div class="grid2">
        <div class="card">
            <h2 style="margin-top:0">Cartera por antigüedad</h2>
            @if ($buckets->isEmpty())
                <div class="muted">Sin cartera en el periodo.</div>
            @else
                <div class="stack">@foreach ($buckets as $k => $b)<i style="width: {{ $b['amount'] / $bucketTotal * 100 }}%; background: {{ L::BUCKET_COLORS[$k] ?? '#94a3b8' }}"></i>@endforeach</div>
                @foreach ($buckets as $k => $b)
                    <div class="row"><span><span class="dot" style="background: {{ L::BUCKET_COLORS[$k] ?? '#94a3b8' }}"></span>{{ L::BUCKETS[$k] ?? $k }} <span class="muted">· {{ $b['contracts'] }} contratos</span></span><span class="num">{{ $money($b['amount']) }}</span></div>
                @endforeach
            @endif
        </div>
        <div class="card">
            <h2 style="margin-top:0">Recuperación por componente</h2>
            @forelse ($recovery as $k => $v)
                <div class="row"><span>{{ L::recovery($k) }}</span><span class="num">{{ $money($v, 2) }}</span></div>
            @empty
                <div class="muted">Sin cobranza en el periodo.</div>
            @endforelse
        </div>
        <div class="card">
            <h2 style="margin-top:0">Colocación por producto</h2>
            @forelse ($products as $i)
                <div class="row"><span>{{ $i['product'] ?? 'Sin producto' }} <span class="muted">· {{ $i['operations'] }} ops</span></span><span class="num">{{ $money($i['amount'], 2) }}</span></div>
            @empty
                <div class="muted">Sin colocación en el periodo.</div>
            @endforelse
        </div>
        <div class="card">
            <h2 style="margin-top:0">Nómina / capital humano</h2>
            @forelse ($payroll as $k => $v)
                <div class="row"><span>{{ L::payroll($k) }}</span><span class="num">{{ $money($v, 2) }}</span></div>
            @empty
                <div class="muted">Sin percepciones registradas.</div>
            @endforelse
            <div class="row" style="font-weight:800"><span>Total capital humano</span><span class="num">{{ $money($r['payroll'], 2) }}</span></div>
        </div>
    </div>

    <h2>OPEX atribuible</h2>
    @if ($opexItems->isEmpty())
        <div class="card muted">Sin gasto atribuible en el periodo.</div>
    @else
        <table>
            <thead><tr><th>Categoría</th><th>Concepto</th><th class="num">Monto</th></tr></thead>
            <tbody>
                @foreach ($opexItems as $i)
                    <tr><td>{{ $i['category'] }}</td><td>{{ $i['concept'] }}</td><td class="num">{{ $money($i['amount'], 2) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="2">Total OPEX</td><td class="num">{{ $money($r['opex'], 2) }}</td></tr></tfoot>
        </table>
    @endif

    @php
        $evo = collect($history ?? [])->filter(fn ($h) => $h['present'] ?? false)->take(-8)->values();
        $evoMax = max(1, (float) $evo->max(fn ($h) => abs((float) $h['ebitda'])));
    @endphp
    @if ($evo->count() > 1)
        <h2>Evolución en los últimos periodos</h2>
        <div class="grid2" style="grid-template-columns: 1fr 1.6fr; margin-top: 0">
            <div class="card">
                <div class="muted" style="font-size:6.2pt;font-weight:700;margin-bottom:4px">EBITDA POR PERIODO</div>
                <div class="evo">
                    @foreach ($evo as $h)
                        <div class="col">
                            <div class="v {{ $h['ebitda'] < 0 ? 'neg' : '' }}">{{ $compact($h['ebitda']) }}</div>
                            <div class="b" style="height: {{ max(3, abs((float) $h['ebitda']) / $evoMax * 52) }}px; background: {{ $h['period']['id'] === $p['id'] ? '#106a59' : ($h['ebitda'] < 0 ? '#fca5a5' : '#99e6d6') }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="evo-l">@foreach ($evo as $h)<span>{{ $h['period']['name'] }}</span>@endforeach</div>
            </div>
            <table>
                <thead><tr><th>Periodo</th><th class="num">Colocación</th><th class="num">Recuperación</th><th class="num">Cartera</th><th class="num">Mora</th><th class="num">Nómina</th><th class="num">EBITDA</th></tr></thead>
                <tbody>
                    @foreach ($evo as $h)
                        <tr class="{{ $h['period']['id'] === $p['id'] ? 'now' : '' }}">
                            <td class="nw">{{ $h['period']['name'] }}</td>
                            <td class="num">{{ $money($h['placement']) }}</td><td class="num">{{ $money($h['recovery']) }}</td><td class="num">{{ $money($h['portfolio']) }}</td>
                            <td class="num"><span class="pill {{ $moraClass($h['mora_pct']) }}">{{ $pct($h['mora_pct']) }}</span></td>
                            <td class="num">{{ $money($h['payroll']) }}</td>
                            <td class="num {{ $h['ebitda'] < 0 ? 'neg' : '' }}" style="font-weight:800">{{ $money($h['ebitda']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@else
    <div class="kpis" style="grid-template-columns: 1.5fr repeat(7, 1fr)">
        <div class="kpi hero-kpi"><div class="l">EBITDA</div><div class="v {{ $t['ebitda'] < 0 ? 'neg' : '' }}">{{ $money($t['ebitda']) }}</div><div class="h">Margen {{ $pct($t['margin_pct']) }} sobre ingreso base</div></div>
        <div class="kpi"><div class="l">Colaboradores</div><div class="v">{{ number_format($t['count']) }}</div><div class="h">{{ $branches->count() }} sucursales</div></div>
        <div class="kpi"><div class="l">Colocación</div><div class="v">{{ $compact($t['placement']) }}</div><div class="h">{{ number_format($t['operations']) }} operaciones</div></div>
        <div class="kpi"><div class="l">Recuperación</div><div class="v">{{ $compact($t['recovery']) }}</div><div class="h">Cobranza del periodo</div></div>
        <div class="kpi"><div class="l">Cartera</div><div class="v">{{ $compact($t['portfolio']) }}</div><div class="h">Vencida {{ $compact($t['overdue']) }}</div></div>
        <div class="kpi"><div class="l">Mora</div><div class="v">{{ $pct($t['mora_pct']) }}</div><div class="h">Vencida / cartera</div></div>
        <div class="kpi"><div class="l">Nómina</div><div class="v">{{ $compact($t['payroll']) }}</div><div class="h">Capital humano</div></div>
        <div class="kpi"><div class="l">OPEX</div><div class="v">{{ $compact($t['opex']) }}</div><div class="h">Gasto atribuible</div></div>
    </div>

    @if ($branches->count() > 1)
        <h2>Resultado por sucursal</h2>
        <table>
            <thead><tr><th>Sucursal</th><th class="num">Colab.</th><th class="num">Colocación</th><th class="num">Recuperación</th><th class="num">Cartera</th><th class="num">Mora</th><th class="num">Nómina</th><th class="num">EBITDA</th><th style="width:18%"></th><th class="num">Margen</th></tr></thead>
            <tbody>
                @foreach ($branches as $b)
                    <tr>
                        <td class="name">{{ $b['name'] }}</td><td class="num">{{ $b['count'] }}</td>
                        <td class="num">{{ $money($b['placement']) }}</td><td class="num">{{ $money($b['recovery']) }}</td><td class="num">{{ $money($b['portfolio']) }}</td>
                        <td class="num"><span class="pill {{ $moraClass($b['mora']) }}">{{ $pct($b['mora']) }}</span></td>
                        <td class="num">{{ $money($b['payroll']) }}</td>
                        <td class="num {{ $b['ebitda'] < 0 ? 'neg' : '' }}" style="font-weight:800">{{ $money($b['ebitda']) }}</td>
                        <td><div class="bar"><i style="width: {{ abs($b['ebitda']) / $maxBranch * 100 }}%; background: {{ $b['ebitda'] < 0 ? '#ef4444' : '#1dc1a2' }}"></i></div></td>
                        <td class="num">{{ $pct($b['margin']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Detalle por colaborador</h2>
    <table>
        <thead><tr>
            <th>#</th><th>Colaborador</th><th>Sucursal</th><th>Cat.</th><th class="num">Colocación</th><th class="num">Ops</th><th class="num">Recuperación</th>
            <th class="num">Cartera</th><th class="num">Vencida</th><th class="num">Mora</th><th class="num">Nómina</th><th class="num">OPEX</th><th class="num">EBITDA</th><th class="num">Margen</th>
        </tr></thead>
        <tbody>
            @foreach ($d['rows'] as $i => $r)
                <tr>
                    <td class="rank">{{ $i + 1 }}</td>
                    <td><div class="name">{{ $r['name'] }}@if (mb_strtoupper((string) $r['status']) === 'BAJA')<span class="baja">BAJA</span>@endif</div>
                        @if ($r['routes'])<div class="routes">{{ implode(', ', $r['routes']) }}</div>@endif</td>
                    <td class="nw">{{ $r['branch'] ?? '—' }}</td>
                    <td><span class="cat" style="background: {{ L::categoryColor($r['category']) }}">{{ mb_substr((string) $r['category'], 0, 3) }}</span></td>
                    <td class="num">{{ $money($r['placement']) }}</td><td class="num">{{ $r['operations'] }}</td>
                    <td class="num">{{ $money($r['recovery']) }}</td><td class="num">{{ $money($r['portfolio']) }}</td><td class="num">{{ $money($r['overdue']) }}</td>
                    <td class="num"><span class="pill {{ $moraClass($r['mora_pct']) }}">{{ $pct($r['mora_pct']) }}</span></td>
                    <td class="num">{{ $money($r['payroll']) }}</td><td class="num">{{ $money($r['opex']) }}</td>
                    <td class="num {{ $r['ebitda'] < 0 ? 'neg' : '' }}" style="font-weight:800">{{ $money($r['ebitda']) }}</td>
                    <td class="num">{{ $pct($r['margin_pct']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
            <td></td><td colspan="3">Total · {{ number_format($t['count']) }} colaboradores</td>
            <td class="num">{{ $money($t['placement']) }}</td><td class="num">{{ number_format($t['operations']) }}</td><td class="num">{{ $money($t['recovery']) }}</td>
            <td class="num">{{ $money($t['portfolio']) }}</td><td class="num">{{ $money($t['overdue']) }}</td><td class="num">{{ $pct($t['mora_pct']) }}</td>
            <td class="num">{{ $money($t['payroll']) }}</td><td class="num">{{ $money($t['opex']) }}</td>
            <td class="num {{ $t['ebitda'] < 0 ? 'neg' : '' }}">{{ $money($t['ebitda']) }}</td><td class="num">{{ $pct($t['margin_pct']) }}</td>
        </tr>
        </tbody>
    </table>
@endif

</body>
</html>

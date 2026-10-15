<?php
/**
 * Dashboard exportado a PDF (15-oct-2026; rediseño ejecutivo Fase 7.2). Representa
 * EXACTAMENTE lo que muestra el Dashboard para el periodo y el alcance elegidos:
 * los 8 KPI y las gráficas, con los mismos datos (DashboardController::
 * payloadForPeriod() + DashboardTrendService::build($branchId) — la tendencia
 * también es la del alcance: sucursal o consolidado). Solo presenta, no calcula.
 *
 * @var array $kpis  @var ?array $charts  @var array $trend  @var array $scope
 * @var string $periodLabel  @var ?string $branchName  @var \Illuminate\Support\Carbon $generatedAt
 * @var ?string $generatedBy  @var \App\Services\Dashboard\DashboardPdfChartBuilder $chart
 */
$money = fn ($v) => $chart->money((float) $v);
$pct = fn ($v) => number_format((float) $v, 2, '.', ',') . '%';
$hero = $kpis ? [
    ['EBITDA', $money($kpis['ebitda']), (float) $kpis['ebitda'] < 0 ? 'neg' : 'pos', 'Ingreso base EBITDA − gastos totales'],
    ['Margen EBITDA', $pct($kpis['margen_ebitda']), (float) $kpis['margen_ebitda'] < 0 ? 'neg' : 'info', 'EBITDA sobre ingreso base'],
] : [];
$cards = $kpis ? [
    ['OPEX', $money($kpis['opex']), ''],
    ['Colocación', $money($kpis['colocacion']), ''],
    ['Recuperación', $money($kpis['recuperacion']), ''],
    ['Cartera', $money($kpis['cartera']), ''],
    ['Mora', $pct($kpis['mora_pct']), (float) $kpis['mora_pct'] > 15 ? 'neg' : 'pos'],
    ['Colaboradores', number_format((int) $kpis['colaboradores']), ''],
] : [];
$scopeLabel = $branchName ? 'Sucursal ' . $branchName : 'Todas las sucursales';
$isBranch = (bool) $branchName && count($charts['sucursalRows'] ?? []) === 1;
$missing = array_values(array_map(fn ($p) => $p['label'], array_filter($trend, fn ($p) => ($p['available'] ?? true) === false)));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen ejecutivo — {{ $periodLabel }} — {{ $scopeLabel }}</title>
<script>
  window.__PDF_READY__ = false;
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () { window.__PDF_READY__ = true; });
  setTimeout(function () { window.__PDF_READY__ = true; }, 4000);
</script>
<style>
@include('reports.partials.montserrat-font-face')
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif; font-size: 8.6pt; color: #1e293b; background: #fff; }

  .band { display: flex; justify-content: space-between; align-items: center; gap: 16pt; padding: 12pt 14pt; border-radius: 9pt;
          background: linear-gradient(120deg, #0b4f43 0%, #106A59 55%, #1a8f78 100%); color: #fff; }
  .brand { font-size: 8pt; font-weight: 700; letter-spacing: 1.4pt; text-transform: uppercase; opacity: .85; }
  .title { font-size: 17pt; font-weight: 800; letter-spacing: -.2pt; margin-top: 2pt; line-height: 1.15; }
  .title small { display: block; font-size: 9pt; font-weight: 600; opacity: .9; margin-top: 3pt; letter-spacing: 0; }
  .meta { text-align: right; font-size: 7.4pt; line-height: 1.65; opacity: .95; }
  .meta b { font-weight: 700; }
  .pill { display: inline-block; margin-top: 5pt; padding: 2.5pt 9pt; border-radius: 10pt; background: rgba(255,255,255,.16); border: .7pt solid rgba(255,255,255,.35); font-size: 7.6pt; font-weight: 700; }

  .hero { display: grid; grid-template-columns: 1fr 1fr; gap: 8pt; margin-top: 11pt; break-inside: avoid; }
  .hcard { border: .8pt solid #e2e8f0; border-radius: 8pt; padding: 10pt 12pt; background: #fff; position: relative; overflow: hidden; }
  .hcard::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3.5pt; background: #106A59; }
  .hcard.neg::before { background: #b91c1c; } .hcard.info::before { background: #1d4ed8; }
  .hcard .l { font-size: 7pt; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .6pt; }
  .hcard .v { font-size: 20pt; font-weight: 800; color: #0f172a; margin-top: 3pt; letter-spacing: -.3pt; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .hcard.pos .v { color: #106A59; } .hcard.neg .v { color: #b91c1c; } .hcard.info .v { color: #1d4ed8; }
  .hcard .h { font-size: 7pt; color: #94a3b8; margin-top: 2pt; }

  .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7pt; margin-top: 8pt; break-inside: avoid; }
  .kpi { border: .8pt solid #e2e8f0; border-radius: 7pt; padding: 7pt 10pt; background: #f8fafc; }
  .kpi .l { font-size: 6.8pt; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5pt; }
  .kpi .v { font-size: 12pt; font-weight: 800; color: #0f172a; margin-top: 2pt; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .kpi .v.pos { color: #106A59; } .kpi .v.neg { color: #b91c1c; }

  .card { border: .8pt solid #e2e8f0; border-radius: 8pt; padding: 9pt 11pt 7pt; margin-top: 10pt; break-inside: avoid; background: #fff; }
  .card h3 { font-size: 8.8pt; font-weight: 800; color: #0f172a; letter-spacing: .1pt; }
  .card .sub { font-size: 7pt; color: #64748b; margin: 1.5pt 0 5pt; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; }
  .row .card { margin-top: 10pt; }
  .note { font-size: 7pt; color: #92400e; background: #fffbeb; border: .6pt solid #fde68a; border-radius: 5pt; padding: 3pt 6pt; margin-top: 4pt; }
  .empty { padding: 16pt 10pt; text-align: center; color: #64748b; font-size: 8pt; border: .8pt dashed #cbd5e1; border-radius: 6pt; margin-top: 10pt; }
  .cat { padding: 18pt 6pt 10pt; }
  .cat .name { font-size: 22pt; font-weight: 800; letter-spacing: .4pt; }
  .cat .val { font-size: 9pt; color: #475569; margin-top: 4pt; font-weight: 600; }

  table.t { width: 100%; border-collapse: collapse; font-size: 7.6pt; margin-top: 4pt; }
  table.t th { background: #f1f5f9; color: #334155; text-align: left; padding: 4.5pt 6pt; font-weight: 700; font-size: 7pt; text-transform: uppercase; letter-spacing: .4pt; border-bottom: 1pt solid #cbd5e1; }
  table.t td { padding: 3.8pt 6pt; border-bottom: .5pt solid #e2e8f0; font-variant-numeric: tabular-nums; }
  table.t tbody tr:nth-child(even) td { background: #fafbfc; }
  table.t tr { break-inside: avoid; }
  table.t .r { text-align: right; white-space: nowrap; }
  table.t tfoot td { font-weight: 800; border-top: 1.2pt solid #106A59; background: #ecfdf5; color: #064e3b; }
  .src { margin-top: 10pt; font-size: 6.8pt; color: #94a3b8; }
</style>
</head>
<body>
  <div class="band">
    <div>
      <div class="brand">MR LANA · Reportes</div>
      <div class="title">Resumen ejecutivo<small>Dashboard · {{ $periodLabel }}</small></div>
      <span class="pill">{{ $scopeLabel }}</span>
    </div>
    <div class="meta">
      <div>Periodo <b>{{ $periodLabel }}</b></div>
      <div>Alcance <b>{{ $branchName ?? 'Consolidado' }}</b></div>
      <div>Generado <b>{{ $generatedAt->format('d/m/Y H:i') }}</b></div>
      @if($generatedBy)<div>Por <b>{{ $generatedBy }}</b></div>@endif
    </div>
  </div>

  @if(!$kpis)
    <div class="empty">Sin datos de radiografía para {{ $scopeLabel }} en {{ $periodLabel }}. Elige otra sucursal o verifica que la radiografía del periodo ya esté generada.</div>
  @else
    <div class="hero">
      @foreach($hero as [$label, $value, $tone, $hint])
        <div class="hcard {{ $tone }}"><div class="l">{{ $label }}</div><div class="v">{{ $value }}</div><div class="h">{{ $hint }}</div></div>
      @endforeach
    </div>
    <div class="kpis">
      @foreach($cards as [$label, $value, $tone])
        <div class="kpi"><div class="l">{{ $label }}</div><div class="v {{ $tone }}">{{ $value }}</div></div>
      @endforeach
    </div>

    <div class="card">
      <h3>EBITDA vs OPEX</h3>
      <div class="sub">Tendencia mensual · {{ $scopeLabel }} · últimos {{ count($trend) }} periodos generados</div>
      @if($trend)
        {!! $chart->trend($trend) !!}
        @if($missing)
          <div class="note">Sin radiografía de {{ $branchName }} en {{ implode(', ', $missing) }}: esos meses quedan como hueco en la línea (no se grafican como cero).</div>
        @endif
      @else
        <div class="empty" style="margin-top: 4pt;">Sin periodos suficientes para mostrar la tendencia.</div>
      @endif
    </div>

    @if($charts)
      <div class="row">
        <div class="card">
          @if($isBranch)
            @php($cat = $charts['categorias'][0] ?? null)
            <h3>Categoría EBITDA de la sucursal</h3>
            <div class="sub">{{ $branchName }} · según su EBITDA del periodo</div>
            <div class="cat">
              <div class="name" style="color: {{ $charts['categoriaColors'][0] ?? '#0f172a' }}">{{ $cat['categoria'] ?? '—' }}</div>
              <div class="val">EBITDA {{ $cat ? $money($cat['ebitda']) : '—' }}</div>
            </div>
          @else
            <h3>Categoría EBITDA por sucursal</h3>
            <div class="sub">Sucursales según su desempeño</div>
            {!! $chart->donut($charts['categoriaLabels'] ?? [], $charts['categoriaCounts'] ?? [], $charts['categoriaColors'] ?? []) ?: '<div class="empty">Sin sucursales con categoría.</div>' !!}
          @endif
        </div>
        <div class="card">
          <h3>Cartera vencida por antigüedad</h3>
          <div class="sub">Mora por bucket · {{ $scopeLabel }}</div>
          {!! $chart->bars(array_column($charts['moraBuckets'] ?? [], 'label'), [['name' => 'Cartera vencida', 'color' => '#EF4444', 'data' => array_column($charts['moraBuckets'] ?? [], 'valor')]], 340, 220, false, true) !!}
        </div>
      </div>

      @unless($isBranch)
        <div class="card">
          <h3>Colocación vs. recuperación</h3>
          <div class="sub">Por sucursal</div>
          {!! $chart->bars(array_column($charts['sucursalRows'] ?? [], 'sucursal'), [
            ['name' => 'Colocación', 'color' => '#106A59', 'data' => array_column($charts['sucursalRows'] ?? [], 'colocacion')],
            ['name' => 'Recuperación', 'color' => '#1DC1A2', 'data' => array_column($charts['sucursalRows'] ?? [], 'recuperacion')],
          ], 700, 270, true) !!}
        </div>
      @endunless

      {{-- Tabla de respaldo: las cifras exactas detrás de las gráficas. --}}
      <div class="card">
        <h3>{{ $isBranch ? 'Colocación, recuperación y EBITDA de la sucursal' : 'Detalle por sucursal' }}</h3>
        <div class="sub">Cifras exactas{{ $isBranch ? '' : ' de la gráfica anterior' }} y categoría EBITDA</div>
        @php($catBy = collect($charts['categorias'] ?? [])->keyBy('nombre'))
        <table class="t">
          <thead><tr><th>Sucursal</th><th class="r">Colocación</th><th class="r">Recuperación</th><th class="r">EBITDA</th><th>Categoría</th></tr></thead>
          <tbody>
            @foreach($charts['sucursalRows'] ?? [] as $r)
              <tr>
                <td>{{ $r['sucursal'] }}</td>
                <td class="r">{{ $money($r['colocacion']) }}</td>
                <td class="r">{{ $money($r['recuperacion']) }}</td>
                <td class="r" style="{{ ($catBy[$r['sucursal']]['ebitda'] ?? 0) < 0 ? 'color:#b91c1c' : '' }}">{{ isset($catBy[$r['sucursal']]) ? $money($catBy[$r['sucursal']]['ebitda']) : '—' }}</td>
                <td>{{ $catBy[$r['sucursal']]['categoria'] ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
          @unless($isBranch)
            <tfoot><tr>
              <td>Suma de sucursales</td>
              <td class="r">{{ $money(array_sum(array_column($charts['sucursalRows'] ?? [], 'colocacion'))) }}</td>
              <td class="r">{{ $money(array_sum(array_column($charts['sucursalRows'] ?? [], 'recuperacion'))) }}</td>
              <td class="r">{{ $money(array_sum(array_column($charts['categorias'] ?? [], 'ebitda'))) }}</td>
              <td></td>
            </tr></tfoot>
          @endunless
        </table>
      </div>
    @else
      <div class="empty">Este alcance no tiene desglose por sucursal en la radiografía del periodo.</div>
    @endif
    <div class="src">Fuente: radiografía generada del periodo (la misma de Dashboard, Excel y Reportería). Montos en pesos mexicanos (MXN).</div>
  @endif
</body>
</html>

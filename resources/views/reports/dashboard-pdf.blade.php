<?php
/**
 * Dashboard exportado a PDF (15-oct-2026). Antes el botón "Exportar PDF" del
 * Dashboard descargaba la Radiografía completa (otro reporte de muchas
 * páginas con filtros parecidos). Ahora este documento representa EXACTAMENTE
 * lo que muestra el Dashboard para el periodo y la sucursal seleccionados:
 * los 8 KPI y las 4 gráficas, con los mismos datos
 * (DashboardController::payloadForPeriod() + DashboardTrendService).
 *
 * @var array $kpis  @var ?array $charts  @var array $trend  @var array $scope
 * @var string $periodLabel  @var ?string $branchName  @var \Illuminate\Support\Carbon $generatedAt
 * @var ?string $generatedBy  @var \App\Services\Dashboard\DashboardPdfChartBuilder $chart
 */
$money = fn ($v) => $chart->money((float) $v);
$pct = fn ($v) => number_format((float) $v, 2, '.', ',') . '%';
$cards = $kpis ? [
    ['EBITDA', $money($kpis['ebitda']), (float) $kpis['ebitda'] < 0 ? 'neg' : 'pos'],
    ['Margen EBITDA', $pct($kpis['margen_ebitda']), (float) $kpis['margen_ebitda'] < 0 ? 'neg' : ''],
    ['OPEX', $money($kpis['opex']), ''],
    ['Colocación', $money($kpis['colocacion']), ''],
    ['Recuperación', $money($kpis['recuperacion']), ''],
    ['Cartera', $money($kpis['cartera']), ''],
    ['Mora', $pct($kpis['mora_pct']), (float) $kpis['mora_pct'] > 15 ? 'neg' : 'pos'],
    ['Colaboradores', number_format((int) $kpis['colaboradores']), ''],
] : [];
$scopeLabel = $branchName ? 'Sucursal ' . $branchName : 'Todas las sucursales';
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

  .head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16pt; padding-bottom: 8pt; border-bottom: 2pt solid #106A59; }
  .brand { font-size: 17pt; font-weight: 800; color: #106A59; letter-spacing: .3pt; line-height: 1; }
  .brand small { display: block; font-size: 7.4pt; font-weight: 600; color: #64748b; letter-spacing: .6pt; margin-top: 3pt; text-transform: uppercase; }
  .title { font-size: 14pt; font-weight: 800; color: #0f172a; margin-top: 8pt; }
  .meta { text-align: right; font-size: 7.6pt; color: #64748b; line-height: 1.55; }
  .meta b { color: #0f172a; font-weight: 700; }

  .filters { display: flex; gap: 6pt; margin: 9pt 0 2pt; flex-wrap: wrap; }
  .chip { border: .8pt solid #cbd5e1; border-radius: 10pt; padding: 2.5pt 8pt; font-size: 7.6pt; font-weight: 600; color: #334155; background: #f8fafc; }
  .chip b { color: #106A59; }

  .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 7pt; margin-top: 10pt; break-inside: avoid; }
  .kpi { border: .8pt solid #e2e8f0; border-radius: 6pt; padding: 7pt 9pt; background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); }
  .kpi .l { font-size: 6.8pt; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5pt; }
  .kpi .v { font-size: 11.5pt; font-weight: 800; color: #0f172a; margin-top: 3pt; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .kpi .v.pos { color: #106A59; } .kpi .v.neg { color: #b91c1c; }

  .card { border: .8pt solid #e2e8f0; border-radius: 7pt; padding: 8pt 10pt 6pt; margin-top: 10pt; break-inside: avoid; background: #fff; }
  .card h3 { font-size: 8.6pt; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: .3pt; }
  .card .sub { font-size: 7pt; color: #94a3b8; margin: 1pt 0 4pt; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; }
  .row .card { margin-top: 10pt; }
  .note { font-size: 7.2pt; color: #64748b; font-style: italic; margin-top: 3pt; }
  .empty { padding: 16pt 10pt; text-align: center; color: #64748b; font-size: 8pt; border: .8pt dashed #cbd5e1; border-radius: 6pt; margin-top: 10pt; }

  table.t { width: 100%; border-collapse: collapse; font-size: 7.6pt; margin-top: 4pt; }
  table.t th { background: #1f2937; color: #fff; text-align: left; padding: 4pt 6pt; font-weight: 700; }
  table.t td { padding: 3.5pt 6pt; border-bottom: .5pt solid #e2e8f0; font-variant-numeric: tabular-nums; }
  table.t tr { break-inside: avoid; }
  table.t .r { text-align: right; white-space: nowrap; }
  table.t tfoot td { font-weight: 800; border-top: 1pt solid #1f2937; background: #f1f5f9; }
  .page-break { break-before: page; }
</style>
</head>
<body>
  <div class="head">
    <div>
      <div class="brand">MR LANA<small>Reportes · Resumen ejecutivo</small></div>
      <div class="title">Dashboard — {{ $periodLabel }}</div>
    </div>
    <div class="meta">
      <div>Periodo: <b>{{ $periodLabel }}</b></div>
      <div>Alcance: <b>{{ $scopeLabel }}</b></div>
      <div>Generado: <b>{{ $generatedAt->format('d/m/Y H:i') }}</b>@if($generatedBy) · {{ $generatedBy }}@endif</div>
    </div>
  </div>

  <div class="filters">
    <span class="chip">Periodo <b>{{ $periodLabel }}</b></span>
    <span class="chip">Sucursal <b>{{ $branchName ?? 'Todas' }}</b></span>
    <span class="chip">Fuente <b>Radiografía generada del periodo</b></span>
  </div>

  @if(!$kpis)
    <div class="empty">Sin datos de radiografía para {{ $scopeLabel }} en {{ $periodLabel }}. Elige otra sucursal o verifica que la radiografía del periodo ya esté generada.</div>
  @else
    <div class="kpis">
      @foreach($cards as [$label, $value, $tone])
        <div class="kpi"><div class="l">{{ $label }}</div><div class="v {{ $tone }}">{{ $value }}</div></div>
      @endforeach
    </div>

    <div class="card">
      <h3>EBITDA vs OPEX</h3>
      <div class="sub">Tendencia mensual — últimos {{ count($trend) }} periodos generados{{ $branchName ? ' (tendencia general del sistema, igual que en el Dashboard; no se filtra por sucursal)' : '' }}</div>
      @if($trend)
        {!! $chart->trend($trend) !!}
      @else
        <div class="empty" style="margin-top: 4pt;">Sin periodos suficientes para mostrar la tendencia.</div>
      @endif
    </div>

    @if($charts)
      <div class="row">
        <div class="card">
          <h3>Categoría EBITDA por sucursal</h3>
          <div class="sub">Sucursales según su desempeño</div>
          {!! $chart->donut($charts['categoriaLabels'] ?? [], $charts['categoriaCounts'] ?? [], $charts['categoriaColors'] ?? []) ?: '<div class="empty">Sin sucursales con categoría.</div>' !!}
        </div>
        <div class="card">
          <h3>Cartera vencida por antigüedad</h3>
          <div class="sub">Mora por bucket</div>
          {!! $chart->bars(array_column($charts['moraBuckets'] ?? [], 'label'), [['name' => 'Cartera vencida', 'color' => '#EF4444', 'data' => array_column($charts['moraBuckets'] ?? [], 'valor')]], 340, 220, false, true) !!}
        </div>
      </div>

      <div class="card">
        <h3>Colocación vs. recuperación</h3>
        <div class="sub">Por sucursal</div>
        {!! $chart->bars(array_column($charts['sucursalRows'] ?? [], 'sucursal'), [
          ['name' => 'Colocación', 'color' => '#106A59', 'data' => array_column($charts['sucursalRows'] ?? [], 'colocacion')],
          ['name' => 'Recuperación', 'color' => '#1DC1A2', 'data' => array_column($charts['sucursalRows'] ?? [], 'recuperacion')],
        ], 700, 270, true) !!}
      </div>

      {{-- Tabla de respaldo: las cifras exactas detrás de la gráfica (en la gráfica solo caben etiquetas abreviadas). --}}
      <div class="card">
        <h3>Detalle por sucursal</h3>
        <div class="sub">Cifras exactas de la gráfica anterior y categoría EBITDA</div>
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
          <tfoot><tr>
            <td>Suma de sucursales</td>
            <td class="r">{{ $money(array_sum(array_column($charts['sucursalRows'] ?? [], 'colocacion'))) }}</td>
            <td class="r">{{ $money(array_sum(array_column($charts['sucursalRows'] ?? [], 'recuperacion'))) }}</td>
            <td class="r">{{ $money(array_sum(array_column($charts['categorias'] ?? [], 'ebitda'))) }}</td>
            <td></td>
          </tr></tfoot>
        </table>
      </div>
    @else
      <div class="empty">Las gráficas comparativas entre sucursales no aplican con una sola sucursal seleccionada (igual que en el Dashboard). Exporta con "Todas las sucursales" para verlas.</div>
    @endif
  @endif
</body>
</html>

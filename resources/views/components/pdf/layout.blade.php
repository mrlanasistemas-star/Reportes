@props(['title' => 'Reporte', 'readyImmediately' => true])
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{{ $title }}</title>
@if($readyImmediately)
<script>window.__PDF_READY__ = true;</script>
@else
<script>window.__PDF_READY__ = false;</script>
@endif
<style>
/* ════════════════════════════════════════════════════════════════════════
   Sistema visual compartido de TODOS los PDF (retoma 21-sep-2026, Parte B) —
   única fuente de estilos, nunca duplicado por reporte. Chrome headless real
   (Browsershot/Puppeteer) renderiza esto — Grid/Flexbox/@page/print-color-adjust
   funcionan de verdad, nunca las limitaciones de un motor viejo tipo DomPDF.
   ════════════════════════════════════════════════════════════════════════ */
* { margin: 0; padding: 0; box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }
@page { margin: 18mm 14mm 22mm 14mm; }

/* ── Header ────────────────────────────────────────────────────────────── */
.pdf-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding-bottom: 10px; margin-bottom: 12px; border-bottom: 2px solid #1f2937; }
.pdf-header .brand-block { display: flex; align-items: center; gap: 10px; }
.pdf-header .brand-mark { font-size: 17pt; font-weight: bold; letter-spacing: .5px; color: #106A59; white-space: nowrap; }
.pdf-header .brand-divider { width: 1px; height: 26px; background: #e2e8f0; }
.pdf-header .report-title { font-size: 10pt; font-weight: bold; color: #1f2937; text-transform: uppercase; letter-spacing: .8px; }
.pdf-header .report-subtitle { font-size: 8pt; color: #64748b; margin-top: 1px; }
.pdf-header .meta-block { text-align: right; font-size: 7.3pt; color: #64748b; line-height: 1.5; }
.pdf-header .meta-block b { color: #1e293b; }

/* ── Secciones ─────────────────────────────────────────────────────────── */
.pdf-section-title { background: #1f2937; color: #fff; padding: 6px 10px; font-size: 9pt; font-weight: bold; letter-spacing: .3px; text-transform: uppercase; margin-top: 14px; margin-bottom: 8px; page-break-after: avoid; page-break-inside: avoid; }
.pdf-section-title.alt { background: #334155; }
.pdf-section-title .subtitle { font-weight: normal; text-transform: none; letter-spacing: 0; font-size: 7.6pt; color: #cbd5e1; margin-top: 1px; }
.pdf-avoid { page-break-inside: avoid; }
.pdf-note { font-size: 7.3pt; color: #64748b; margin-top: 5px; font-style: italic; }

/* ── KPIs ──────────────────────────────────────────────────────────────────
   inline-block a propósito, NUNCA flex/grid aquí (retoma 21-sep-2026, punto
   32/33): Chromium headless renderiza flex/grid perfecto en pantalla, pero su
   motor de paginación de impresión NO fragmenta contenedores flex/grid entre
   páginas de forma confiable — un contenedor flex que no cabe completo se
   empuja ENTERO a la siguiente página, dejando media hoja en blanco (verificado
   empíricamente generando el PDF real). inline-block SÍ fragmenta como texto
   normal, cada tarjeta cae en la página con espacio disponible. ─────────────── */
.pdf-kpi-grid { margin-top: 4px; font-size: 0; }
.pdf-kpi-card { display: inline-block; width: 23.2%; margin: 0 1% 6px 0; vertical-align: top; font-size: 8.5pt; border: 0.75pt solid #d9e2ec; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); border-radius: 6px; padding: 7px 9px; page-break-inside: avoid; }
.pdf-kpi-card .kpi-label { font-size: 6.6pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; }
.pdf-kpi-card .kpi-value { font-size: 11.5pt; font-weight: bold; color: #106A59; margin-top: 3px; }
.pdf-kpi-card .kpi-value.pos  { color: #106A59; }
.pdf-kpi-card .kpi-value.negv { color: #b91c1c; }
.pdf-kpi-card .kpi-sub { font-size: 6.6pt; font-weight: bold; margin-top: 2px; color: #94a3b8; }
.pdf-kpi-card .kpi-sub.pos  { color: #15803d; }
.pdf-kpi-card .kpi-sub.negv { color: #b91c1c; }

/* ── Gráficas — mismo criterio inline-block (ver comentario de .pdf-kpi-grid) ── */
.pdf-charts-row { margin-top: 10px; margin-bottom: 4px; font-size: 0; }
.pdf-chart-card { display: inline-block; width: 48.5%; margin: 0 1.5% 10px 0; vertical-align: top; font-size: 8.5pt; border: 0.75pt solid #e2e8f0; border-radius: 8px; padding: 10px 12px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); page-break-inside: avoid; }
.pdf-chart-card h3 { font-size: 8.3pt; color: #1f2937; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 2px; padding-bottom: 5px; border-bottom: 0.5pt solid #e2e8f0; }
.pdf-chart-card .chart-subtitle { font-size: 6.8pt; color: #94a3b8; margin-bottom: 4px; }
.pdf-chart-card canvas { width: 100% !important; }

/* ── Tablas ────────────────────────────────────────────────────────────── */
table.pdf-table { width: 100%; border-collapse: collapse; font-size: 7.8pt; }
table.pdf-table thead { display: table-header-group; }
table.pdf-table thead th { background: #1f2937; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; border-bottom: 1px solid #1f2937; }
table.pdf-table tbody tr { page-break-inside: avoid; }
table.pdf-table tbody td { padding: 4px 6px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
table.pdf-table tbody tr:nth-child(even) td { background: #f8fafc; }
table.pdf-table tfoot td { background: #1f2937; color: #fff; font-weight: bold; padding: 5px 6px; border-top: 1pt solid #0f172a; }
table.pdf-table .r { text-align: right; }
table.pdf-table .c { text-align: center; }
table.pdf-table .b { font-weight: bold; }
.pos  { color: #15803d; }
.negv { color: #b91c1c; }

/* ── Badges ────────────────────────────────────────────────────────────── */
.pdf-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 7.3pt; font-weight: bold; }
.pdf-badge-diamante  { background: #dbeafe; color: #0369A1; }
.pdf-badge-master    { background: #ede9fe; color: #7C3AED; }
.pdf-badge-senior    { background: #d1fae5; color: #065f46; }
.pdf-badge-junior    { background: #fef3c7; color: #92400e; }
.pdf-badge-mantenido { background: #fee2e2; color: #b91c1c; }
.pdf-badge-neutral   { background: #e2e8f0; color: #475569; }

/* ── Panel resumen / alertas ───────────────────────────────────────────── */
.pdf-summary-panel { border: 0.75pt solid #e2e8f0; border-radius: 8px; padding: 10px 12px; background: #fff; page-break-inside: avoid; }
.pdf-summary-panel h4 { font-size: 7.6pt; text-transform: uppercase; letter-spacing: .4px; color: #64748b; font-weight: bold; margin-bottom: 5px; }
.pdf-summary-panel.tone-ok    { background: #ecfdf5; border-color: #a7f3d0; }
.pdf-summary-panel.tone-warn  { background: #fffbeb; border-color: #fde68a; }
.pdf-summary-panel.tone-alert { background: #fef2f2; border-color: #fecaca; }

/* ── Empty state ───────────────────────────────────────────────────────── */
.pdf-empty-state { padding: 14px 10px; text-align: center; color: #94a3b8; font-size: 7.8pt; font-style: italic; border: 0.75pt dashed #e2e8f0; border-radius: 6px; }

/* ── Gráfica SVG server-side (RadiographyChartSvgBuilder — sucursal/gestor) ─── */
.pdf-svg-chart { margin: 8px 0; padding: 8px 10px; border: 0.75pt solid #e2e8f0; border-radius: 6px; background: #fbfdff; page-break-inside: avoid; }

/* ── Badge grande centrado (categoría EBITDA / efectividad) ─────────────── */
.pdf-big-badge { display: inline-block; padding: 5px 16px; border-radius: 14px; font-size: 10pt; font-weight: bold; letter-spacing: .5px; }

/* ── Barras HTML simples (donde no aplica un chart.js real) ───────────────── */
.pdf-bar-row { margin-bottom: 6px; }
.pdf-bar-label { font-size: 7.4pt; color: #334155; margin-bottom: 2px; }
.pdf-bar-track { background: #e7ecf1; width: 100%; height: 10px; border-radius: 2px; }
.pdf-bar-fill  { height: 10px; border-radius: 2px; display: block; min-width: 2px; }
.pdf-bar-fill-teal  { background: #106A59; }
.pdf-bar-fill-blue  { background: #5B9BD5; }
.pdf-bar-fill-red   { background: #b91c1c; }
.pdf-bar-value { font-size: 7pt; color: #475569; margin-top: 1px; text-align: right; }

/* ── Layout de 2 columnas — inline-block (ver comentario de .pdf-kpi-grid) ── */
.pdf-cols-2 { font-size: 0; }
.pdf-cols-2 > div { display: inline-block; width: 49%; vertical-align: top; font-size: 8.5pt; page-break-inside: avoid; }
.pdf-cols-2 > div:first-child { margin-right: 2%; }

/* ── Footer note (fuera del footer real de Chrome — ver BrowsershotPdfRenderer) ── */
.pdf-footnote { margin-top: 10px; font-size: 7pt; color: #94a3b8; text-align: center; }

{!! $slotStyles ?? '' !!}
</style>
</head>
<body>
{{ $slot }}
</body>
</html>

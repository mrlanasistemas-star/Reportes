<?php
/**
 * Cierre real OKR (04-oct-2026, sección 1/3) — este machote NO es un diseño
 * "inspirado": las 2 páginas de fondo (resources/templates/okr/backgrounds/
 * carta-p{1,2}.png) son el PDF original "CARTA COMPROMISO OKR - MR LANA.pdf"
 * rasterizado 1:1 a 200dpi — pixel-perfect por construcción, nunca
 * reconstruido a mano. Lo ÚNICO que esta vista agrega son los campos
 * dinámicos, posicionados en pt EXACTOS extraídos del PDF original con
 * `pdftotext -bbox` (coordenadas = bounding box real de cada blank "____").
 * Requiere full_bleed=true en BrowsershotPdfRenderer (sin márgenes/footer de
 * Puppeteer — el footer YA está pintado en el fondo).
 */
if (!function_exists('okr_pdf_month_es')) {
function okr_pdf_month_es(int $m): string
{
    return ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$m];
}
function okr_pdf_date_long(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $d = \Illuminate\Support\Carbon::parse($iso);

    return $d->day . ' de ' . okr_pdf_month_es((int) $d->month) . ' de ' . $d->year;
}
function okr_pdf_date_short(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $d = \Illuminate\Support\Carbon::parse($iso);

    return sprintf('%02d/%02d/%04d', $d->day, $d->month, $d->year);
}
/** Clasifica los Key Results del Objective contra las 4 filas FIJAS del machote original — nunca se agregan filas. */
function okr_pdf_classify_krs(array $keyResults): array
{
    $slots = ['colocacion' => null, 'recuperacion' => null, 'ebitda' => null, 'otro' => null];
    foreach ($keyResults as $kr) {
        $norm = \Illuminate\Support\Str::of($kr['kpi_name'] ?? '')->ascii()->upper()->value();
        if (str_contains($norm, 'COLOCAC') && $slots['colocacion'] === null) {
            $slots['colocacion'] = $kr;
        } elseif (str_contains($norm, 'RECUPERAC') && $slots['recuperacion'] === null) {
            $slots['recuperacion'] = $kr;
        } elseif (str_contains($norm, 'EBITDA') && $slots['ebitda'] === null) {
            $slots['ebitda'] = $kr;
        } elseif ($slots['otro'] === null) {
            $slots['otro'] = $kr;
        }
    }

    return $slots;
}
function okr_pdf_money(?float $v): string
{
    return $v === null ? '' : number_format($v, 0, '.', ',');
}
}

$krSlots = okr_pdf_classify_krs($snapshot['key_results'] ?? []);
$bgP1 = base64_encode(file_get_contents(resource_path('templates/okr/backgrounds/carta-p1.png')));
$bgP2 = base64_encode(file_get_contents(resource_path('templates/okr/backgrounds/carta-p2.png')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Carta Compromiso OKR — {{ $folio }}</title>
<script>window.__PDF_READY__ = true;</script>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  @page { size: 612pt 792pt; margin: 0; }
  html, body { width: 612pt; }
  body { font-family: Calibri, 'Segoe UI', Arial, sans-serif; }
  .page { position: relative; width: 612pt; height: 792pt; overflow: hidden; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .page > img.bg { position: absolute; top: 0; left: 0; width: 612pt; height: 792pt; display: block; }
  .ov { position: absolute; color: #0f2a3d; font-weight: 700; white-space: nowrap; overflow: hidden; }
  .ov.wrap { white-space: normal; overflow: hidden; line-height: 11pt; }
  .ov.mark { font-weight: 900; color: #111827; text-align: center; }
  .ov.preview-badge {
    background: #fde68a; color: #92400e; font-size: 7.5pt; font-weight: 800;
    padding: 2pt 6pt; border-radius: 3pt; letter-spacing: .4pt;
  }
</style>
</head>
<body>

<div class="page">
  <img class="bg" src="data:image/png;base64,{{ $bgP1 }}" alt="">

  @if($isPreview)
    <div class="ov preview-badge" style="left:430pt; top:16pt;">VISTA PREVIA</div>
  @endif

  {{-- Lugar y fecha de emisión (texto apoyado SOBRE el blank, no atravesado) --}}
  <div class="ov" style="left:64pt; top:206pt; width:194pt; font-size:8.5pt;">
    {{ $snapshot['place'] }}, {{ okr_pdf_date_long($generatedAt?->toDateString()) }}
  </div>

  {{-- Folio interno --}}
  <div class="ov" style="left:313pt; top:206pt; width:194pt; font-size:8.5pt;">{{ $folio }}</div>

  {{-- I. DATOS DEL COLABORADOR --}}
  <div class="ov" style="left:72pt; top:256pt; width:194pt; font-size:9.3pt;">{{ $snapshot['employee_name'] }}</div>
  <div class="ov" style="left:313pt; top:256pt; width:194pt; font-size:9.3pt;">{{ $snapshot['position'] }}</div>
  <div class="ov" style="left:72pt; top:282pt; width:194pt; font-size:9.3pt;">{{ $snapshot['branch_name'] }}</div>
  <div class="ov" style="left:313pt; top:282pt; width:194pt; font-size:9.3pt;">{{ $snapshot['responsible_name'] }}</div>

  {{-- II. PERIODO --}}
  <div class="ov" style="left:64pt; top:336pt; width:194pt; font-size:9.3pt;">{{ okr_pdf_date_short($snapshot['start_date']) }}</div>
  <div class="ov" style="left:313pt; top:336pt; width:194pt; font-size:9.3pt;">{{ okr_pdf_date_short($snapshot['end_date']) }}</div>

  {{-- OKR / Objetivo principal --}}
  <div class="ov wrap" style="left:58pt; top:468pt; width:393pt; height:22pt; font-size:9pt;">{{ $snapshot['objective_title'] }}</div>

  {{-- KPI y meta comprometida --}}
  @foreach(['colocacion' => 525.3, 'recuperacion' => 539.3, 'ebitda' => 553.2, 'otro' => 567.1] as $slot => $rowY)
    @php($kr = $krSlots[$slot])
    @if($kr)
      <div class="ov mark" style="left:195.5pt; top:{{ $rowY - 1 }}pt; width:7pt; font-size:8pt;">X</div>
      <div class="ov" style="left:{{ $slot === 'otro' ? 269 : 273 }}pt; top:{{ $rowY }}pt; width:{{ $slot === 'otro' ? 80 : 80 }}pt; font-size:9pt;">
        @if($slot !== 'otro'){{ okr_pdf_money($kr['target_value'] ?? null) }}@else ${{ okr_pdf_money($kr['target_value'] ?? null) }}@endif
      </div>
    @else
      <div class="ov mark" style="left:219.4pt; top:{{ $rowY - 1 }}pt; width:7pt; font-size:8pt;">X</div>
    @endif
  @endforeach
</div>

<div class="page">
  <img class="bg" src="data:image/png;base64,{{ $bgP2 }}" alt="">
</div>

</body>
</html>

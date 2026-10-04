<?php
/**
 * Cierre real OKR (04-oct-2026, sección 10/11) — mismas reglas que
 * okr-commitment-letter-pdf.blade.php: fondo = las 2 páginas del PDF
 * original "WARNING ROJO - LLAMADA DE ATENCIÓN - MR LANA.pdf" rasterizadas
 * 1:1, overlay posicionado en pt exactos extraídos con `pdftotext -bbox`.
 * Requiere full_bleed=true.
 */
if (!function_exists('okr_wpdf_money')) {
function okr_wpdf_money(?float $v): string
{
    return $v === null ? '—' : number_format($v, 0, '.', ',');
}
function okr_wpdf_pct(?float $v): string
{
    return $v === null ? '—' : number_format($v, 1, '.', ',');
}
function okr_wpdf_date_parts(?string $iso): array
{
    if (!$iso) {
        return ['', '', ''];
    }
    $d = \Illuminate\Support\Carbon::parse($iso);

    return [sprintf('%02d', $d->day), sprintf('%02d', $d->month), (string) $d->year];
}
/** Clasifica las filas del snapshot contra las 4 filas FIJAS del machote original. */
function okr_wpdf_classify_rows(array $rows): array
{
    $slots = ['colocacion' => null, 'recuperacion' => null, 'ebitda' => null, 'otro' => null];
    foreach ($rows as $row) {
        $norm = \Illuminate\Support\Str::of($row['kpi_name'] ?? '')->ascii()->upper()->value();
        if (str_contains($norm, 'COLOCAC') && $slots['colocacion'] === null) {
            $slots['colocacion'] = $row;
        } elseif (str_contains($norm, 'RECUPERAC') && $slots['recuperacion'] === null) {
            $slots['recuperacion'] = $row;
        } elseif (str_contains($norm, 'EBITDA') && $slots['ebitda'] === null) {
            $slots['ebitda'] = $row;
        } elseif ($slots['otro'] === null) {
            $slots['otro'] = $row;
        }
    }

    return $slots;
}
}

$rowSlots = okr_wpdf_classify_rows($snapshot['rows'] ?? []);
[$genD, $genM, $genY] = okr_wpdf_date_parts($generatedAt?->toDateString());
[$wsD, $wsM, $wsY] = okr_wpdf_date_parts($snapshot['week_start'] ?? null);
[$weD, $weM, $weY] = okr_wpdf_date_parts($snapshot['week_end'] ?? null);
[$ltD, $ltM, $ltY] = okr_wpdf_date_parts($snapshot['letter_date'] ?? null);
$bgP1 = base64_encode(file_get_contents(resource_path('templates/okr/backgrounds/warning-p1.png')));
$bgP2 = base64_encode(file_get_contents(resource_path('templates/okr/backgrounds/warning-p2.png')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Warning Rojo OKR — {{ $folio }}</title>
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
  .ov.c { text-align: center; }
</style>
</head>
<body>

<div class="page">
  <img class="bg" src="data:image/png;base64,{{ $bgP1 }}" alt="">

  {{-- FOLIO / FECHA --}}
  <div class="ov" style="left:150pt; top:209pt; width:74pt; font-size:8.5pt;">{{ $folio }}</div>
  <div class="ov c" style="left:373pt; top:209pt; width:15pt; font-size:8.5pt;">{{ $genD }}</div>
  <div class="ov c" style="left:397pt; top:209pt; width:15pt; font-size:8.5pt;">{{ $genM }}</div>
  <div class="ov c" style="left:421pt; top:209pt; width:25pt; font-size:8.5pt;">{{ $genY }}</div>

  {{-- COLABORADOR / PUESTO --}}
  <div class="ov" style="left:150pt; top:224.5pt; width:150pt; font-size:9pt;">{{ $snapshot['employee_name'] }}</div>
  <div class="ov" style="left:373pt; top:224.5pt; width:117pt; font-size:9pt;">{{ $snapshot['position'] }}</div>

  {{-- SUCURSAL / ÁREA --}}
  <div class="ov" style="left:150pt; top:250pt; width:116pt; font-size:9pt;">{{ $snapshot['branch_name'] }}</div>

  {{-- PERIODO EVALUADO: Del DD/MM/YYYY al DD/MM/YYYY --}}
  <div class="ov c" style="left:390pt; top:250pt; width:15pt; font-size:8.5pt;">{{ $wsD }}</div>
  <div class="ov c" style="left:415pt; top:250pt; width:15pt; font-size:8.5pt;">{{ $wsM }}</div>
  <div class="ov c" style="left:439pt; top:250pt; width:15pt; font-size:8.5pt;">{{ $wsY }}</div>
  <div class="ov c" style="left:468pt; top:250pt; width:15pt; font-size:8.5pt;">{{ $weD }}</div>
  <div class="ov c" style="left:492pt; top:250pt; width:15pt; font-size:8.5pt;">{{ $weM }}</div>
  <div class="ov c" style="left:373pt; top:260.5pt; width:16pt; font-size:8.5pt;">{{ $weY }}</div>

  {{-- Referencia a la Carta Compromiso original (solo si ya existe) --}}
  @if($snapshot['letter_date'] ?? null)
    <div class="ov c" style="left:537pt; top:336pt; width:16pt; font-size:9pt;">{{ $ltD }}</div>
    <div class="ov c" style="left:63pt; top:349pt; width:18pt; font-size:9pt;">{{ $ltM }}</div>
    <div class="ov c" style="left:92pt; top:349pt; width:30pt; font-size:9pt;">{{ $ltY }}</div>
  @endif

  {{-- OKR / Objetivo evaluado --}}
  <div class="ov wrap" style="left:63pt; top:401pt; width:357pt; height:22pt; font-size:9pt;">{{ $snapshot['objective_title'] }}</div>

  {{-- RESULTADO DEL PERIODO --}}
  @foreach(['colocacion' => 466.9, 'recuperacion' => 482.6, 'ebitda' => 498.3, 'otro' => 514.2] as $slot => $rowY)
    @php($row = $rowSlots[$slot])
    @if($row)
      {{-- el "$" ya está impreso en el fondo original — nunca duplicarlo aquí --}}
      <div class="ov" style="left:170pt; top:{{ $rowY }}pt; width:63pt; font-size:9pt;">{{ okr_wpdf_money($row['target_value'] ?? null) }}</div>
      <div class="ov" style="left:275pt; top:{{ $rowY }}pt; width:63pt; font-size:9pt;">{{ $row['has_data'] ? okr_wpdf_money($row['actual_value']) : 'Sin datos' }}</div>
      <div class="ov c" style="left:391pt; top:{{ $rowY }}pt; width:30pt; font-size:9pt;">{{ $row['has_data'] ? okr_wpdf_pct($row['compliance_percentage']) : '—' }}</div>
      <div class="ov" style="left:485pt; top:{{ $rowY }}pt; width:63pt; font-size:9pt;">{{ $row['has_data'] ? okr_wpdf_money($row['gap']) : '—' }}</div>
      @if($slot === 'otro')
        <div class="ov" style="left:71pt; top:{{ $rowY }}pt; width:38pt; font-size:7.5pt;">{{ \Illuminate\Support\Str::limit($row['kpi_name'] ?? '', 14, '') }}</div>
      @endif
    @endif
  @endforeach
</div>

<div class="page">
  <img class="bg" src="data:image/png;base64,{{ $bgP2 }}" alt="">
</div>

</body>
</html>

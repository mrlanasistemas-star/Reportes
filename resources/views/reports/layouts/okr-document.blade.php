<?php
/**
 * Layout base de los documentos OKR (Carta Compromiso / Warning) — 14-oct-2026.
 *
 * Antes cada documento era el PDF original rasterizado como fondo con textos
 * en position:absolute encima: cualquier nombre/objetivo largo pisaba
 * etiquetas, líneas y casillas. Ahora el contenido fluye como HTML real
 * (tablas, grid, saltos de página) y del machote original solo se conservan
 * los elementos corporativos DECORATIVOS, recortados 1:1 del original
 * (resources/templates/okr/branding/*.png): ola superior con logotipo, pie
 * con dirección y marca de agua "ML".
 *
 * - Encabezado/pie se repiten en cada página con position:fixed; el
 *   <thead>/<tfoot> espaciador de .doc-frame reserva su alto en CADA página
 *   (técnica estándar de impresión en Chrome) — el texto nunca queda debajo.
 * - Montserrat (SIL OFL 1.1, resources/fonts/montserrat/OFL.txt) embebida en
 *   base64: Chrome no necesita internet ni Google Fonts para imprimir.
 * - window.__PDF_READY__ se activa hasta que document.fonts confirma la
 *   tipografía — BrowsershotPdfRenderer espera esa señal (full_bleed=true).
 */
$okrDocFonts = '';
foreach ([400, 500, 600, 700, 800] as $weight) {
    $fontPath = resource_path("fonts/montserrat/montserrat-latin-{$weight}-normal.woff2");
    if (is_file($fontPath)) {
        $okrDocFonts .= "@font-face{font-family:'Montserrat';font-style:normal;font-weight:{$weight};font-display:block;"
            . "src:url(data:font/woff2;base64," . base64_encode(file_get_contents($fontPath)) . ") format('woff2');}\n";
    }
}
$okrDocImage = fn (string $name) => 'data:image/png;base64,' . base64_encode(file_get_contents(resource_path("templates/okr/branding/{$name}.png")));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>@yield('title')</title>
<script>
  window.__PDF_READY__ = false;
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () { window.__PDF_READY__ = true; });
  setTimeout(function () { window.__PDF_READY__ = true; }, 4000);
</script>
<style>
{!! $okrDocFonts !!}
  * { margin: 0; padding: 0; box-sizing: border-box; }
  @page { size: 612pt 792pt; margin: 0; }
  html, body { width: 612pt; background: #fff; }
  body {
    font-family: 'Montserrat', sans-serif; font-size: 9.3pt; line-height: 1.5; color: #1f2933;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  b, strong { font-weight: 700; color: #111827; }

  /* Elementos corporativos fijos (se repiten en cada página impresa). */
  .doc-header { position: fixed; top: 0; left: 0; width: 612pt; height: 144pt; z-index: 0; }
  .doc-footer { position: fixed; bottom: 14pt; left: 0; width: 612pt; height: 43.2pt; z-index: 0; }
  .doc-watermark { position: fixed; top: 300pt; left: 196pt; width: 220pt; z-index: 0; }
  .doc-header img, .doc-footer img { width: 100%; height: 100%; display: block; }

  /* Marco de paginación: thead/tfoot reservan el espacio de encabezado/pie en cada página. */
  table.doc-frame { width: 100%; border-collapse: collapse; position: relative; z-index: 1; }
  .doc-frame > thead > tr > td { height: 126pt; }
  .doc-frame > tfoot > tr > td { height: 66pt; }
  .doc-frame > tbody > tr > td { padding: 0 56pt; vertical-align: top; }

  .doc-title { text-align: center; margin-bottom: 14pt; }
  .doc-title h1 { font-size: 19pt; font-weight: 700; letter-spacing: .3pt; color: #1f2329; line-height: 1.25; }
  .doc-title p { font-size: 10pt; font-weight: 600; color: #6b7280; letter-spacing: .4pt; }

  .section { margin-top: 11pt; }
  .keep { break-inside: avoid; }
  .keep-next { break-after: avoid; }
  .section-title {
    font-size: 10.5pt; font-weight: 700; color: #1f2933; text-transform: uppercase;
    border-bottom: 1pt solid #4b5563; padding-bottom: 2pt; margin-bottom: 7pt; letter-spacing: .2pt;
  }
  .label { font-size: 8.2pt; font-weight: 700; color: #374151; letter-spacing: .15pt; }
  .value {
    display: block; min-height: 16pt; padding: 2pt 0 2pt; border-bottom: .8pt solid #6b7280;
    font-size: 9.6pt; font-weight: 600; color: #0f2a3d; line-height: 1.35; word-break: break-word;
  }
  .value.pending { color: #b45309; font-style: italic; font-weight: 600; border-bottom-color: #f59e0b; }

  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; column-gap: 26pt; row-gap: 8pt; }
  .boxed { border: .8pt solid #cbd5e1; display: grid; grid-template-columns: 1fr 1fr; }
  .boxed > div { padding: 6pt 9pt 7pt; }
  .boxed > div:nth-child(odd) { border-right: .8pt solid #cbd5e1; }
  .boxed > div:nth-child(n+3) { border-top: .8pt solid #cbd5e1; }

  p.body { text-align: justify; margin-top: 6pt; }
  p.muted { color: #6b7280; }

  table.data { width: 100%; border-collapse: collapse; margin-top: 6pt; font-size: 8.6pt; break-inside: auto; }
  table.data th { background: #3a3a3a; color: #fff; font-weight: 700; text-transform: uppercase; font-size: 8pt; padding: 4pt 6pt; text-align: center; letter-spacing: .2pt; }
  table.data td { border: .8pt solid #d1d5db; padding: 5pt 6pt; vertical-align: middle; line-height: 1.35; }
  table.data tr { break-inside: avoid; }
  table.data td.kpi { font-weight: 700; color: #111827; }
  table.data td.num { text-align: right; font-weight: 700; color: #0f2a3d; white-space: nowrap; }
  table.data td.center { text-align: center; }
  table.data td.note { font-size: 7.6pt; color: #4b5563; }

  .check { display: inline-flex; align-items: center; gap: 3pt; margin-right: 7pt; white-space: nowrap; }
  .check i { display: inline-block; width: 8.5pt; height: 8.5pt; border: .9pt solid #374151; position: relative; font-style: normal; }
  .check i.on::after { content: ''; position: absolute; left: 2.4pt; top: .3pt; width: 2.6pt; height: 5pt; border: solid #0f2a3d; border-width: 0 1.5pt 1.5pt 0; transform: rotate(45deg); }

  .blank-line { display: inline-block; min-width: 80pt; border-bottom: .8pt solid #6b7280; height: 11pt; vertical-align: bottom; }
  .ruled { border-bottom: .8pt solid #9ca3af; height: 17pt; }

  .signatures { display: grid; grid-template-columns: repeat(3, 1fr); column-gap: 22pt; margin-top: 30pt; break-inside: avoid; text-align: center; }
  .signatures .line { border-top: .9pt solid #1f2933; padding-top: 4pt; }
  .signatures .role { font-weight: 700; font-size: 8.6pt; color: #1f2933; text-transform: uppercase; }
  .signatures .name { font-size: 8pt; color: #374151; font-weight: 600; min-height: 11pt; }
  .signatures .hint { font-size: 7.6pt; color: #6b7280; }

  .badge-preview {
    position: fixed; top: 16pt; right: 22pt; z-index: 5; background: #fde68a; color: #92400e;
    font-size: 7.5pt; font-weight: 800; padding: 3pt 8pt; border-radius: 3pt; letter-spacing: .5pt;
  }
  .avoid-break { break-inside: avoid; }
  .page-break { break-before: page; }
@stack('styles')
</style>
</head>
<body>
  <div class="doc-header"><img src="{{ $okrDocImage('header') }}" alt=""></div>
  <img class="doc-watermark" src="{{ $okrDocImage('watermark') }}" alt="">
  <div class="doc-footer"><img src="{{ $okrDocImage('footer') }}" alt=""></div>
  @yield('badge')

  <table class="doc-frame">
    <thead><tr><td></td></tr></thead>
    <tfoot><tr><td></td></tr></tfoot>
    <tbody><tr><td>
      @yield('content')
    </td></tr></tbody>
  </table>
</body>
</html>

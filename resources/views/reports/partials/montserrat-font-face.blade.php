<?php
/**
 * Montserrat LOCAL para PDFs de Browsershot (SIL OFL 1.1,
 * resources/fonts/montserrat/OFL.txt): pesos 400–800 embebidos en base64
 * dentro del propio HTML — Chrome no necesita internet ni Google Fonts para
 * imprimir. Se incluye DENTRO de un <style>. Si algún archivo faltara, la
 * regla se omite y aplica el fallback declarado por la vista (sans-serif).
 */
$montserratCss = '';
foreach ([400, 500, 600, 700, 800] as $weight) {
    $fontPath = resource_path("fonts/montserrat/montserrat-latin-{$weight}-normal.woff2");
    if (is_file($fontPath)) {
        $montserratCss .= "@font-face{font-family:'Montserrat';font-style:normal;font-weight:{$weight};font-display:block;"
            . 'src:url(data:font/woff2;base64,' . base64_encode(file_get_contents($fontPath)) . ") format('woff2');}\n";
    }
}
?>
{!! $montserratCss !!}

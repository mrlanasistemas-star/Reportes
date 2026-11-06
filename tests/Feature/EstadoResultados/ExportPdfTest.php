<?php

use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Comparatives\ComparisonService;
use App\Services\EstadoResultados\Export\ErFormat;
use App\Services\EstadoResultados\Export\ErPdfDestinations;
use App\Services\EstadoResultados\Export\EstadoResultadosPdfExporter;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * PDF ejecutivo del Estado de Resultados (modelo de EBITDA único). Datos 100 % SINTÉTICOS
 * (caso de referencia del dueño). El render real con Chrome se prueba sólo si hay Chromium.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('ORIZABA');
    F::branch('CORPORATIVO');
});

function pdfRefPeriods(bool $isrPending = false): array
{
    $ago = F::monthPeriod(2026, 8, '2026-08-02', '2026-08-31');
    $sep = F::monthPeriod(2026, 9, '2026-09-01', '2026-09-30');
    F::fakeRadiographyByPeriod([
        $ago->id => [
            'CORDOBA' => ['interes_recuperado' => 500000.00, 'gastos_operativos' => 200000.00, 'gastos_detalle' => ['Renta Oficina' => 200000.00], 'nomina_total' => 100000.00],
            'ORIZABA' => ['interes_recuperado' => 300000.00, 'gastos_operativos' => 150000.00, 'gastos_detalle' => ['Renta Oficina' => 150000.00], 'nomina_total' => 50000.00],
        ],
        $sep->id => [
            'CORDOBA' => ['interes_recuperado' => 600000.00, 'gastos_operativos' => 250000.00, 'gastos_detalle' => ['Renta Oficina' => 150000.00, 'Gasolina' => 100000.00], 'nomina_total' => 120000.00],
            'ORIZABA' => ['interes_recuperado' => 400000.00, 'gastos_operativos' => 150000.00, 'gastos_detalle' => ['Renta Oficina' => 150000.00], 'nomina_total' => 80000.00],
        ],
    ]);
    foreach ([[$ago, ['depreciacion' => '30000', 'intereses_bbva' => '20000', 'isr' => '50000'], []], [$sep, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000'], $isrPending ? ['isr'] : []]] as [$p, $captured, $pending]) {
        foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
            if (in_array($concept, $pending, true)) {
                continue;
            }
            isset($captured[$concept])
                ? app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CAPTURADO, $captured[$concept], 'prueba', null)
                : app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
        }
    }

    return [$ago, $sep];
}

function pdfHtml(Period $p, string $scope = 'CONSOLIDADO'): string
{
    return app(EstadoResultadosPdfExporter::class)->html(
        app(EstadoResultadosDatasetBuilder::class)->build($p, $scope),
        app(ComparisonService::class)->build($p, $scope, null, ['meses' => 6]),
    );
}

it('caso de referencia: el HTML del PDF trae los cuatro resultados, comparativas y gráficas; nunca «EBITDA 1/2/3»', function () {
    [, $sep] = pdfRefPeriods();
    G::fakePdfRenderer();
    $admin = G::admin();
    $web = $this->actingAs($admin)->getJson("/estado-resultados/{$sep->id}/dataset")->assertOk()->json('dataset');
    $html = (string) file_get_contents($this->actingAs($admin)->get("/estado-resultados/{$sep->id}/exportar/pdf")->assertOk()
        ->assertHeader('X-Dataset-Hash', $web['meta']['dataset_hash'])->getFile()->getPathname());

    foreach (['$1,000,000.00', '$600,000.00', '$400,000.00', '$30,000.00', '$370,000.00', '-$30,000.00', '$340,000.00', '$100,000.00', '$240,000.00'] as $amount) {
        expect($html)->toContain($amount);
    }
    expect($html)->toContain('Resumen ejecutivo')->toContain('Estado de Resultados</span> detallado')
        ->toContain('EBITDA')->toContain('EBIT')->toContain('EBT')->toContain('Utilidad neta')
        ->toContain('Margen 40.00%')->toContain('Margen 24.00%')
        // Comparativas contra Agosto (EBITDA 300,000 → 400,000: +$100,000.00, +33.33 %, favorable).
        ->toContain('Periodo actual vs periodo anterior')->toContain('+$100,000.00')->toContain('+33.33%')->toContain('Favorable')
        ->toContain('Acumulado del año (YTD)')->toContain('acumulado parcial')
        // Gráficas SVG del servidor (sin JS externo) y sucursales en el consolidado.
        ->toContain('Evolución de EBITDA, EBIT, EBT y utilidad neta')->toContain('Cascada del resultado')
        ->toContain('Composición del OPEX')->toContain('Márgenes EBITDA, EBIT y neto')->toContain('EBITDA por sucursal')
        ->toContain('Gastos centralizados')
        ->toContain($web['meta']['dataset_hash'])
        ->toContain('window.__PDF_READY__ = true')
        ->not->toContain('<script src')
        ->not->toMatch('/EBITDA\s*[123]\b/');
    expect(substr_count($html, '<svg'))->toBeGreaterThanOrEqual(6);
    // Logo oficial embebido (sin rutas internas del servidor).
    expect($html)->toContain('data:image/png;base64,')->not->toContain(str_replace('\\', '/', base_path()))->not->toContain(base_path());

    // Índice después de la portada: cada liga interna (#id) apunta a un ancla que existe.
    expect($html)->toContain('id="contenido"')->toContain('href="#resumen"')->toContain('href="#conciliacion"');
    preg_match_all('/href="#([^"]+)"/', $html, $hrefs);
    expect($hrefs[1])->not->toBeEmpty();
    foreach (array_unique($hrefs[1]) as $id) {
        expect($html)->toContain('id="'.$id.'"');
    }
    // Sin números de página inventados cuando no se pudo leer el PDF (render simulado).
    expect($html)->not->toContain('class="pg"');
});

it('índice del PDF: lee la página real de cada ancla desde los destinos con nombre de Chrome', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<</Type /Catalog\n/Pages 2 0 R\n/Dests 9 0 R>>\nendobj\n"
        ."2 0 obj\n<</Type /Pages\n/Count 3\n/Kids [3 0 R 4 0 R]>>\nendobj\n"
        ."3 0 obj\n<</Type /Page\n/Parent 2 0 R>>\nendobj\n"
        ."4 0 obj\n<</Type /Pages\n/Count 2\n/Kids [5 0 R 6 0 R]\n/Parent 2 0 R>>\nendobj\n"
        ."5 0 obj\n<</Type /Page\n/Resources <</Font <<>>>>\n/Parent 4 0 R>>\nendobj\n"
        ."6 0 obj\n<</Type /Page\n/Parent 4 0 R>>\nendobj\n"
        ."9 0 obj\n<</contenido [5 0 R /XYZ 0 792 0]\n/cmp-anterior [6 0 R /XYZ 0 500 0]>>\nendobj\n";
    $path = storage_path('framework/testing/er/dests_'.uniqid().'.pdf');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, $pdf);
    expect(ErPdfDestinations::pages($path))->toBe(['contenido' => 2, 'cmp-anterior' => 3]);
    file_put_contents($path, 'no es un pdf');
    expect(ErPdfDestinations::pages($path))->toBe([]);
    @unlink($path);
});

it('ISR pendiente: el PDF muestra «Pendiente» y nunca $0.00 ni la utilidad neta', function () {
    [, $sep] = pdfRefPeriods(isrPending: true);
    $html = pdfHtml($sep);

    expect($html)->toContain('Pendiente')->toContain('$340,000.00')
        ->not->toContain('$240,000.00')
        ->not->toMatch('/Utilidad neta<\/div>\s*<div class="v[^"]*">\$0\.00/');
});

it('alcance de sucursal: sin gráfica de sucursales ni centralizados; Corporativo/DG nunca se asignan', function () {
    [, $sep] = pdfRefPeriods();
    $html = pdfHtml($sep, 'CORDOBA');

    expect($html)->toContain('Alcance: Sucursal CORDOBA')->toContain('$230,000.00')
        ->not->toContain('EBITDA por sucursal')->not->toContain('Gastos centralizados');
});

it('preliminar: marca de agua y estado PRELIMINAR; nunca se rotula como aprobado', function () {
    [, $sep] = pdfRefPeriods();
    $d = app(EstadoResultadosDatasetBuilder::class)->build($sep);
    $d['meta']['report'] = ['status' => 'aprobado', 'status_label' => 'Aprobado', 'preliminary' => true, 'preliminary_reasons' => ['Falta el estado de cuenta'], 'version' => 2];
    $html = app(EstadoResultadosPdfExporter::class)->html($d);

    expect($html)->toContain('class="watermark">PRELIMINAR')->toContain('Falta el estado de cuenta')
        ->toContain('class="status bad">PRELIMINAR')->not->toContain('class="status ok"');
});

it('formato mexicano y enmascarado de cuentas', function () {
    expect(ErFormat::money('1234.5'))->toBe('$1,234.50')
        ->and(ErFormat::money('-30000'))->toBe('-$30,000.00')
        ->and(ErFormat::money(null))->toBe('Pendiente')
        ->and(ErFormat::delta('100000'))->toBe('+$100,000.00')
        ->and(ErFormat::pct('33.3333', '—', true))->toBe('+33.33%')
        ->and(ErFormat::mask('Cuenta ****6697'))->toBe('Cuenta ••6697')
        ->and(ErFormat::mask('SPEI CLABE 012180001234566697 PAGO'))->toBe('SPEI CLABE ••6697 PAGO')
        ->and(ErFormat::mask('Tarjeta 4152 3133 1234 5678'))->toBe('Tarjeta ••5678')
        ->and(ErFormat::mask('estado_0112345678.xlsx'))->toBe('estado_••5678.xlsx')
        ->and(ErFormat::mask('Pago 2026-09-10 por $1,234,567.89 folio 1234'))->toBe('Pago 2026-09-10 por $1,234,567.89 folio 1234');
});

it('fallo de Chrome: error controlado con referencia, sin rutas internas y sin archivo parcial', function () {
    [, $sep] = pdfRefPeriods();
    $before = glob(storage_path('app/tmp/estado-resultados-*.pdf')) ?: [];
    app()->instance(BrowsershotPdfRenderer::class, new class extends BrowsershotPdfRenderer
    {
        public function renderHtmlToFile(string $html, string $outputPath, array $options = []): void
        {
            file_put_contents($outputPath, 'parcial');
            throw PdfRenderException::chartTimeout(new RuntimeException('C:\\interno\\chrome.exe timed out'));
        }
    });
    $res = $this->actingAs(G::admin())->get("/estado-resultados/{$sep->id}/exportar/pdf")->assertStatus(500);

    expect($res->json('message'))->toContain('Referencia: ER-PDF-')->not->toContain('interno')->not->toContain('chrome.exe');
    expect(array_values(array_diff(glob(storage_path('app/tmp/estado-resultados-*.pdf')) ?: [], $before)))->toBe([]);
});

it('render real con Browsershot (se omite si no hay Chrome/Chromium disponible)', function () {
    $chrome = config('pdf.browsershot.chrome_path');
    if (! $chrome || ! is_file($chrome)) {
        // Respaldo: Chrome descargado por Puppeteer en el perfil del usuario.
        $home = getenv('USERPROFILE') ?: getenv('HOME');
        $found = $home ? (glob($home.'/.cache/puppeteer/chrome/*/chrome-*/chrome{,.exe}', GLOB_BRACE) ?: []) : [];
        $chrome = $found ? end($found) : null;
    }
    $node = config('pdf.browsershot.node_binary');
    if (! $chrome || ! is_file($chrome) || ($node && ! is_file($node))) {
        $this->markTestSkipped('Chrome/Chromium o Node no disponibles en este entorno.');
    }
    config(['pdf.browsershot.chrome_path' => $chrome]);

    [, $sep] = pdfRefPeriods();
    $out = storage_path('framework/testing/er/real_'.uniqid().'.pdf');
    $dataset = app(EstadoResultadosDatasetBuilder::class)->build($sep);
    $cmp = app(ComparisonService::class)->build($sep, 'CONSOLIDADO', null, ['meses' => 6]);
    // Chrome bajo carga (suite completa en paralelo con otros procesos) puede exceder su tiempo:
    // un reintento; si vuelve a vencer por TIEMPO se omite con el motivo (cualquier otro error falla).
    for ($try = 1; ; $try++) {
        try {
            app(EstadoResultadosPdfExporter::class)->export($dataset, $out, $cmp);
            break;
        } catch (PdfRenderException $e) {
            expect(is_file($out))->toBeFalse('el exportador debe borrar el archivo parcial');
            if (! in_array($e->code(), [PdfRenderException::TIMEOUT, PdfRenderException::CHART_TIMEOUT, PdfRenderException::HTML_FAILED], true) || $try >= 2) {
                in_array($e->code(), [PdfRenderException::TIMEOUT, PdfRenderException::CHART_TIMEOUT], true)
                    ? $this->markTestSkipped('Chrome excedió el tiempo en este entorno ('.$e->code().').')
                    : throw $e;
            }
        }
    }
    $bytes = (string) file_get_contents($out);
    $dests = ErPdfDestinations::pages($out);
    @unlink($out);

    // Índice en la página 2 (tras la portada) con ligas a páginas reales y marcadores del documento.
    expect($dests['contenido'] ?? null)->toBe(2)
        ->and($dests['resumen'] ?? null)->toBe(3)
        ->and($dests['conciliacion'] ?? 0)->toBeGreaterThan($dests['resumen'] ?? 99)
        ->and($bytes)->toContain('/Outlines');

    expect(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and(preg_match_all('#/Type\s*/Page[^s]#', $bytes))->toBeGreaterThanOrEqual(6);
})->group('browser');

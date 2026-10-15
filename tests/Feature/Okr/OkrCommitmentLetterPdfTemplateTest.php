<?php

use Illuminate\Support\Carbon;

/**
 * Carta Compromiso rehecha 14-oct-2026 (HTML de flujo, sin textos en
 * position:absolute sobre el machote rasterizado). Red de seguridad SIN
 * Chrome: renderiza el blade real que usa Browsershot y verifica estructura,
 * tipografía local y formato. La revisión VISUAL se hizo generando PDFs
 * reales con Browsershot (nombres/objetivos largos, montos grandes, campos
 * vacíos) — ver resumen de la sesión.
 */
function okrLetterHtml(array $overrides = [], bool $isPreview = false): string
{
    $snapshot = array_merge([
        'place' => 'Cuernavaca, Morelos',
        'position' => 'GESTOR',
        'objective_title' => 'Incrementar colocación de crédito en la sucursal Cuernavaca',
        'scope_type' => 'employee',
        'branch_name' => 'CUERNAVACA',
        'collaborator_name' => 'JUAN PÉREZ',
        'employee_name' => 'JUAN PÉREZ',
        'employee_code' => 'CUE01234',
        'immediate_boss' => 'María Fernanda López',
        'responsible_name' => 'María Fernanda López',
        'start_date' => '2026-10-05',
        'end_date' => '2026-11-01',
        'duration_weeks' => 4,
        'key_results' => [
            ['kpi_name' => 'Colocación', 'description' => 'Colocación individual', 'unit' => 'currency', 'baseline_value' => 638576, 'target_value' => 838576.0, 'weight' => 100],
        ],
    ], $overrides);

    return view('reports.okr-commitment-letter-pdf', [
        'folio' => 'CC-0001-TEST', 'snapshot' => $snapshot, 'generatedAt' => Carbon::parse('2026-10-04'),
        'generatedByName' => 'Admin Test', 'isPreview' => $isPreview,
    ])->render();
}

it('renders the commitment letter with the Objective data in flowing HTML (no absolute overlays) and Montserrat embedded locally', function () {
    $html = okrLetterHtml();

    expect($html)->toContain('JUAN PÉREZ')
        ->toContain('CUE01234')
        ->toContain('GESTOR')
        ->toContain('CUERNAVACA')
        ->toContain('María Fernanda López')
        ->toContain('CC-0001-TEST')
        ->toContain('05/10/2026')
        ->toContain('01/11/2026')
        ->toContain('4 de octubre de 2026')
        ->toContain('Incrementar colocación de crédito en la sucursal Cuernavaca');

    // Formato monetario mexicano: nunca "838576" crudo.
    expect($html)->toContain('$838,576.00')->toContain('$638,576.00')->toContain('+$200,000.00');

    // Montserrat embebida (sin Google Fonts / internet).
    expect($html)->toContain("font-family:'Montserrat'")->toContain('data:font/woff2;base64,')
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('Calibri');

    // Sin el machote rasterizado de página completa con overlays absolutos.
    expect($html)->not->toContain('class="ov')->not->toContain('carta-p1');
    // Elementos corporativos conservados (encabezado, pie, marca de agua).
    expect(substr_count($html, 'data:image/png;base64,'))->toBe(3);

    // Casillas Aplica: Colocación Sí; Recuperación/EBITDA/Otro No.
    expect(substr_count($html, '<i class="on"></i>'))->toBe(4);
    // Firmas completas.
    expect($html)->toContain('Colaborador')->toContain('Jefe inmediato / Responsable')->toContain('Vo. Bo. MR LANA');
});

it('marks missing data as pending in the preview instead of leaving blanks under labels', function () {
    // employee_code ausente también cuenta como "Pendiente de captura"
    // (15-oct-2026: Número de empleado en la carta).
    $html = okrLetterHtml(['position' => null, 'immediate_boss' => null, 'responsible_name' => null, 'employee_code' => null], isPreview: true);

    expect(substr_count($html, 'Pendiente de captura'))->toBe(3);
    expect($html)->toContain('VISTA PREVIA');
});

it('keeps every Key Result: extra KPIs get their own rows with their unit (never dropped)', function () {
    $html = okrLetterHtml(['key_results' => [
        ['kpi_name' => 'Colocación', 'unit' => 'currency', 'baseline_value' => 100000, 'target_value' => 130000, 'weight' => 50],
        ['kpi_name' => 'Mora', 'unit' => 'percentage', 'baseline_value' => 12.5, 'target_value' => 9.75, 'weight' => 30],
        ['kpi_name' => 'Número de Clientes', 'unit' => 'integer', 'baseline_value' => 120, 'target_value' => 150, 'weight' => 20],
    ]]);

    expect($html)->toContain('Otro KPI: Mora')->toContain('9.75%')->toContain('-2.75 pp')
        ->toContain('Otro KPI: Número de Clientes')->toContain('$130,000.00');
});

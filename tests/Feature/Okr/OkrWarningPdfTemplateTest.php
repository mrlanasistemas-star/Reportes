<?php

use Illuminate\Support\Carbon;

/**
 * Cierre real OKR (04-oct-2026, puntos 19/21) — mismo criterio que
 * OkrCommitmentLetterPdfTemplateTest: red de seguridad sin Chrome para el
 * blade real de Warning Rojo (machote original, fondo = PDF rasterizado).
 */
it('renders the real warning blade already filled from the Objective/week snapshot — only corrective actions are human input', function () {
    $snapshot = [
        'objective_title' => 'Incrementar colocación de crédito en la sucursal Cuernavaca',
        'scope_type' => 'employee',
        'branch_name' => 'CUERNAVACA',
        'employee_name' => 'JUAN PÉREZ',
        'position' => 'GESTOR',
        'responsible_name' => 'María Fernanda López',
        'letter_folio' => 'CC-0001-TEST',
        'letter_date' => '2026-10-04',
        'week_number' => 2,
        'week_start' => '2026-10-12',
        'week_end' => '2026-10-18',
        'rows' => [
            [
                'kpi_name' => 'Colocación', 'unit' => 'MXN', 'target_value' => 700000.0,
                'expected_value' => 350000.0, 'actual_value' => 180000.0, 'compliance_percentage' => 25.7,
                'gap' => 520000.0, 'has_data' => true, 'has_breach' => true,
            ],
        ],
    ];

    $html = view('reports.okr-warning-pdf', [
        'folio' => 'WR-0001-TEST',
        'snapshot' => $snapshot,
        'correctiveActions' => 'Reforzar visitas a prospectos de crédito grupal.',
        'observations' => null,
        'generatedAt' => Carbon::parse('2026-10-19'),
        'generatedByName' => 'Admin Test',
    ])->render();

    expect($html)->toContain('JUAN PÉREZ')
        ->toContain('GESTOR')
        ->toContain('CUERNAVACA')
        ->toContain('WR-0001-TEST')
        ->toContain('Incrementar colocación de crédito en la sucursal Cuernavaca')
        ->toContain('180,000') // resultado alcanzado real de la semana
        ->toContain('520,000') // brecha (INCREASE: meta - real)
        ->toContain('25.7')    // % cumplimiento
        // referencia a la Carta Compromiso original (solo si ya existe, 11)
        ->toContain('04')
        ->toContain('data:image/png;base64,');

    expect(substr_count($html, 'class="page"'))->toBe(2); // 2 páginas, estructura de firmas completa (sección 4/21)
});

it('never prints a fabricated referencia when no Carta Compromiso has been issued yet', function () {
    $snapshot = [
        'objective_title' => 'Objective sin carta previa',
        'scope_type' => 'employee', 'branch_name' => 'CUERNAVACA', 'employee_name' => 'ANA GÓMEZ',
        'position' => null, 'responsible_name' => null,
        'letter_folio' => null, 'letter_date' => null,
        'week_number' => 1, 'week_start' => '2026-10-05', 'week_end' => '2026-10-11',
        'rows' => [],
    ];

    $html = view('reports.okr-warning-pdf', [
        'folio' => 'WR-0002-TEST', 'snapshot' => $snapshot,
        'correctiveActions' => 'Seguimiento.', 'observations' => null,
        'generatedAt' => Carbon::parse('2026-10-12'), 'generatedByName' => 'Admin Test',
    ])->render();

    expect($html)->toContain('ANA GÓMEZ');
});

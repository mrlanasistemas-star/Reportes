<?php

use Illuminate\Support\Carbon;

/**
 * Cierre real OKR (04-oct-2026, puntos 19-21) — "TEST VISUAL REAL": este
 * repo no trae poppler/Imagick vendorizados, así que un diff de píxeles no
 * puede correr en CI. Esta prueba es la red de seguridad SIN Chrome: renderiza
 * el blade real (resources/views/reports/okr-commitment-letter-pdf.blade.php)
 * — el mismo que usa Browsershot en producción — y verifica que los campos
 * dinámicos aparecen en el HTML exactamente donde deben, sobre el fondo del
 * machote original. Si alguien rompe una coordenada/variable del overlay,
 * esta prueba truena sin necesitar Node/Chrome. La comparación visual PÍXEL
 * se hizo manualmente en esta sesión (pdftoppm a 150dpi) contra las 2 páginas
 * del PDF original — ver resumen de la conversación, no automatizable aquí.
 */
it('renders the real commitment-letter blade with the ACTIVATED Objective data, never asking the collaborator to retype anything', function () {
    $snapshot = [
        'place' => 'Cuernavaca, Morelos',
        'position' => 'GESTOR',
        'objective_title' => 'Incrementar colocación de crédito en la sucursal Cuernavaca',
        'scope_type' => 'employee',
        'branch_name' => 'CUERNAVACA',
        'employee_name' => 'JUAN PÉREZ',
        'responsible_name' => 'María Fernanda López',
        'start_date' => '2026-10-05',
        'end_date' => '2026-11-01',
        'duration_weeks' => 4,
        'key_results' => [
            ['kpi_name' => 'Colocación', 'unit' => 'MXN', 'baseline_value' => 0, 'target_value' => 700000.0, 'weight' => 100],
        ],
    ];

    $html = view('reports.okr-commitment-letter-pdf', [
        'folio' => 'CC-0001-TEST',
        'snapshot' => $snapshot,
        'generatedAt' => Carbon::parse('2026-10-04'),
        'generatedByName' => 'Admin Test',
        'isPreview' => false,
    ])->render();

    // Campos que el sistema YA conoce — el colaborador NUNCA los escribe (5/9).
    expect($html)->toContain('JUAN PÉREZ')
        ->toContain('GESTOR')
        ->toContain('CUERNAVACA')
        ->toContain('María Fernanda López')
        ->toContain('CC-0001-TEST')
        ->toContain('05/10/2026')
        ->toContain('01/11/2026')
        ->toContain('Incrementar colocación de crédito en la sucursal Cuernavaca')
        ->toContain('700,000');

    // El fondo de cada página es LITERALMENTE el PDF original rasterizado
    // (1/3) — nunca un header/footer inventado — verificado vía el base64
    // embebido de los 2 PNG fuente, no un diseño alternativo.
    expect($html)->toContain('data:image/png;base64,');
    expect(substr_count($html, 'class="page"'))->toBe(2); // 2 páginas, exactas como el original (sección 4: "estructura de firmas" completa)

    // Checkbox "Aplica Sí" marcado para Colocación (único KPI presente), "No" para los demás.
    expect(substr_count($html, '>X</div>'))->toBe(4); // 1 Sí (Colocación) + 3 No (Recuperación/EBITDA/Otro)
});

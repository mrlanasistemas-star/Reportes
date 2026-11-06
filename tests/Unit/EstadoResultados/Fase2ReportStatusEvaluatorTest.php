<?php

use App\Services\EstadoResultados\Reporting\ReportStatus;
use App\Services\EstadoResultados\Reporting\ReportStatusEvaluator;

/**
 * Aprobación por LISTA BLANCA (fase 2.1): sólo estados explícitamente satisfactorios
 * permiten aprobar; nulos, desconocidos, fallidos, incompletos o desactualizados bloquean
 * con un motivo exacto.
 */
function evOk(): array
{
    $ok = ['status' => 'procesado'];

    return [
        'conciliation' => ['pendientes' => ['count' => 0, 'amount' => '0.00']],
        'checks' => [['key' => 'k', 'label' => 'Control', 'ok' => true, 'difference' => '0.00']],
        'validation' => ['status' => 'ok', 'integrity' => ['status' => 'ok'], 'unresolved' => []],
        'completeness' => ['utilidad_neta' => ['complete' => true, 'missing' => []]],
        'sources' => [
            'radiografia' => $ok, 'erp' => $ok, 'nomina' => $ok,
            'cuentas' => [
                ['key' => 'administrativa', 'label' => 'Cuenta administrativa', 'status' => 'procesado', 'upload' => ['blocking_warnings' => []]],
                ['key' => 'excedentes', 'label' => 'Cuenta de excedentes', 'status' => 'procesado', 'upload' => ['blocking_warnings' => []]],
            ],
            'procesamiento' => ['status' => 'procesado', 'last_run' => ['status' => 'ok'], 'stale_erp' => false, 'unprocessed' => false],
        ],
    ];
}

function evRun(array $d): array
{
    return (new ReportStatusEvaluator)->evaluate($d);
}

it('todo satisfactorio → listo para revisión, sin bloqueos', function () {
    $e = evRun(evOk());
    expect($e['status'])->toBe(ReportStatus::LISTO_REVISION)->and($e['blockers'])->toBe([])->and($e['preliminary'])->toBeFalse();
});

dataset('fuentes', ['radiografia', 'erp', 'nomina']);

it('fuente con estado nulo, desconocido o con incidencias bloquea con motivo exacto', function (string $src) {
    foreach ([null => 'sin estado', 'raro' => 'estado no reconocido', 'con_incidencias' => 'tiene incidencias'] as $status => $needle) {
        $d = evOk();
        $d['sources'][$src]['status'] = $status === '' ? null : $status;
        $e = evRun($d);
        expect($e['status'])->not->toBe(ReportStatus::LISTO_REVISION)
            ->and(implode(' | ', array_column($e['blockers'], 'message')))->toContain($needle);
    }
    $d = evOk();
    unset($d['sources'][$src]);
    expect(evRun($d)['status'])->toBe(ReportStatus::BORRADOR);
})->with('fuentes');

it('fuente pendiente = dato faltante', function () {
    $d = evOk();
    $d['sources']['erp']['status'] = 'pendiente';
    $e = evRun($d);
    expect($e['missing'])->toContain('Falta el ERP de gastos del periodo.')->and($e['status'])->toBe(ReportStatus::BORRADOR);
});

it('procesamiento: corrida nula, con error, en curso, desconocida, ERP desactualizado o sin procesar bloquean', function () {
    $cases = [
        ['last_run' => null, 'needle' => 'nunca se ha procesado'],
        ['last_run' => ['status' => 'error'], 'needle' => 'terminó con error'],
        ['last_run' => ['status' => 'en_proceso'], 'needle' => 'procesamiento en curso'],
        ['last_run' => ['status' => '???'], 'needle' => 'estado no reconocido'],
        ['stale_erp' => true, 'needle' => 'ERP de gastos cambió'],
        ['unprocessed' => true, 'needle' => 'sin procesar'],
        ['status' => null, 'needle' => 'sin estado'],
    ];
    foreach ($cases as $c) {
        $d = evOk();
        $needle = $c['needle'];
        unset($c['needle']);
        $d['sources']['procesamiento'] = array_replace($d['sources']['procesamiento'], $c);
        $e = evRun($d);
        expect($e['status'])->toBe(ReportStatus::PENDIENTE_CONCILIACION, $needle)
            ->and(implode(' | ', array_column($e['blockers'], 'message')))->toContain($needle);
    }
    $d = evOk();
    unset($d['sources']['procesamiento']);
    expect(evRun($d)['status'])->toBe(ReportStatus::PENDIENTE_CONCILIACION);
});

it('cuentas: faltante, cargada sin procesar, con incidencias, desconocida o aviso bloqueante', function () {
    $mk = function (callable $fn) {
        $d = evOk();
        $fn($d);

        return evRun($d);
    };
    expect($mk(fn (&$d) => $d['sources']['cuentas'][0]['status'] = 'pendiente')['missing'])->toContain('Falta el estado de cuenta: Cuenta administrativa.')
        ->and($mk(fn (&$d) => $d['sources']['cuentas'][0]['status'] = 'cargado')['status'])->toBe(ReportStatus::PENDIENTE_CONCILIACION)
        ->and($mk(fn (&$d) => $d['sources']['cuentas'][1]['status'] = 'con_incidencias')['status'])->toBe(ReportStatus::BORRADOR)
        ->and(implode('|', array_column($mk(fn (&$d) => $d['sources']['cuentas'][1]['status'] = 'x')['blockers'], 'message')))->toContain('no reconocido')
        ->and($mk(fn (&$d) => $d['sources']['cuentas'] = [])['status'])->toBe(ReportStatus::BORRADOR);
    $e = $mk(fn (&$d) => $d['sources']['cuentas'][0]['upload']['blocking_warnings'] = [['code' => 'control_no_cuadra', 'severity' => 'bloqueante', 'message' => 'Los totales no cuadran']]);
    expect(array_column($e['blockers'], 'code'))->toContain('avisos_carga')
        ->and(implode('|', array_column($e['blockers'], 'message')))->toContain('Los totales no cuadran');
});

it('diferencias de conciliación abiertas (validation.unresolved) bloquean; las informativas no', function () {
    $d = evOk();
    $d['validation']['status'] = 'con_pendientes';
    $d['validation']['unresolved'] = [['key' => 'diferencia_importe', 'label' => 'Mismo folio con importe diferente al ERP', 'count' => 2, 'amount' => '500.00', 'blocking' => true, 'open_count' => 1, 'open_amount' => '200.00']];
    $e = evRun($d);
    expect($e['status'])->toBe(ReportStatus::PENDIENTE_CONCILIACION)
        ->and(implode('|', array_column($e['blockers'], 'message')))->toContain('Mismo folio con importe diferente al ERP (1 partida(s), $200.00)');

    $d['validation']['unresolved'] = [['key' => 'erp_saldo_pendiente', 'label' => 'Saldo ERP', 'count' => 1, 'amount' => '10.00', 'blocking' => false]];
    $d['validation']['status'] = 'ok';
    expect(evRun($d)['status'])->toBe(ReportStatus::LISTO_REVISION);

    // Dataset sin la marca `blocking` (anterior): se asume que bloquea.
    $d['validation']['unresolved'] = [['key' => 'diferencia_folio', 'label' => 'Folio sin ERP', 'count' => 1, 'amount' => '10.00']];
    expect(evRun($d)['status'])->toBe(ReportStatus::PENDIENTE_CONCILIACION);
});

it('validaciones: check fallido, sin checks, validación o integridad no satisfactorias bloquean', function () {
    $d = evOk();
    $d['checks'][] = ['key' => 'x', 'label' => 'EBITDA', 'ok' => false, 'difference' => '0.01'];
    expect(evRun($d)['failed_checks'])->toHaveCount(1);

    $d = evOk();
    $d['checks'][0]['ok'] = 'true'; // tipo inesperado ⇒ no es «ok»
    expect(evRun($d)['status'])->toBe(ReportStatus::BORRADOR);

    foreach ([['checks', []], ['validation.status', null], ['validation.status', 'con_diferencias'], ['validation.status', 'otro'], ['validation.integrity.status', null], ['validation.integrity.status', 'con_incidencias']] as [$path, $value]) {
        $d = evOk();
        data_set($d, $path, $value);
        expect(evRun($d)['status'])->toBe(ReportStatus::BORRADOR, $path.'='.json_encode($value));
    }
});

it('ajustes sin información y completitud ausente bloquean', function () {
    $d = evOk();
    $d['completeness']['utilidad_neta']['missing'] = ['ISR causado del periodo'];
    expect(evRun($d)['missing'][0])->toContain('ISR causado del periodo');
    unset($d['completeness']);
    expect(evRun($d)['status'])->toBe(ReportStatus::BORRADOR);
});

it('con lista de fuentes, los motivos de PRELIMINAR son exactos y dicen dónde se resuelve', function () {
    $d = evOk();
    $d['sources']['erp']['status'] = 'pendiente';
    $d['sources']['checklist'] = ['items' => [
        ['key' => 'erp', 'state' => 'con_problemas', 'required' => true, 'reason' => 'Falta el ERP de gastos de Octubre 2026: el archivo «X» ya está en Carga de archivos → En Carga de archivos → Octubre 2026, pulsa «Cargar registros».'],
        ['key' => 'noi_corporativo', 'state' => 'falta', 'required' => false, 'reason' => 'Falta el NOI de corporativo de Octubre 2026 → Súbelo aquí.'],
    ]];
    $e = (new ReportStatusEvaluator)->evaluate($d);
    expect($e['missing'])->toContain('Falta el ERP de gastos de Octubre 2026: el archivo «X» ya está en Carga de archivos → En Carga de archivos → Octubre 2026, pulsa «Cargar registros».')
        ->and(implode(' ', $e['preliminary_reasons']))->not->toContain('NOI de corporativo');

    $d['sources']['checklist']['items'][1]['required'] = true;
    expect((new ReportStatusEvaluator)->evaluate($d)['missing'])->toContain('Falta el NOI de corporativo de Octubre 2026 → Súbelo aquí.');
});

<?php

use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4Exception;

/**
 * Regresión por regla del motor V4 (puerto de Módulo1.bas de MR_LANA_EFECTIVIDAD.xlsm).
 * Datos sintéticos mínimos: semana 05-11/10/2026, domingo base 04/10.
 */
const V4_COLS_FOTO = ['contrato', 'acreditado', 'oficina', 'nombre_promotor', 'substatus', 'estatus', 'producto', 'fecha_primer_pago',
    'fecha_vencimiento', 'fecha_siguiente_pago', 'cuota_total', 'periodicidad', 'pagos', 'total_atrasado', 'pagos_atrasados',
    'total_pendiente', 'saldo_capital', 'saldo_interes', 'saldo_impuesto', 'saldo_cargos_calendario', 'saldo_impuesto_cargos_calendario'];

const V4_COLS_ING = ['oficina', 'promotor', 'contrato', 'acreditado', 'operacion', 'transaccion', 'fecha_transaccion', 'fecha_cuota',
    'fecha_vencimiento', 'capital', 'interes', 'impuesto', 'cargos_calendario', 'producto', 'concepto', 'numero_cuota'];

function v4Fila(array $o = []): array
{
    return $o + [
        'contrato' => 'C1', 'acreditado' => ' ', 'oficina' => 'RUTA A', 'nombre_promotor' => 'ANA', 'substatus' => 'VIGENTE',
        'estatus' => 'ACTIVO', 'producto' => 'S12', 'fecha_primer_pago' => '2026-10-05', 'fecha_vencimiento' => '2026-12-31',
        'fecha_siguiente_pago' => null, 'cuota_total' => 100.0, 'periodicidad' => 'SEMANAL', 'pagos' => 12.0, 'total_atrasado' => 0.0,
        'pagos_atrasados' => 0.0, 'total_pendiente' => 1000.0, 'saldo_capital' => 900.0, 'saldo_interes' => 0.0, 'saldo_impuesto' => 0.0,
        'saldo_cargos_calendario' => 0.0, 'saldo_impuesto_cargos_calendario' => 0.0,
    ];
}

function v4Pago(array $o = []): array
{
    return $o + [
        'oficina' => 'RUTA A', 'promotor' => 'ANA', 'contrato' => 'C1', 'acreditado' => 'X1', 'operacion' => 'PAGO A CONTRATO',
        'transaccion' => 'PAGO', 'fecha_transaccion' => '2026-10-05', 'fecha_cuota' => '2026-10-05', 'fecha_vencimiento' => '2026-12-31',
        'capital' => 100.0, 'interes' => 0.0, 'impuesto' => 0.0, 'cargos_calendario' => 0.0, 'producto' => 'S12', 'concepto' => 'PRINCIPAL',
        'numero_cuota' => 1,
    ];
}

/**
 * @param  array<int, list<array>>|list<array>  $filas  mismas filas para las 8 fotos, o [indice => filas]
 */
function v4Calc(array $filas, array $pagos, array $p = [], ?array $porFoto = null): array
{
    $p += ['modo' => 'SEMANAL', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-11'];
    $pasos = (int) ((strtotime($p['fecha_corte']) - strtotime($p['domingo_base'])) / 86400);
    $fotos = [];
    for ($i = 0; $i <= $pasos; $i++) {
        $fotos[$i] = ['columnas' => V4_COLS_FOTO, 'filas' => $porFoto[$i] ?? $filas];
    }
    $ing = ['columnas' => V4_COLS_ING, 'filas' => $pagos];

    return (new EfectividadV4Calculator)->calculate($p, $fotos, $ing, $ing, true);
}

function v4Total(array $r): array
{
    return collect($r['filas'])->firstWhere('tipo', 'gran_total') ?? [];
}

it('Sano: cuota de la semana pagada a tiempo; % sobre Sano por cobrar', function () {
    $r = v4Calc([v4Fila()], [v4Pago()]);
    $t = v4Total($r);
    expect($t['sano_pc'])->toEqual(100.0)->and($t['sano_cob'])->toEqual(100.0)->and($t['pct_total'])->toEqual(1.0)->and($t['mora'])->toEqual(0.0);
});

it('Anticipos a fecha cuota: un pago de la semana anterior se reconoce el día de la cuota (Sano) y no antes', function () {
    $anticipo = v4Pago(['fecha_transaccion' => '2026-09-30', 'fecha_cuota' => '2026-10-05']);
    $r = v4Calc([v4Fila()], [$anticipo]);
    expect(v4Total($r)['sano_cob'])->toEqual(100.0);
    expect($r['movimientos'][0]['fecha_reconocimiento'])->toBe('2026-10-05');

    // Anticipo de una cuota de la SEMANA SIGUIENTE: no se reconoce en esta.
    $futuro = v4Pago(['fecha_transaccion' => '2026-10-06', 'fecha_cuota' => '2026-10-12']);
    expect(v4Calc([v4Fila()], [$futuro])['filas'][0]['sano_cob'] ?? 0)->toEqual(0.0);
});

it('Reno solo su semana: REFINANCIAMIENTO/DESCUENTO cuenta solo si transacción y cuota caen en la semana', function () {
    $renoSemana = v4Pago(['operacion' => 'REFINANCIAMIENTO', 'transaccion' => 'DESCUENTO', 'fecha_transaccion' => '2026-10-05']);
    expect(v4Total(v4Calc([v4Fila()], [$renoSemana]))['cobranza_total'])->toEqual(100.0);

    // Mismo descuento registrado la semana anterior (anticipo de reno): NO se reconoce.
    $renoPrevio = v4Pago(['operacion' => 'REFINANCIAMIENTO', 'transaccion' => 'DESCUENTO', 'fecha_transaccion' => '2026-10-02']);
    expect(v4Total(v4Calc([v4Fila()], [$renoPrevio]))['cobranza_total'])->toEqual(0.0);

    // Un pago ordinario anticipado sí se reconoce (contraste).
    $pagoPrevio = v4Pago(['fecha_transaccion' => '2026-10-02']);
    expect(v4Total(v4Calc([v4Fila()], [$pagoPrevio]))['cobranza_total'])->toEqual(100.0);
});

it('Whitelist: DESCUENTO sin REFINANCIAMIENTO o CONDONACION nunca cuentan', function () {
    $r = v4Calc([v4Fila()], [
        v4Pago(['operacion' => 'SEGUROS', 'transaccion' => 'DESCUENTO']),
        v4Pago(['operacion' => 'CASTIGO DE CARTERA', 'transaccion' => 'CONDONACION']),
    ]);
    expect(v4Total($r)['cobranza_total'])->toEqual(0.0);
});

it('Sin moratorios: el importe es capital + interés + impuesto + cargos calendario (cargos de vencimiento no existen para la macro)', function () {
    $moratorio = v4Pago(['capital' => 0.0, 'interes' => 0.0, 'impuesto' => 0.0, 'cargos_calendario' => 0.0, 'cargos_vencimiento' => 456.6]);
    expect(v4Total(v4Calc([v4Fila()], [$moratorio]))['cobranza_total'] ?? 0.0)->toEqual(0.0);

    $mixto = v4Pago(['capital' => 80.0, 'interes' => 15.0, 'impuesto' => 2.4, 'cargos_calendario' => 2.6, 'cargos_vencimiento' => 50.0]);
    expect(v4Total(v4Calc([v4Fila()], [$mixto]))['sano_cob'])->toEqual(100.0);
});

it('Vencido según foto diaria: se usa la foto del día de reconocimiento, no la del corte', function () {
    $vigente = [v4Fila()];
    $vencido = [v4Fila(['substatus' => 'VENCIDO', 'total_atrasado' => 300.0])];
    // VIGENTE de domingo a martes, VENCIDO de miércoles en adelante.
    $porFoto = [0 => $vigente, 1 => $vigente, 2 => $vigente, 3 => $vencido, 4 => $vencido, 5 => $vencido, 6 => $vencido, 7 => $vencido];

    $pagoLunes = v4Pago(['fecha_transaccion' => '2026-10-05']);
    expect(v4Total(v4Calc([], [$pagoLunes], [], $porFoto))['sano_cob'])->toEqual(100.0);

    $pagoJueves = v4Pago(['fecha_transaccion' => '2026-10-08', 'fecha_cuota' => '2026-10-05']);
    $t = v4Total(v4Calc([], [$pagoJueves], [], $porFoto));
    expect($t['vencido_cob'])->toEqual(100.0)->and($t['atraso_cob'])->toEqual(0.0);
});

it('Atraso según foto diaria: con Total atrasado ese día, un pago puntual se clasifica como atraso', function () {
    $conAtraso = [v4Fila(['total_atrasado' => 200.0, 'pagos_atrasados' => 2.0])];
    $t = v4Total(v4Calc($conAtraso, [v4Pago()]));
    expect($t['atraso_cob'])->toEqual(100.0)->and($t['sano_cob'])->toEqual(0.0);

    // Bandera 0.01: pagos atrasados sin importe útil también cuentan como atraso del día.
    $bandera = [v4Fila(['total_atrasado' => 0.0, 'pagos_atrasados' => 1.0])];
    expect(v4Total(v4Calc($bandera, [v4Pago()]))['atraso_cob'])->toEqual(100.0);
});

it('Pago tardío de un contrato vigente = atraso; posterior al vencimiento del contrato = vencido', function () {
    $tarde = v4Pago(['fecha_transaccion' => '2026-10-07', 'fecha_cuota' => '2026-10-05']);
    expect(v4Total(v4Calc([v4Fila()], [$tarde]))['atraso_cob'])->toEqual(100.0);

    $fila = [v4Fila(['fecha_vencimiento' => '2026-10-06', 'fecha_primer_pago' => '2026-10-05', 'pagos' => 1.0])];
    $despues = v4Pago(['fecha_transaccion' => '2026-10-08', 'fecha_cuota' => '2026-10-05', 'fecha_vencimiento' => '2026-10-06']);
    expect(v4Total(v4Calc($fila, [$despues]))['vencido_cob'])->toEqual(100.0);
});

it('Stocks de VIGENTE CON ATRASO y VENCIDO salen SOLO de la foto del corte (no se suman días)', function () {
    $dia = [v4Fila(['total_atrasado' => 500.0, 'pagos_atrasados' => 5.0])];
    $corte = [v4Fila(['total_atrasado' => 120.0, 'pagos_atrasados' => 1.0])];
    $porFoto = array_fill(0, 7, $dia) + [7 => $corte];
    expect(v4Total(v4Calc([], [], [], $porFoto))['atraso_pc'])->toEqual(120.0);

    // Vencido: Total atrasado manda; si es 0, suma de saldos (con negativos); si no, Total pendiente.
    $v1 = [v4Fila(['substatus' => 'VENCIDO', 'total_atrasado' => 0.0, 'saldo_capital' => 300.0, 'saldo_interes' => -20.0, 'total_pendiente' => 0.0])];
    expect(v4Total(v4Calc($v1, []))['vencido_pc'])->toEqual(280.0);
    $v2 = [v4Fila(['substatus' => 'VENCIDO', 'total_atrasado' => 0.0, 'saldo_capital' => 0.0, 'total_pendiente' => 75.5])];
    expect(v4Total(v4Calc($v2, []))['vencido_pc'])->toEqual(75.5);
});

it('Contratos VENCIDOS, cerrados o inactivos no generan cuotas sanas', function () {
    $r = v4Calc([
        v4Fila(['contrato' => 'V', 'substatus' => 'VENCIDO', 'total_atrasado' => 10.0]),
        v4Fila(['contrato' => 'X', 'estatus' => 'CERRADO']),
        v4Fila(['contrato' => 'I', 'substatus' => 'INACTIVO']),
    ], []);
    expect(v4Total($r)['sano_pc'])->toEqual(0.0);
});

it('Productos REESTRUCTURA/UNIFICACIÓN/CONDONACIÓN/COBERTURA quedan fuera (cartera y pagos); origen REFINANCIAMIENTO no', function () {
    $r = v4Calc([v4Fila(['producto' => 'REESTRUCTURA CREDITO MR LANA'])], [v4Pago()]);
    expect($r['filas'])->toBe([])->and($r['sin_movimientos'])->toBeTrue();

    $porConcepto = v4Calc([v4Fila()], [v4Pago(['concepto' => 'COBERTURA SAVEHEARTS'])]);
    expect(v4Total($porConcepto)['cobranza_total'])->toEqual(0.0);
});

it('NO-CARTERA: pago de un contrato sin respaldo en ninguna fotografía → incidencia, excluido del cálculo', function () {
    $r = v4Calc([v4Fila()], [v4Pago(), v4Pago(['contrato' => '26ORI0687909', 'ref' => '203'])]);
    expect(v4Total($r)['cobranza_total'])->toEqual(100.0);
    expect($r['incidencias'])->toHaveCount(1)
        ->and($r['incidencias'][0]['id'])->toBe('NO-CARTERA|26ORI0687909|203')
        ->and($r['incidencias'][0]['descripcion'])->toBe('Contrato sin respaldo en fotos del periodo: 26ORI0687909; validar antes de contabilizar anticipo/pago.');
});

it('CUOTA: pago puntual de un vigente sin cuota programada verificable → incidencia, excluido', function () {
    $pago = v4Pago(['fecha_cuota' => '2026-10-07', 'fecha_transaccion' => '2026-10-07', 'ref' => '9']);
    $r = v4Calc([v4Fila()], [$pago]);
    expect($r['incidencias'][0]['codigo'])->toBe('CUOTA')->and(v4Total($r)['cobranza_total'] ?? 0.0)->toEqual(0.0);
});

it('DUP: el mismo movimiento dos veces se cuenta una sola vez y queda en la bitácora', function () {
    $r = v4Calc([v4Fila()], [v4Pago(['ref' => '2']), v4Pago(['ref' => '3'])]);
    expect(v4Total($r)['sano_cob'])->toEqual(100.0)->and($r['incidencias'][0]['codigo'])->toBe('DUP');
});

it('FOTO-DUP: un contrato repetido en una fotografía se usa una sola vez', function () {
    $r = v4Calc([v4Fila(), v4Fila()], []);
    expect(v4Total($r)['sano_pc'])->toEqual(100.0);
    expect(collect($r['incidencias'])->pluck('codigo')->unique()->all())->toBe(['FOTO-DUP']);
});

it('Un contrato que desaparece a media semana conserva su cuota y el estado de la última foto previa (nunca una futura)', function () {
    $con = [v4Fila()];
    // Aparece de domingo a martes; desde el miércoles ya no está (cerrado por reno).
    $porFoto = [0 => $con, 1 => $con, 2 => $con] + array_fill(3, 5, [v4Fila(['contrato' => 'OTRO', 'fecha_primer_pago' => '2026-11-02'])]);
    $pago = v4Pago(['fecha_transaccion' => '2026-10-09', 'fecha_cuota' => '2026-10-05']);
    $r = v4Calc([], [$pago], [], $porFoto);
    expect(v4Total($r)['sano_pc'])->toEqual(100.0)->and(v4Total($r)['atraso_cob'])->toEqual(100.0);
    expect($r['movimientos'][0]['estado_foto'])->toBe('VIGENTE');
});

it('Cambio de condiciones a media semana: las cuotas se reprograman solo hacia adelante', function () {
    $antes = [v4Fila(['periodicidad' => 'DIARIO', 'cuota_total' => 50.0, 'pagos' => 30.0])];
    $despues = [v4Fila(['periodicidad' => 'DIARIO', 'cuota_total' => 80.0, 'pagos' => 30.0])];
    // Lunes a miércoles cuota 50; desde el jueves 80 (jueves y viernes) → 3×50 + 2×80.
    $porFoto = [0 => $antes, 1 => $antes, 2 => $antes, 3 => $antes] + array_fill(4, 4, $despues);
    expect(v4Total(v4Calc([], [], [], $porFoto))['sano_pc'])->toEqual(310.0);
});

it('Responsable de ruta: el promotor con más movimientos de la semana se queda con toda la ruta; sin cobros, el predominante de la última foto', function () {
    $filas = [v4Fila(['contrato' => 'C1', 'nombre_promotor' => 'ANA']), v4Fila(['contrato' => 'C2', 'nombre_promotor' => 'ANA'])];
    $pagos = [v4Pago(['promotor' => 'BETO', 'contrato' => 'C1']), v4Pago(['promotor' => 'BETO', 'contrato' => 'C2'])];
    $r = v4Calc($filas, $pagos);
    expect(collect($r['filas'])->where('tipo', 'promotor')->pluck('promotor')->all())->toBe(['BETO']);

    $sinCobros = v4Calc([v4Fila(['nombre_promotor' => 'ANA']), v4Fila(['contrato' => 'C2', 'nombre_promotor' => 'ANA']), v4Fila(['contrato' => 'C3', 'nombre_promotor' => 'CARLOS'])], []);
    expect(collect($sinCobros['filas'])->where('tipo', 'promotor')->pluck('promotor')->all())->toBe(['ANA']);
});

it('Orden, subtotales por oficina y GRAN TOTAL como la hoja EFECTIVIDAD', function () {
    $r = v4Calc([
        v4Fila(['contrato' => 'C1', 'oficina' => 'YANGA', 'nombre_promotor' => 'Z']),
        v4Fila(['contrato' => 'C2', 'oficina' => 'calzadas', 'nombre_promotor' => 'Y']),
    ], []);
    expect(collect($r['filas'])->pluck('promotor')->all())->toBe(['Y', 'TOTAL CALZADAS', 'Z', 'TOTAL YANGA', 'GRAN TOTAL'])
        ->and($r['filas'][0]['oficina_titulo'])->toBe('CALZADAS');
});

it('DIARIO: el denominador es solo la cuota de ese día y el corte usa las fotos hasta ese día', function () {
    $fila = [v4Fila(['periodicidad' => 'DIARIO', 'pagos' => 30.0])];
    $r = v4Calc($fila, [v4Pago(['fecha_cuota' => '2026-10-06', 'fecha_transaccion' => '2026-10-06'])], ['modo' => 'DIARIO', 'fecha_corte' => '2026-10-06']);
    expect($r['parametros']['inicio_ingresos'])->toBe('2026-10-06')->and(v4Total($r)['sano_pc'])->toEqual(100.0)->and(v4Total($r)['sano_cob'])->toEqual(100.0);
});

it('MENSUAL suma un mes como DateAdd de VBA (31 → último día del mes siguiente, sin desbordar)', function () {
    // Semana 23/02-01/03/2026. 31/01 + 1 mes = 28/02 en VBA (dentro de la semana);
    // con la suma de PHP sería 03/03 (fuera) y la cuota se perdería.
    $fila = [v4Fila(['periodicidad' => 'MENSUAL', 'fecha_primer_pago' => '2026-01-31', 'pagos' => 6.0, 'fecha_vencimiento' => '2026-12-31'])];
    $r = v4Calc($fila, [], ['domingo_base' => '2026-02-22', 'fecha_corte' => '2026-03-01']);
    expect(v4Total($r)['sano_pc'])->toEqual(100.0);
});

it('Suma de una expresión VBA con un solo redondeo (precisión extendida x87)', function () {
    $pago = v4Pago(['capital' => 350.79, 'interes' => 58.8, 'impuesto' => 9.41]);
    $r = v4Calc([v4Fila(['cuota_total' => 419.0])], [$pago]);
    expect(v4Total($r)['sano_cob'])->toBe(419.0); // PHP ingenuo daría 419.00000000000006
});

it('Validaciones de control: domingo base, corte semanal y fotografías obligatorias', function () {
    expect(fn () => v4Calc([v4Fila()], [], ['domingo_base' => '2026-10-05', 'fecha_corte' => '2026-10-12']))
        ->toThrow(fn (EfectividadV4Exception $e) => expect($e->codigo)->toBe('EC-V4-902'));
    expect(fn () => v4Calc([v4Fila()], [], ['fecha_corte' => '2026-10-09']))
        ->toThrow(fn (EfectividadV4Exception $e) => expect($e->codigo)->toBe('EC-V4-906'));

    $fotos = array_fill(0, 7, ['columnas' => V4_COLS_FOTO, 'filas' => [v4Fila()]]); // falta la foto 7
    $ing = ['columnas' => V4_COLS_ING, 'filas' => []];
    expect(fn () => (new EfectividadV4Calculator)->calculate(['modo' => 'SEMANAL', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-11'], $fotos, $ing, $ing))
        ->toThrow(fn (EfectividadV4Exception $e) => expect($e->codigo)->toBe('EC-V4-907')->and($e->getMessage())->toContain('CARTERA DOMINGO CORTE'));
});

// ── Ramas adicionales de Módulo1.bas (cierre 12-oct-2026) ──────────────────

it('GRP: contrato observado en foto sin Oficina y pago sin Oficina → sin responsable de ruta, excluido', function () {
    $r = v4Calc([v4Fila(), v4Fila(['contrato' => 'C9', 'oficina' => ''])], [v4Pago(), v4Pago(['contrato' => 'C9', 'oficina' => '', 'ref' => '5'])]);
    expect(v4Total($r)['cobranza_total'])->toEqual(100.0)
        ->and($r['incidencias'])->toHaveCount(1)
        ->and($r['incidencias'][0])->toMatchArray(['id' => 'GRP|C9|5', 'codigo' => 'GRP', 'contrato' => 'C9', 'referencia' => '5',
            'descripcion' => 'Ingreso sin responsable de ruta: C9', 'importe' => 100.0, 'fecha' => '2026-10-05']);
});

it('GRP no aplica si el pago trae Oficina; como la foto sin Oficina no programó cuota, el pago puntual cae en CUOTA y el tardío cuenta como atraso', function () {
    $puntual = v4Calc([v4Fila(), v4Fila(['contrato' => 'C9', 'oficina' => ''])], [v4Pago(), v4Pago(['contrato' => 'C9', 'ref' => '5'])]);
    expect(collect($puntual['incidencias'])->pluck('id')->all())->toBe(['CUOTA|C9|5'])
        ->and(v4Total($puntual)['cobranza_total'])->toEqual(100.0);

    $tardio = v4Calc([v4Fila(), v4Fila(['contrato' => 'C9', 'oficina' => ''])], [v4Pago(), v4Pago(['contrato' => 'C9', 'ref' => '5', 'fecha_transaccion' => '2026-10-06'])]);
    expect($tardio['incidencias'])->toBe([])
        ->and(v4Total($tardio)['atraso_cob'])->toEqual(100.0)
        ->and(collect($tardio['filas'])->where('tipo', 'promotor')->pluck('promotor')->all())->toBe(['ANA']);
});

it('PROM: contrato con Oficina sin promotor y sin responsable de ruta → incidencia (una por contrato) y sin grupo', function () {
    $r = v4Calc([v4Fila(), v4Fila(['contrato' => 'C5', 'oficina' => 'RUTA Z', 'nombre_promotor' => ''])], []);
    expect(collect($r['incidencias'])->pluck('id')->all())->toBe(['PROM|C5'])
        ->and($r['incidencias'][0]['descripcion'])->toBe('Promotor no identificado: C5, CARTERA DOMINGO')
        ->and(collect($r['filas'])->pluck('oficina')->filter()->unique()->values()->all())->toBe(['RUTA A']);

    // Con un cobro de la semana en RUTA Z, su promotor es el responsable y ya no hay PROM.
    $conRuta = v4Calc([v4Fila(), v4Fila(['contrato' => 'C5', 'oficina' => 'RUTA Z', 'nombre_promotor' => ''])],
        [v4Pago(['contrato' => 'C5', 'oficina' => 'RUTA Z', 'promotor' => 'LUIS'])]);
    expect($conRuta['incidencias'])->toBe([])
        ->and(collect($conRuta['filas'])->firstWhere('promotor', 'LUIS')['sano_cob'])->toEqual(100.0);
});

it('FECHA: sin Fecha primer pago usa Fecha siguiente pago; sin ninguna (o inválida) → incidencia y sin cuotas', function () {
    $sinFechas = v4Calc([v4Fila(['contrato' => 'C6', 'fecha_primer_pago' => null])], []);
    expect(collect($sinFechas['incidencias'])->pluck('id')->all())->toBe(['FECHA|C6'])
        ->and($sinFechas['incidencias'][0]['descripcion'])->toBe('Contrato sin fecha primer pago: C6')
        ->and($sinFechas['filas'])->toBe([]);

    $siguiente = v4Calc([v4Fila(['fecha_primer_pago' => null, 'fecha_siguiente_pago' => '2026-10-06'])], []);
    expect(v4Total($siguiente)['sano_pc'])->toEqual(100.0)->and($siguiente['incidencias'])->toBe([]);

    $invalida = v4Calc([v4Fila(['contrato' => 'C7', 'fecha_primer_pago' => '2026-02-30'])], []);
    expect(collect($invalida['incidencias'])->pluck('codigo')->all())->toBe(['FECHA']);
});

it('QUINCENAL / CATORCENAL / "14": cuota cada 14 días', function () {
    $enSemana = v4Calc([v4Fila(['periodicidad' => 'QUINCENAL', 'fecha_primer_pago' => '2026-09-21'])], []);   // 21/09 → 05/10
    $fuera = v4Calc([v4Fila(['periodicidad' => 'QUINCENAL', 'fecha_primer_pago' => '2026-09-28'])], []);      // 28/09 → 12/10
    $catorcenal = v4Calc([v4Fila(['periodicidad' => 'Catorcenal', 'fecha_primer_pago' => '2026-09-23'])], []); // 23/09 → 07/10
    $catorce = v4Calc([v4Fila(['periodicidad' => 'CADA 14 DIAS', 'fecha_primer_pago' => '2026-09-25'])], []);  // 25/09 → 09/10

    expect(v4Total($enSemana)['sano_pc'])->toEqual(100.0)
        ->and($fuera['filas'])->toBe([])
        ->and(v4Total($catorcenal)['sano_pc'])->toEqual(100.0)
        ->and(v4Total($catorce)['sano_pc'])->toEqual(100.0);
});

it('Periodicidad DIARIA: cuotas de lunes a viernes (salta sábado y domingo)', function () {
    $r = v4Calc([v4Fila(['periodicidad' => 'DIARIO', 'pagos' => 30.0])], []);
    expect(v4Total($r)['sano_pc'])->toEqual(500.0);

    // Primer pago en sábado: esa cuota existe (la macro no la mueve) y la siguiente es el lunes (fuera).
    $sabado = v4Calc([v4Fila(['periodicidad' => 'DIARIO', 'pagos' => 30.0, 'fecha_primer_pago' => '2026-10-10'])], []);
    expect(v4Total($sabado)['sano_pc'])->toEqual(100.0);
});

it('Periodicidad SEMANAL por defecto: texto desconocido o vacío = cada 7 días', function () {
    expect(v4Total(v4Calc([v4Fila(['periodicidad' => 'OTRA'])], []))['sano_pc'])->toEqual(100.0)
        ->and(v4Total(v4Calc([v4Fila(['periodicidad' => '', 'fecha_primer_pago' => '2026-09-28'])], []))['sano_pc'])->toEqual(100.0);
});

it('MENSUAL en año bisiesto: 31/01/2028 + 1 mes = 29/02/2028; en 2027 = 28/02/2027', function () {
    $fila = fn ($primer) => [v4Fila(['periodicidad' => 'MENSUAL', 'fecha_primer_pago' => $primer, 'pagos' => 6.0, 'fecha_vencimiento' => '2028-12-31'])];

    // Semana 28/02-05/03/2028 (domingo base 27/02): la cuota del martes 29/02 es exigible.
    $bisiesto = v4Calc($fila('2028-01-31'), [v4Pago(['fecha_transaccion' => '2028-02-29', 'fecha_cuota' => '2028-02-29', 'fecha_vencimiento' => '2028-12-31'])],
        ['domingo_base' => '2028-02-27', 'fecha_corte' => '2028-03-05']);
    expect(v4Total($bisiesto)['sano_pc'])->toEqual(100.0)->and(v4Total($bisiesto)['sano_cob'])->toEqual(100.0);

    // Semana 22-28/02/2027: la cuota cae el domingo 28/02 (fin de la semana evaluada).
    $comun = v4Calc($fila('2027-01-31'), [], ['domingo_base' => '2027-02-21', 'fecha_corte' => '2027-02-28']);
    expect(v4Total($comun)['sano_pc'])->toEqual(100.0);
});

it('Cierre de mes: MENSUAL desde 31/08 → 30/09 → 30/10 (el día no se recupera, igual que DateAdd encadenado)', function () {
    // Semana 26/10-01/11/2026 (domingo base 25/10): cuota del viernes 30/10.
    $r = v4Calc([v4Fila(['periodicidad' => 'MENSUAL', 'fecha_primer_pago' => '2026-08-31', 'pagos' => 6.0])], [], ['domingo_base' => '2026-10-25', 'fecha_corte' => '2026-11-01']);
    expect(v4Total($r)['sano_pc'])->toEqual(100.0);
});

it('Importes cero o negativos no cuentan; un componente negativo se netea dentro del importe', function () {
    $cero = v4Calc([v4Fila()], [v4Pago(['capital' => 0.0])]);
    $negativo = v4Calc([v4Fila()], [v4Pago(['capital' => -100.0])]);
    $neto = v4Calc([v4Fila()], [v4Pago(['capital' => 120.0, 'interes' => -20.0])]);
    $texto = v4Calc([v4Fila()], [v4Pago(['capital' => '$1,000.50', 'interes' => '(0.50)'])]);

    expect(v4Total($cero)['cobranza_total'])->toEqual(0.0)->and($cero['incidencias'])->toBe([])
        ->and(v4Total($negativo)['cobranza_total'])->toEqual(0.0)
        ->and(v4Total($neto)['sano_cob'])->toEqual(100.0)
        ->and(v4Total($texto)['sano_cob'])->toEqual(1000.0);
});

it('Cuota cero o negativa en la foto no programa cuota Sano; Pagos = 0 tampoco', function () {
    expect(v4Calc([v4Fila(['cuota_total' => 0.0])], [])['filas'])->toBe([])
        ->and(v4Calc([v4Fila(['cuota_total' => -50.0])], [])['filas'])->toBe([])
        ->and(v4Calc([v4Fila(['pagos' => 0.0])], [])['filas'])->toBe([]);
});

it('Fechas inválidas en Ingresos: el movimiento se ignora (sin incidencia); dd/mm/aaaa y serial de Excel sí se leen', function () {
    $invalida = v4Calc([v4Fila()], [v4Pago(['fecha_transaccion' => '31/02/2026'])]);
    $sinCuota = v4Calc([v4Fila()], [v4Pago(['fecha_cuota' => 'no es fecha'])]);
    $mexicana = v4Calc([v4Fila()], [v4Pago(['fecha_transaccion' => '05/10/2026', 'fecha_cuota' => '05/10/2026'])]);
    $serial = intdiv((int) strtotime('2026-10-05 00:00:00 UTC'), 86400) + 25569;
    $excel = v4Calc([v4Fila()], [v4Pago(['fecha_transaccion' => (float) $serial, 'fecha_cuota' => (float) $serial])]);

    expect(v4Total($invalida)['cobranza_total'])->toEqual(0.0)->and($invalida['incidencias'])->toBe([])
        ->and(v4Total($sinCuota)['cobranza_total'])->toEqual(0.0)
        ->and(v4Total($mexicana)['sano_cob'])->toEqual(100.0)
        ->and(v4Total($excel)['sano_cob'])->toEqual(100.0);
});

it('Promotores homónimos en oficinas distintas son grupos distintos (llave OFICINA|NOM|PROMOTOR)', function () {
    $r = v4Calc([
        v4Fila(['contrato' => 'C1', 'oficina' => 'RUTA A', 'nombre_promotor' => 'ANA']),
        v4Fila(['contrato' => 'C2', 'oficina' => 'RUTA B', 'nombre_promotor' => 'ANA', 'cuota_total' => 50.0]),
    ], [v4Pago(), v4Pago(['contrato' => 'C2', 'oficina' => 'RUTA B', 'capital' => 50.0])]);
    $promotores = collect($r['filas'])->where('tipo', 'promotor')->values();

    expect($promotores->pluck('promotor')->all())->toBe(['ANA', 'ANA'])
        ->and($promotores->pluck('oficina')->all())->toBe(['RUTA A', 'RUTA B'])
        ->and($promotores->pluck('sano_pc')->all())->toEqual([100.0, 50.0])
        ->and(collect($r['filas'])->pluck('promotor')->all())->toBe(['ANA', 'TOTAL RUTA A', 'ANA', 'TOTAL RUTA B', 'GRAN TOTAL'])
        ->and(v4Total($r)['sano_pc'])->toEqual(150.0);
});

it('Normalización: acentos, mayúsculas y espacios dobles agrupan la misma oficina/promotor', function () {
    $r = v4Calc([
        v4Fila(['contrato' => 'C1', 'oficina' => 'Córdoba', 'nombre_promotor' => 'MARÍA  LÓPEZ']),
        v4Fila(['contrato' => 'C2', 'oficina' => 'CORDOBA', 'nombre_promotor' => 'maria lopez']),
    ], []);
    expect(collect($r['filas'])->where('tipo', 'promotor')->count())->toBe(1)
        ->and(v4Total($r)['sano_pc'])->toEqual(200.0);
});

it('Múltiples contratos de un promotor se acumulan; varios pagos de un contrato también', function () {
    $r = v4Calc(
        [v4Fila(['contrato' => 'C1']), v4Fila(['contrato' => 'C2', 'cuota_total' => 250.0]), v4Fila(['contrato' => 'C3', 'cuota_total' => 75.25])],
        [v4Pago(['contrato' => 'C1', 'capital' => 60.0]), v4Pago(['contrato' => 'C1', 'capital' => 40.0, 'numero_cuota' => 2]),
            v4Pago(['contrato' => 'C2', 'capital' => 250.0]), v4Pago(['contrato' => 'C3', 'capital' => 75.25, 'fecha_transaccion' => '2026-10-06'])],
    );
    $t = v4Total($r);
    expect($t['sano_pc'])->toEqual(425.25)->and($t['sano_cob'])->toEqual(350.0)->and($t['atraso_cob'])->toEqual(75.25)
        ->and($t['cobranza_total'])->toEqual(425.25)->and($t['mora'])->toEqual(0.0)->and($r['incidencias'])->toBe([]);
});

it('Cambio de firma con mayor cuota a media semana: el monto nuevo aplica solo a cuotas desde esa foto', function () {
    $antes = [v4Fila(['cuota_total' => 100.0])];
    $despues = [v4Fila(['cuota_total' => 130.0])]; // la cuota semanal del lunes ya pasó cuando cambia
    $porFoto = [0 => $antes, 1 => $antes, 2 => $antes] + array_fill(3, 5, $despues);
    expect(v4Total(v4Calc([], [], [], $porFoto))['sano_pc'])->toEqual(100.0);

    // Cambio el domingo base (antes del lunes): la cuota del lunes toma el monto nuevo.
    $porFoto2 = [0 => $antes] + array_fill(1, 7, $despues);
    expect(v4Total(v4Calc([], [], [], $porFoto2))['sano_pc'])->toEqual(130.0);
});

it('Modo DIARIO con corte en domingo y validaciones 901/903/904/905/908/909/910/920/940', function () {
    $dom = v4Calc([v4Fila()], [], ['modo' => 'DIARIO', 'fecha_corte' => '2026-10-11']);
    expect($dom['parametros'])->toMatchArray(['modo' => 'DIARIO', 'inicio_ingresos' => '2026-10-11', 'fin_ingresos' => '2026-10-11']);

    $codigo = function (callable $fn) {
        try {
            $fn();
        } catch (EfectividadV4Exception $e) {
            return $e->codigo;
        }

        return null;
    };
    $fotos = array_fill(0, 8, ['columnas' => V4_COLS_FOTO, 'filas' => [v4Fila()]]);
    $ing = ['columnas' => V4_COLS_ING, 'filas' => []];
    $calc = fn (array $p, ?array $f = null, ?array $i = null, ?array $h = null) => (new EfectividadV4Calculator)->calculate($p + ['modo' => 'SEMANAL', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-11'], $f ?? $fotos, $i ?? $ing, $h ?? $ing);

    expect($codigo(fn () => $calc(['domingo_base' => null])))->toBe('EC-V4-901')
        ->and($codigo(fn () => $calc(['fecha_corte' => 'x'])))->toBe('EC-V4-903')
        ->and($codigo(fn () => $calc(['modo' => 'MENSUAL'])))->toBe('EC-V4-904')
        ->and($codigo(fn () => $calc(['modo' => 'DIARIO', 'fecha_corte' => '2026-10-04'])))->toBe('EC-V4-905')
        ->and($codigo(fn () => $calc(['modo' => 'DIARIO', 'fecha_corte' => '2026-10-12'])))->toBe('EC-V4-905')
        ->and($codigo(fn () => $calc([], [0 => ['columnas' => ['oficina'], 'filas' => [v4Fila()]]] + $fotos)))->toBe('EC-V4-908')
        ->and($codigo(fn () => $calc([], [3 => ['columnas' => V4_COLS_FOTO, 'filas' => [v4Fila(['contrato' => ' '])]]] + $fotos)))->toBe('EC-V4-909')
        ->and($codigo(fn () => $calc([], null, ['columnas' => ['oficina'], 'filas' => []])))->toBe('EC-V4-910')
        ->and($codigo(fn () => $calc([], null, null, ['columnas' => array_values(array_diff(V4_COLS_ING, ['fecha_cuota'])), 'filas' => []])))->toBe('EC-V4-940')
        ->and($codigo(fn () => $calc([], [5 => ['columnas' => array_values(array_diff(V4_COLS_FOTO, ['cuota_total'])), 'filas' => [v4Fila()]]] + $fotos)))->toBe('EC-V4-920');
});

it('Sin grupos con datos: sin_movimientos = true y ninguna fila (nunca 0% inventado)', function () {
    $r = v4Calc([v4Fila(['estatus' => 'CERRADO'])], []);
    expect($r['filas'])->toBe([])->and($r['sin_movimientos'])->toBeTrue();
});

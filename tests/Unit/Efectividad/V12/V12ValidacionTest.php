<?php

use App\Services\Efectividad\V12\EfectividadV12Calculator;
use App\Services\Efectividad\V12\V12Buckets;
use App\Services\Efectividad\V12\V12Corrida;
use App\Services\Efectividad\V12\V12Exception;
use Tests\Support\EfectividadV12Fixtures as F;

/** Código EC-V12 de la excepción lanzada por `$fn` (falla si no lanza V12Exception). */
function v12Codigo(callable $fn): string
{
    try {
        $fn();
    } catch (V12Exception $e) {
        return $e->codigo;
    }
    throw new RuntimeException('Se esperaba una V12Exception');
}

/** Buckets de un solo grupo con importes forzados para probar MR_ValidarBuckets. */
function v12BucketsForzados(array $importes): V12Buckets
{
    $c = new V12Corrida;
    $c->grupos = ['CORDOBA~ANA' => true];
    $b = new V12Buckets($c);
    foreach ($importes as $arreglo => $valor) {
        $b->{$arreglo}['CORDOBA~ANA'] = $valor;
    }

    return $b;
}

it('fila() devuelve porcentajes nulos cuando el Sano por cobrar es ≤ 0.005', function () {
    $calc = new EfectividadV12Calculator;
    $v = ['sano_pc' => 0.005, 'sano_cob' => 0.0, 'atraso_pc' => 10.0, 'atraso_cob' => 10.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0];
    $sinBase = $calc->fila('promotor', 'CORDOBA', 'ANA', $v);
    $conBase = $calc->fila('promotor', 'CORDOBA', 'ANA', ['sano_pc' => 0.006] + $v);

    expect($sinBase)->toMatchArray([
        'sano_pct' => null, 'atraso_pct' => null, 'vencido_pct' => null, 'pct_total' => null,
        'cobranza_total' => 10.0, 'mora' => 0.005 - 10.0, 'oficina_titulo' => 'CORDOBA', 'es_total' => false,
    ])
        ->and($conBase['atraso_pct'])->toEqualWithDelta(10 / 0.006, 1e-9)
        ->and($conBase['pct_total'])->toEqualWithDelta(10 / 0.006, 1e-9);
});

it('MR_ValidarBuckets: cobranza sana mayor al Sano por cobrar es EC-V12-291', function () {
    $b = v12BucketsForzados(['meta' => 100.0, 'pcS' => 100.0, 'cobS' => 100.03]);

    expect(v12Codigo(fn () => $b->validar()))->toBe('EC-V12-291');
});

it('MR_ValidarBuckets: cobranza de atraso mayor a su Por cobrar es EC-V12-292', function () {
    expect(v12Codigo(fn () => v12BucketsForzados(['pcA' => 50.0, 'cobA' => 50.03])->validar()))->toBe('EC-V12-292');
});

it('MR_ValidarBuckets: cobranza vencida mayor a su Por cobrar es EC-V12-293', function () {
    expect(v12Codigo(fn () => v12BucketsForzados(['pcV' => 50.0, 'cobV' => 50.03])->validar()))->toBe('EC-V12-293');
});

it('MR_ValidarBuckets: referencia interna distinta del Sano por cobrar es EC-V12-294', function () {
    expect(v12Codigo(fn () => v12BucketsForzados(['meta' => 100.0, 'pcS' => 99.9])->validar()))->toBe('EC-V12-294');
});

it('MR_ValidarBuckets tolera diferencias de centavos (≤ 0.02)', function () {
    v12BucketsForzados(['meta' => 100.0, 'pcS' => 100.0, 'cobS' => 100.02, 'pcA' => 5.0, 'cobA' => 5.02])->validar();

    expect(true)->toBeTrue();
});

it('rechaza parámetros inválidos con su código EC-V12', function (array $parametros, string $codigo) {
    $a = F::contrato('A');
    $calc = new EfectividadV12Calculator;

    expect(v12Codigo(fn () => $calc->calculate($parametros, F::fotos([$a], []), F::ingresos([]), F::ingresos([]))))->toBe($codigo);
})->with([
    'domingo base que no es domingo' => [['modo' => 'SEMANAL', 'domingo_base' => '2026-10-26', 'fecha_corte' => '2026-11-02'], 'EC-V12-212'],
    'modalidad inválida' => [['modo' => 'MENSUAL', 'domingo_base' => F::DOMINGO, 'fecha_corte' => F::CORTE], 'EC-V12-202'],
    'corte semanal distinto al domingo siguiente' => [['modo' => 'SEMANAL', 'domingo_base' => F::DOMINGO, 'fecha_corte' => '2026-10-30'], 'EC-V12-206'],
    'corte fuera de la semana' => [['modo' => 'ACUMULADO', 'domingo_base' => F::DOMINGO, 'fecha_corte' => '2026-11-02'], 'EC-V12-213'],
    'domingo base ilegible' => [['modo' => 'SEMANAL', 'domingo_base' => 'no es fecha', 'fecha_corte' => F::CORTE], 'EC-V12-211'],
]);

it('rechaza una fotografía sin columnas indispensables (EC-V12-320)', function () {
    $fotos = F::fotos([F::contrato('A')], []);
    $fotos[4]['columnas'] = array_values(array_diff($fotos[4]['columnas'], ['capital_pendiente']));

    expect(v12Codigo(fn () => (new EfectividadV12Calculator)->calculate(
        ['modo' => 'SEMANAL', 'domingo_base' => F::DOMINGO, 'fecha_corte' => F::CORTE], $fotos, F::ingresos([]), F::ingresos([]),
    )))->toBe('EC-V12-320');
});

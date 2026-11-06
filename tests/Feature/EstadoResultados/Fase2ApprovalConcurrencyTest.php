<?php

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\EstadoResultados\EstadoResultadosVersionLog;
use App\Models\EstadoResultados\EstadoResultadosVersionSnapshot;
use App\Models\Period;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Reporting\ApprovalInputSignature;
use App\Services\EstadoResultados\Reporting\EstadoResultadosApprovalService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosGovernanceException;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Fase 2.2 — consistencia de la aprobación: cada versión aprobada representa EXACTAMENTE
 * el dataset validado. Concurrencia simulada en un solo proceso (SQLite): se interviene
 * entre la construcción de los datasets y la escritura.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    G::fakePdfRenderer();
});

/** Firma de insumos que ejecuta $hook justo ANTES del 2.º cálculo (dentro de la transacción). */
function acHookSignature(callable $hook): void
{
    app()->instance(ApprovalInputSignature::class, new class(app(PeriodDataIds::class), app(ErpExpenseRepository::class), $hook) extends ApprovalInputSignature
    {
        private int $calls = 0;

        public function __construct(PeriodDataIds $d, ErpExpenseRepository $e, private $hook)
        {
            parent::__construct($d, $e);
        }

        public function compute(Period $period): array
        {
            if (++$this->calls === 2) {
                ($this->hook)($period);
            }

            return parent::compute($period);
        }
    });
}

function acApprove(Period $p, ?string $hash = null): EstadoResultadosVersion
{
    return app(EstadoResultadosApprovalService::class)->approve($p, G::admin(), 'cierre', $hash);
}

it('aprueba y cada snapshot guardado es byte a byte el dataset validado (hash = expected_hash)', function () {
    $p = G::readyPeriod();
    $expected = app(EstadoResultadosDatasetBuilder::class)->build($p)['meta']['dataset_hash'];
    $v = acApprove($p, $expected);

    expect($v->dataset_hash)->toBe($expected)
        ->and($v->current_period_id)->toBe($p->id)
        ->and($v->input_signature)->toHaveLength(64);
    foreach (EstadoResultadosVersionSnapshot::query()->where('version_id', $v->id)->get() as $s) {
        expect(hash('sha256', $s->dataset))->toBe($s->payload_hash)
            ->and(json_decode($s->dataset, true)['meta']['dataset_hash'])->toBe($s->dataset_hash);
    }
    expect(EstadoResultadosVersionLog::query()->where('action', 'aprobado')->first()->details['input_signature'])->toBe($v->input_signature);
});

it('dos aprobaciones simultáneas del mismo periodo: la segunda recibe 409 y no crea versión', function () {
    $p = G::readyPeriod();
    $other = Cache::lock(EstadoResultadosApprovalService::lockKey($p->id), 600);
    expect($other->get())->toBeTrue();

    $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/aprobar")
        ->assertStatus(409)->assertJsonPath('conflict', 'aprobacion_en_curso');
    expect(EstadoResultadosVersion::query()->count())->toBe(0);
    $other->release();

    $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/aprobar")->assertOk();
});

it('si cambian los datos durante la aprobación (clasificación, ajuste, ERP, movimiento) se rechaza con 409 sin escribir', function (callable $change, string $component) {
    $p = G::readyPeriod();
    acHookSignature($change);

    try {
        acApprove($p);
        $this->fail('Debió rechazar la aprobación');
    } catch (EstadoResultadosGovernanceException $e) {
        expect($e->status())->toBe(409)
            ->and($e->payload()['conflict'])->toBe('datos_cambiaron')
            ->and($e->payload()['changed'])->toContain($component)
            ->and($e->getMessage())->toContain('cambiaron mientras se aprobaba');
    }
    expect(EstadoResultadosVersion::query()->count())->toBe(0)
        ->and(EstadoResultadosVersionSnapshot::query()->count())->toBe(0)
        ->and(EstadoResultadosVersionLog::query()->where('action', 'aprobacion_rechazada')->count())->toBe(1);
})->with([
    'clasificación' => [fn (Period $p) => EstadoResultadosClassification::query()->create(['period_id' => $p->id, 'item_fingerprint' => 'x', 'item_source' => 'banco', 'treatment' => 'capex', 'previous_treatment' => 'pendiente', 'notes' => 'cambio concurrente']), 'classifications'],
    'ajuste' => [fn (Period $p) => DB::table('estado_resultados_adjustments')->where('period_id', $p->id)->where('concept', 'isr')->update(['state' => 'capturado', 'amount' => 5, 'updated_at' => now()->addSecond()]), 'adjustments'],
    'ERP re-importado' => [fn (Period $p) => F::erp($p, 'CORPORATIVO', 'REQ-NEW', '10.00', '2026-09-05'), 'erp_signature'],
    'movimiento bancario' => [fn (Period $p) => DB::table('estado_resultados_movements')->where('period_id', $p->id)->limit(1)->update(['cargo' => DB::raw('cargo + 1')]), 'movements'],
    'NOI re-importado' => [fn (Period $p) => G::noi($p, '5.00'), 'fact.fact_noi_movements'],
]);

it('si otro usuario aprueba en medio, la transacción lo detecta (409) y no escribe una segunda versión', function () {
    $p = G::readyPeriod();
    acHookSignature(function (Period $p) {
        // Otro proceso que no respetó el candado de aplicación escribe su versión.
        EstadoResultadosVersion::query()->create([
            'period_id' => $p->id, 'version' => 1, 'status' => 'aprobado', 'current_period_id' => $p->id, 'rules_version' => 'x',
            'dataset_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64), 'scopes' => [], 'sources' => [],
            'classifications' => [], 'adjustments' => [], 'approved_at' => now(),
        ]);
    });

    try {
        acApprove($p);
        $this->fail('Debió rechazar la aprobación');
    } catch (EstadoResultadosGovernanceException $e) {
        expect($e->status())->toBe(409)->and($e->payload()['conflict'])->toBe('version_cambio');
    }
    // En SQLite (una sola conexión) la fila del «otro proceso» viaja en la misma transacción y se revierte con ella.
    expect(EstadoResultadosVersion::query()->where('period_id', $p->id)->count())->toBe(0);
});

it('candado expirado durante una corrida larga: no se escribe la versión', function () {
    $p = G::readyPeriod();
    acHookSignature(function (Period $p) {
        Cache::lock(EstadoResultadosApprovalService::lockKey($p->id))->forceRelease();
        Cache::lock(EstadoResultadosApprovalService::lockKey($p->id), 600)->get(); // lo toma otro proceso
    });

    try {
        acApprove($p);
        $this->fail('Debió rechazar la aprobación');
    } catch (EstadoResultadosGovernanceException $e) {
        expect($e->status())->toBe(409)->and($e->payload()['conflict'])->toBe('candado_expirado');
    }
    expect(EstadoResultadosVersion::query()->count())->toBe(0);
});

it('expected_hash distinto del dataset construido → 409', function () {
    $p = G::readyPeriod();
    expect(fn () => acApprove($p, str_repeat('0', 64)))->toThrow(EstadoResultadosGovernanceException::class, 'Las cifras cambiaron');
    expect(EstadoResultadosVersion::query()->count())->toBe(0);
});

it('BD: a lo más una versión vigente por periodo y (periodo, versión) única', function () {
    $p = G::readyPeriod();
    $row = fn (int $n, ?int $current) => [
        'period_id' => $p->id, 'version' => $n, 'status' => 'aprobado', 'current_period_id' => $current, 'rules_version' => 'x',
        'dataset_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64), 'scopes' => '[]', 'sources' => '[]',
        'classifications' => '[]', 'adjustments' => '[]', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('estado_resultados_versions')->insert($row(1, $p->id));
    expect(fn () => DB::table('estado_resultados_versions')->insert($row(2, $p->id)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('estado_resultados_versions')->insert($row(1, null)))->toThrow(QueryException::class);
    DB::table('estado_resultados_versions')->insert($row(2, null)); // sustituida: NULL no choca
    expect(DB::table('estado_resultados_versions')->where('period_id', $p->id)->count())->toBe(2);
});

it('nueva versión aprobada: la anterior deja de ser vigente (current_period_id = NULL) y queda sustituida', function () {
    $p = G::readyPeriod();
    $v1 = acApprove($p);
    app(EstadoResultadosApprovalService::class)->openNewVersion($p, G::admin(), 'Corrección de un ajuste contable');
    $v2 = acApprove($p);

    expect($v1->fresh()->status)->toBe('sustituido')
        ->and($v1->fresh()->current_period_id)->toBeNull()
        ->and($v1->fresh()->superseded_by_version_id)->toBe($v2->id)
        ->and($v2->current_period_id)->toBe($p->id);
});

it('escritura que llega cuando el periodo ya se aprobó: re-verificación con la fila bloqueada → 423', function () {
    $p = G::readyPeriod();
    acApprove($p);
    expect(fn () => DB::transaction(fn () => app(PeriodLockGuard::class)->lockForWrite($p)))->toThrow(EstadoResultadosLockedException::class);
});

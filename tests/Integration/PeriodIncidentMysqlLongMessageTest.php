<?php

use App\Models\Period;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 — la PRUEBA REAL de que la migración
 * 2026_08_27_000001_expand_period_incidents_message_column ya evita el
 * SQLSTATE[22001] "Data too long for column 'message'" contra MySQL/MariaDB de
 * verdad (no SQLite, que no aplica límite de longitud — ver PeriodIncidentLong
 * MessageTest.php en tests/Feature, cuya nota de cobertura señala que ese test
 * pasaría incluso SIN el fix). Corre sobre la conexión aislada mysql_testing
 * (reportes_test) — ver tests/Integration/README.md.
 */
it('confirms message is TEXT (not VARCHAR(255)) on the real MySQL/MariaDB schema', function () {
    $column = DB::connection('mysql_testing')
        ->selectOne("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'period_incidents' AND COLUMN_NAME = 'message'");

    expect($column)->not->toBeNull();
    expect(strtolower($column->DATA_TYPE))->toBe('text');
});

it('persists a message of several thousand characters against real MySQL without SQLSTATE[22001]', function () {
    $period = Period::query()->create([
        'name' => 'Periodo mysql mensaje largo', 'code' => 'M-MYSQL-LONGMSG', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
    ]);

    // Plantilla real de NoiNominaImportService + nombre largo, y un segundo caso de
    // varios miles de caracteres — el mismo escenario que producía el 1406 real
    // antes de la migración, ahora contra MySQL de verdad (strict=true, ver
    // config/database.php), no SQLite.
    $longEmployeeName = str_repeat('MARIA DE LOS ANGELES GUADALUPE FERNANDEZ ', 20);
    $message1000 = "\"{$longEmployeeName}\" tiene pago en NOI pero no se encontró en el directorio Lendus, "
        . 'cartera/colocación/cobranza ni asignaciones históricas. Verificar identidad y sucursal antes de confiar en el reporte.';
    $message5000 = str_repeat('Registro de auditoría con nombres largos y acentos: José Ángel Núñez Domínguez. ', 65);

    expect(mb_strlen($message1000))->toBeGreaterThan(1000);
    expect(mb_strlen($message5000))->toBeGreaterThan(5000);

    foreach ([$message1000, $message5000] as $i => $message) {
        $incident = PeriodIncident::query()->create([
            'period_summary_id' => $summary->id,
            'type'     => 'noi_identidad.sin_respaldo',
            'severity' => 'warning',
            'message'  => $message,
            'context'  => ['case' => $i],
        ]);

        $incident->refresh();
        expect($incident->message)->toBe($message);
    }
});

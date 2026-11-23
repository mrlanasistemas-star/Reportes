<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Migración aditiva de índices (23-nov-2026): crea los índices compuestos y es idempotente
 * (volver a ejecutarla no falla ni duplica índices).
 */
it('crea los índices de rendimiento y se puede volver a ejecutar sin error', function () {
    $migration = include database_path('migrations/2026_11_23_200002_add_missing_performance_indexes.php');

    expect(Schema::hasIndex('fact_placements', 'fact_placements_period_operation_date_idx'))->toBeTrue()
        ->and(Schema::hasIndex('fact_expenses', ['period_id', 'expense_date']))->toBeTrue()
        ->and(Schema::hasIndex('period_database_update_runs', ['period_id', 'status']))->toBeTrue()
        ->and(Schema::hasIndex('api_query_logs', ['created_at']))->toBeTrue();

    $before = count(Schema::getIndexes('fact_placements'));
    $migration->up();
    $migration->up();

    expect(count(Schema::getIndexes('fact_placements')))->toBe($before);
});

it('el rollback sólo quita los índices que agregó', function () {
    $migration = include database_path('migrations/2026_11_23_200002_add_missing_performance_indexes.php');

    $migration->down();
    expect(Schema::hasIndex('fact_placements', 'fact_placements_period_operation_date_idx'))->toBeFalse()
        ->and(Schema::hasIndex('fact_placements', ['period_id', 'branch_id']))->toBeTrue();

    $migration->up();
    expect(Schema::hasIndex('fact_placements', 'fact_placements_period_operation_date_idx'))->toBeTrue();
});

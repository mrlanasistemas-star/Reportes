<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Purga diaria de la caché en base de datos (23-nov-2026): sólo borra filas vencidas,
 * nunca una clave vigente; con otro store no hace nada.
 */
it('borra sólo las filas vencidas de la caché y de los candados', function () {
    config(['cache.default' => 'database']);
    $now = time();
    DB::table('cache')->insert([
        ['key' => 'vencida-1', 'value' => 's:1:"a";', 'expiration' => $now - 60],
        ['key' => 'vencida-2', 'value' => 's:1:"b";', 'expiration' => $now - 3600],
        ['key' => 'vigente', 'value' => 's:1:"c";', 'expiration' => $now + 3600],
    ]);
    DB::table('cache_locks')->insert([
        ['key' => 'candado-viejo', 'owner' => 'x', 'expiration' => $now - 10],
        ['key' => 'candado-vivo', 'owner' => 'y', 'expiration' => $now + 600],
    ]);

    $this->artisan('reportes:purgar-cache-vencida', ['--lote' => 100])->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->all())->toBe(['vigente'])
        ->and(DB::table('cache_locks')->pluck('key')->all())->toBe(['candado-vivo']);
});

it('con un store que no es de base de datos no toca nada', function () {
    DB::table('cache')->insert(['key' => 'vencida', 'value' => 's:1:"a";', 'expiration' => time() - 60]);

    $this->artisan('reportes:purgar-cache-vencida', ['--store' => 'array'])->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(1);
});

it('la purga de caché, de jobs fallidos y de api_query_logs están programadas', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

    expect($commands)->toContain('reportes:purgar-cache-vencida')
        ->toContain('queue:prune-failed')
        ->toContain('queue:prune-batches')
        ->toContain('api:query-logs:prune');
});

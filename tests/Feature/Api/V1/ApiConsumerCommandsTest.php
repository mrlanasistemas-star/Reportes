<?php

use App\Models\ApiConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an api consumer with a usable token via artisan', function () {
    $this->artisan('api:consumers:create', [
        'name' => 'Auditoría Operativa',
        'system_code' => 'auditoria-cmd-test',
        '--ability' => ['branches.read'],
    ])->assertExitCode(0);

    $consumer = ApiConsumer::query()->where('system_code', 'auditoria-cmd-test')->first();

    expect($consumer)->not->toBeNull();
    expect($consumer->is_active)->toBeTrue();
    expect($consumer->tokens()->count())->toBe(1);
});

it('refuses to create a duplicate system_code', function () {
    ApiConsumer::query()->create(['name' => 'X', 'system_code' => 'dup-code', 'is_active' => true]);

    $this->artisan('api:consumers:create', [
        'name' => 'Y',
        'system_code' => 'dup-code',
    ])->assertExitCode(1);
});

it('restricts the consumer to the given branches', function () {
    $this->artisan('api:consumers:create', [
        'name' => 'Auditoría Córdoba',
        'system_code' => 'auditoria-branch-test',
        '--branch' => ['5', '9'],
    ])->assertExitCode(0);

    $consumer = ApiConsumer::query()->where('system_code', 'auditoria-branch-test')->first();

    expect($consumer->allowed_branch_ids)->toBe([5, 9]);
    expect($consumer->canAccessBranch(5))->toBeTrue();
    expect($consumer->canAccessBranch(7))->toBeFalse();
    expect($consumer->canAccessGeneralScope())->toBeFalse();
});

it('revokes all tokens and deactivates the consumer', function () {
    $consumer = ApiConsumer::query()->create(['name' => 'X', 'system_code' => 'revoke-test', 'is_active' => true]);
    $consumer->createToken('t1');
    $consumer->createToken('t2');

    $this->artisan('api:consumers:revoke', [
        'system_code' => 'revoke-test',
        '--deactivate' => true,
    ])->assertExitCode(0);

    expect($consumer->tokens()->count())->toBe(0);
    expect($consumer->fresh()->is_active)->toBeFalse();
});

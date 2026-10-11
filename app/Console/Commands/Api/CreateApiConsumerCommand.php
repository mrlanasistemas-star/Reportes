<?php

namespace App\Console\Commands\Api;

use App\Models\ApiConsumer;
use Illuminate\Console\Command;

/**
 * Fase 1.6 — no hay (ni se pidió) una pantalla de administración de
 * consumidores: esta es la única forma de emitir credenciales hoy. El token
 * en texto plano solo se muestra UNA vez (igual que Sanctum/GitHub/Stripe) —
 * nunca se guarda en texto plano en la BD ni en logs.
 */
class CreateApiConsumerCommand extends Command
{
    protected $signature = 'api:consumers:create
                                {name : Nombre del sistema consumidor, p.ej. "Auditoría Operativa"}
                                {system_code : Código único, p.ej. "auditoria-operativa"}
                                {--ability=* : Abilities del token, p.ej. --ability=branches.read --ability=financial.read}
                                {--branch=* : IDs de sucursal a las que se restringe (vacío = sin restricción)}';

    protected $description = 'Crea un ApiConsumer (sistema externo) y emite su primer token Sanctum para /api/v1';

    public function handle(): int
    {
        $systemCode = (string) $this->argument('system_code');

        if (ApiConsumer::query()->where('system_code', $systemCode)->exists()) {
            $this->error("Ya existe un ApiConsumer con system_code '{$systemCode}'.");

            return 1;
        }

        $abilities = $this->option('ability') ?: ['branches.read', 'employees.read', 'periods.read', 'financial.read', 'collections.read', 'okr.read'];
        $branchIds = array_map('intval', $this->option('branch') ?: []);

        $consumer = ApiConsumer::query()->create([
            'name'               => $this->argument('name'),
            'system_code'        => $systemCode,
            'abilities'          => $abilities,
            'allowed_branch_ids' => empty($branchIds) ? null : $branchIds,
            'is_active'          => true,
        ]);

        $token = $consumer->createToken('initial-token', $abilities)->plainTextToken;

        $this->info("Consumidor '{$consumer->name}' ({$consumer->system_code}) creado con id={$consumer->id}.");
        $this->info('Abilities: ' . implode(', ', $abilities));
        $this->info($branchIds ? 'Restringido a sucursales: ' . implode(', ', $branchIds) : 'Sin restricción de sucursal (scope=general permitido).');
        $this->newLine();
        $this->warn('TOKEN (se muestra una sola vez, guárdalo ahora):');
        $this->line($token);

        return 0;
    }
}

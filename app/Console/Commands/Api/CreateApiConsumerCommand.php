<?php

namespace App\Console\Commands\Api;

use App\Models\ApiConsumer;
use Illuminate\Console\Command;

/**
 * Comando `api:consumers:create` — da de alta un sistema externo (ApiConsumer) y le emite
 * su primer token Sanctum para consumir la API /api/v1.
 *
 * Fase 1.6 — no hay (ni se pidió) una pantalla de administración de consumidores: esta es la
 * única forma de emitir credenciales hoy. El token en texto plano solo se muestra UNA vez
 * (igual que Sanctum/GitHub/Stripe) — nunca se guarda en texto plano en la BD ni en logs.
 *
 * Fase 2.5 — estado de sucursal EXPLÍCITO (nunca inferido de un array vacío):
 * `--branch` → restricted; `--deny-branches` → none; ninguno de los dos → global
 * (comportamiento histórico, sin cambios).
 *
 * Cuándo usarlo: al integrar un nuevo sistema consumidor de la API.
 * Escribe en BD: SÍ — inserta en `api_consumers` y en `personal_access_tokens`.
 *
 * Ejemplos:
 *   php artisan api:consumers:create "Auditoría Operativa" auditoria-operativa
 *   php artisan api:consumers:create "BI" bi --ability=branches.read --ability=financial.read --branch=3 --branch=5
 *   php artisan api:consumers:create "Demo" demo --deny-branches
 */
class CreateApiConsumerCommand extends Command
{
    protected $signature = 'api:consumers:create
                                {name : Nombre del sistema consumidor, p.ej. "Auditoría Operativa"}
                                {system_code : Código único, p.ej. "auditoria-operativa"}
                                {--ability=* : Abilities del token, p.ej. --ability=branches.read --ability=financial.read}
                                {--branch=* : IDs de sucursal a las que se restringe (branch_access_mode=restricted)}
                                {--deny-branches : Sin acceso a ninguna sucursal (branch_access_mode=none) — incompatible con --branch}';

    protected $description = 'Crea un ApiConsumer (sistema externo) y emite su primer token Sanctum para /api/v1';

    /**
     * Valida que el `system_code` no exista y que `--branch` y `--deny-branches` no se combinen,
     * crea el ApiConsumer con sus abilities (por defecto todas las de lectura) y su modo de acceso
     * a sucursales, emite el token "initial-token" y lo imprime una única vez.
     *
     * @return int 0 si se creó el consumidor; 1 si el código ya existe o las opciones son incompatibles.
     */
    public function handle(): int
    {
        $systemCode = (string) $this->argument('system_code');

        if (ApiConsumer::query()->where('system_code', $systemCode)->exists()) {
            $this->error("Ya existe un ApiConsumer con system_code '{$systemCode}'.");

            return 1;
        }

        $branchIds = array_map('intval', $this->option('branch') ?: []);
        $denyBranches = (bool) $this->option('deny-branches');

        if ($denyBranches && ! empty($branchIds)) {
            $this->error('--branch y --deny-branches son incompatibles entre sí.');

            return 1;
        }

        $branchAccessMode = $denyBranches
            ? ApiConsumer::BRANCH_ACCESS_NONE
            : (empty($branchIds) ? ApiConsumer::BRANCH_ACCESS_GLOBAL : ApiConsumer::BRANCH_ACCESS_RESTRICTED);

        $abilities = $this->option('ability') ?: ['branches.read', 'employees.read', 'periods.read', 'financial.read', 'collections.read', 'okr.read'];

        $consumer = ApiConsumer::query()->create([
            'name' => $this->argument('name'),
            'system_code' => $systemCode,
            'abilities' => $abilities,
            'allowed_branch_ids' => empty($branchIds) ? null : $branchIds,
            'branch_access_mode' => $branchAccessMode,
            'is_active' => true,
        ]);

        $token = $consumer->createToken('initial-token', $abilities)->plainTextToken;

        $this->info("Consumidor '{$consumer->name}' ({$consumer->system_code}) creado con id={$consumer->id}.");
        $this->info('Abilities: '.implode(', ', $abilities));
        $this->info(match ($branchAccessMode) {
            ApiConsumer::BRANCH_ACCESS_RESTRICTED => 'Restringido a sucursales: '.implode(', ', $branchIds),
            ApiConsumer::BRANCH_ACCESS_NONE => 'SIN acceso a ninguna sucursal (branch_access_mode=none).',
            default => 'Sin restricción de sucursal (scope=general permitido).',
        });
        $this->newLine();
        $this->warn('TOKEN (se muestra una sola vez, guárdalo ahora):');
        $this->line($token);

        return 0;
    }
}

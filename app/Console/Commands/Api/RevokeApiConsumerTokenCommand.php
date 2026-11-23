<?php

namespace App\Console\Commands\Api;

use App\Models\ApiConsumer;
use Illuminate\Console\Command;

/**
 * Comando `api:consumers:revoke` — revoca tokens Sanctum de un consumidor de la API.
 *
 * Fase 1.6 — "tokens individuales revocables": con `--token-id` revoca solo ese token; sin él
 * revoca TODOS los tokens del consumidor. Con `--deactivate` además marca al consumidor como
 * inactivo (`is_active = false`).
 *
 * Cuándo usarlo: ante una fuga de credenciales o al dar de baja una integración.
 * Escribe en BD: SÍ — borra filas de `personal_access_tokens` y opcionalmente actualiza `api_consumers`.
 *
 * Ejemplos:
 *   php artisan api:consumers:revoke auditoria-operativa
 *   php artisan api:consumers:revoke auditoria-operativa --token-id=12
 *   php artisan api:consumers:revoke auditoria-operativa --deactivate
 */
class RevokeApiConsumerTokenCommand extends Command
{
    protected $signature = 'api:consumers:revoke
                                {system_code : Código del consumidor}
                                {--token-id= : ID específico del token (omitir para revocar TODOS los tokens del consumidor)}
                                {--deactivate : Además marcar el consumidor como inactivo (is_active=false)}';

    protected $description = 'Revoca uno o todos los tokens Sanctum de un ApiConsumer';

    /**
     * Busca el consumidor por `system_code`, elimina el token indicado (o todos) y, si se pidió,
     * lo desactiva.
     *
     * @return int 0 al revocar; 1 si el consumidor no existe.
     */
    public function handle(): int
    {
        $consumer = ApiConsumer::query()->where('system_code', $this->argument('system_code'))->first();

        if (! $consumer) {
            $this->error("No existe un ApiConsumer con system_code '{$this->argument('system_code')}'.");

            return 1;
        }

        $tokenId = $this->option('token-id');
        $count = $tokenId
            ? $consumer->tokens()->where('id', $tokenId)->delete()
            : $consumer->tokens()->delete();

        if ($this->option('deactivate')) {
            $consumer->forceFill(['is_active' => false])->save();
        }

        $this->info("Revocados {$count} token(s) de '{$consumer->system_code}'.".($this->option('deactivate') ? ' Consumidor desactivado.' : ''));

        return 0;
    }
}

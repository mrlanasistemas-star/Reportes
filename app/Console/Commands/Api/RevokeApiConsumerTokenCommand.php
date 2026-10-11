<?php

namespace App\Console\Commands\Api;

use App\Models\ApiConsumer;
use Illuminate\Console\Command;

/** Fase 1.6 — "tokens individuales revocables". */
class RevokeApiConsumerTokenCommand extends Command
{
    protected $signature = 'api:consumers:revoke
                                {system_code : Código del consumidor}
                                {--token-id= : ID específico del token (omitir para revocar TODOS los tokens del consumidor)}
                                {--deactivate : Además marcar el consumidor como inactivo (is_active=false)}';

    protected $description = 'Revoca uno o todos los tokens Sanctum de un ApiConsumer';

    public function handle(): int
    {
        $consumer = ApiConsumer::query()->where('system_code', $this->argument('system_code'))->first();

        if (!$consumer) {
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

        $this->info("Revocados {$count} token(s) de '{$consumer->system_code}'." . ($this->option('deactivate') ? ' Consumidor desactivado.' : ''));

        return 0;
    }
}

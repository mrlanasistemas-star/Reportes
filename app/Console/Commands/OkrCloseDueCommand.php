<?php

namespace App\Console\Commands;

use App\Services\Okr\OkrClosingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Módulo OKR (08-sep-2026) — cierre automático de Objectives vencidos
 * (sección 32/55 del pedido). Idempotente — un Objective ya cerrado se omite.
 */
class OkrCloseDueCommand extends Command
{
    protected $signature = 'okr:close-due';

    protected $description = 'Cierra los OKR activos cuya end_date ya pasó, clasificando el resultado final.';

    public function handle(OkrClosingService $closingService): int
    {
        $count = $closingService->closeDue();
        $this->info("{$count} OKR cerrado(s).");

        Cache::forever('okr:last_close_due_at', now()->toDateTimeString());

        return 0;
    }
}

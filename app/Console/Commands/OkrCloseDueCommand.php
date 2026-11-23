<?php

namespace App\Console\Commands;

use App\Services\Okr\OkrClosingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Comando `okr:close-due` — Módulo OKR (08-sep-2026): cierre automático de los Objectives
 * activos cuya end_date ya pasó, clasificando el resultado final (sección 32/55 del pedido).
 * Idempotente — un Objective ya cerrado se omite. Registra la hora en caché
 * (`okr:last_close_due_at`) para `okr:health`.
 *
 * Cuándo usarlo: lo ejecuta el scheduler diario a las 06:15; a mano si el cron no corrió.
 * Escribe en BD: SÍ (cierra `okr_objectives` vencidos) y una clave de caché.
 *
 * Ejemplo:
 *   php artisan okr:close-due
 */
class OkrCloseDueCommand extends Command
{
    protected $signature = 'okr:close-due';

    protected $description = 'Cierra los OKR activos cuya end_date ya pasó, clasificando el resultado final.';

    /**
     * Cierra los OKR vencidos con OkrClosingService e informa cuántos cerró.
     *
     * @param  OkrClosingService  $closingService  Servicio de cierre de OKR.
     * @return int Siempre 0.
     */
    public function handle(OkrClosingService $closingService): int
    {
        $count = $closingService->closeDue();
        $this->info("{$count} OKR cerrado(s).");

        Cache::forever('okr:last_close_due_at', now()->toDateTimeString());

        return 0;
    }
}

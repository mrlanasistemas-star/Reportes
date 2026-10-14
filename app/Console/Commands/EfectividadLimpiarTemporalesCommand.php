<?php

namespace App\Console\Commands;

use App\Services\Efectividad\Calculadora\EfectividadWorkspace;
use Illuminate\Console\Command;

/** Borra los espacios temporales vencidos de la calculadora de Efectividad. */
class EfectividadLimpiarTemporalesCommand extends Command
{
    protected $signature = 'efectividad:limpiar-temporales {--todo : Borra todos los espacios, sin importar su antigüedad}';

    protected $description = 'Elimina archivos temporales vencidos de la calculadora de Efectividad de Cobranza';

    public function handle(EfectividadWorkspace $workspace): int
    {
        $n = $workspace->purgarVencidos($this->option('todo') ? 0 : EfectividadWorkspace::TTL_MINUTOS);
        $this->info("Espacios temporales eliminados: {$n}");

        return self::SUCCESS;
    }
}

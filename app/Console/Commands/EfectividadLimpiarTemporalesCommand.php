<?php

namespace App\Console\Commands;

use App\Services\Efectividad\Calculadora\EfectividadWorkspace;
use Illuminate\Console\Command;

/**
 * Comando `efectividad:limpiar-temporales` — borra los espacios temporales vencidos de la
 * calculadora de Efectividad de Cobranza (Excel leídos y cifrados, PDF/PNG no descargados).
 * Con `--todo` borra todos los espacios sin importar su antigüedad.
 *
 * Cuándo usarlo: lo ejecuta el scheduler cada 10 minutos (routes/console.php). A mano, `--todo`
 * puede interrumpir a un usuario que esté usando la calculadora en ese momento.
 * Escribe en BD: NO — borra archivos temporales en disco.
 *
 * Ejemplos:
 *   php artisan efectividad:limpiar-temporales
 *   php artisan efectividad:limpiar-temporales --todo
 */
class EfectividadLimpiarTemporalesCommand extends Command
{
    protected $signature = 'efectividad:limpiar-temporales {--todo : Borra todos los espacios, sin importar su antigüedad}';

    protected $description = 'Elimina archivos temporales vencidos de la calculadora de Efectividad de Cobranza';

    /**
     * Purga los espacios con más de TTL_MINUTOS (o todos con `--todo`) e informa cuántos borró.
     *
     * @param  EfectividadWorkspace  $workspace  Gestor de espacios temporales de la calculadora.
     * @return int Siempre self::SUCCESS.
     */
    public function handle(EfectividadWorkspace $workspace): int
    {
        $n = $workspace->purgarVencidos($this->option('todo') ? 0 : EfectividadWorkspace::TTL_MINUTOS);
        $this->info("Espacios temporales eliminados: {$n}");

        return self::SUCCESS;
    }
}

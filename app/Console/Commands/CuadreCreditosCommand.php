<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use Illuminate\Console\Command;

/**
 * Cuadre de créditos de un periodo, desde consola: cuánto se DEBÍA pagar según la tabla de
 * amortización (tal cual el documento del acreedor), cuánto APORTARON las sucursales y cuánto
 * se PAGÓ al acreedor según el banco. Solo lectura: no modifica nada.
 *
 *   php artisan estado-resultados:cuadre-creditos 19
 *   php artisan estado-resultados:cuadre-creditos 19 --credito=lerom
 */
class CuadreCreditosCommand extends Command
{
    protected $signature = 'estado-resultados:cuadre-creditos
        {periodo : ID del periodo}
        {--credito= : Solo un crédito (lerom | bbva)}';

    protected $description = 'Muestra cuánto se debía pagar a LEROM/BBVA en el periodo, cuánto aportaron las sucursales y cuánto se pagó (solo lectura)';

    public function handle(FinancialExpenseService $service): int
    {
        $period = Period::query()->find((int) $this->argument('periodo'));
        if (! $period) {
            $this->error('No existe el periodo '.$this->argument('periodo').'.');

            return self::FAILURE;
        }

        $panel = $service->panel($period);
        $only = $this->option('credito');
        $money = fn ($v) => '$'.number_format((float) $v, 2);

        $this->info('Periodo '.$period->id.' · '.$period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y'));

        foreach ($panel['credits'] as $c) {
            $key = $c['credit']['key'] ?? null;
            if ($only && $key !== $only) {
                continue;
            }
            $this->newLine();
            $this->line('<options=bold>'.($c['credit']['name'] ?? $key).'</>');

            if (! ($c['has_table'] ?? false)) {
                $this->warn('  Sin tabla de amortización cargada.');

                continue;
            }

            $t = $c['totals'];
            $paid = $c['creditor_payments']['totals'] ?? [];
            $contrib = $c['contributions'] ?? [];
            $debe = (float) $t['payment'];
            $pagado = (float) ($paid['detected'] ?? 0);
            $aportado = (float) ($contrib['total'] ?? 0);

            $this->table(['Concepto', 'Importe'], [
                ['Pagos de la tabla en el periodo', (string) $t['count']],
                ['DEBÍA PAGAR (pago total de la tabla)', $money($debe)],
                ['  · Capital (no es gasto)', $money($t['principal'])],
                ['  · Interés', $money($t['interest'])],
                ['  · IVA de interés', $money($t['interest_vat'])],
                ['  · Comisión (informativa, no entra al resultado)', $money($t['commission'])],
                ['  · IVA de comisión', $money($t['commission_vat'])],
                ['APORTARON las sucursales', $money($aportado).' ('.($contrib['count'] ?? 0).' depósitos)'],
                ['PAGADO al acreedor (banco)', $money($pagado).' ('.($paid['reconciled_count'] ?? 0).' de '.($paid['expected_count'] ?? 0).' pagos localizados)'],
                ['Cargos al acreedor fuera de calendario', $money($paid['unscheduled'] ?? 0)],
                ['Diferencia pagado − debía', $money($pagado - $debe)],
                ['Diferencia aportado − debía', $money($aportado - $debe)],
            ]);

            $branches = collect($contrib['by_branch'] ?? [])->map(fn ($b) => [
                $b['branch'] ?? $b['name'] ?? '—',
                (string) ($b['count'] ?? ''),
                $money($b['amount'] ?? $b['total'] ?? 0),
            ])->all();
            if ($branches !== []) {
                $this->line('  Aportaciones por sucursal:');
                $this->table(['Sucursal', 'Depósitos', 'Importe'], $branches);
            }
        }

        return self::SUCCESS;
    }
}

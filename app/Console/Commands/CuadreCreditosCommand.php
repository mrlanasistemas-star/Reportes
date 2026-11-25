<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\EstadoResultados\Export\ErFinancingSection;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuadre de créditos de un periodo, desde consola: cuánto se DEBÍA pagar según la tabla de
 * amortización (tal cual el documento del acreedor), cuánto APORTARON las sucursales, cuánto se
 * PAGÓ al acreedor según el banco y cuánto quedó APLICADO al crédito (máx. de ambos, sin doble
 * conteo; ver FinancialExpenseService::applyFunds()), con el reparto por pago. Solo lectura.
 *
 * El periodo se indica por ID o por una fecha (YYYY-MM-DD): se elige el periodo que la contiene,
 * priorizando el de mayor duración (p. ej. el de 4 semanas sobre uno semanal); si hay varios se
 * listan los candidatos.
 *
 *   php artisan estado-resultados:cuadre-creditos 2026-04-15
 *   php artisan estado-resultados:cuadre-creditos 21 --credito=lerom
 */
class CuadreCreditosCommand extends Command
{
    protected $signature = 'estado-resultados:cuadre-creditos
        {periodo : ID del periodo o una fecha YYYY-MM-DD que caiga en él}
        {--credito= : Solo un crédito (lerom | bbva)}';

    protected $description = 'Muestra cuánto se debía pagar a LEROM/BBVA en el periodo, cuánto aportaron las sucursales, cuánto se pagó y cuánto quedó aplicado (solo lectura)';

    public function handle(FinancialExpenseService $service): int
    {
        $period = $this->resolvePeriod((string) $this->argument('periodo'));
        if (! $period) {
            return self::FAILURE;
        }

        $panel = $service->panel($period);
        $only = $this->option('credito');
        $money = fn ($v) => '$'.number_format((float) $v, 2);

        $this->info('Periodo '.$period->id.' · '.($period->name ?? '').' · '.$period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y'));

        foreach ($panel['credits'] as $c) {
            $key = $c['credit']['key'] ?? null;
            if ($only && $key !== $only) {
                continue;
            }
            $this->newLine();
            $this->line('<options=bold>'.($c['credit']['name'] ?? $key).'</> · '.(ErFinancingSection::CREDIT_STATUS[$c['status']['status']] ?? $c['status']['status']));

            if (! ($c['has_table'] ?? false)) {
                $this->warn('  Sin tabla de amortización cargada.');

                continue;
            }

            $t = $c['totals'];
            $a = $c['application'];
            $cp = $c['creditor_payments'];
            $contrib = $c['contributions'] ?? [];
            $diff = (float) $a['difference'];

            $this->table(['Concepto', 'Importe'], [
                ['DEBÍA PAGAR ('.$t['count'].' pagos de la tabla)', $money($a['expected'])],
                ['  · Capital (no es gasto)', $money($t['principal'])],
                ['  · Interés', $money($t['interest'])],
                ['  · Comisión (informativa salvo configuración)', $money($t['commission'])],
                ['  · IVA (interés + comisión)', $money((float) $t['interest_vat'] + (float) $t['commission_vat'])],
                ['APORTADO por las sucursales', $money($a['contributed']).' ('.($contrib['count'] ?? 0).' depósitos)'],
                ['PAGADO al acreedor (banco)', $money($a['paid_to_creditor']).($cp['unscheduled'] ? ' (incluye '.count($cp['unscheduled']).' cargo(s) no previsto(s): '.$money($cp['totals']['unscheduled']).')' : '')],
                ['APLICADO al crédito = máx(aportado, pagado)', $money($a['applied'])],
                [$diff < 0 ? 'FALTANTE' : 'EXCEDENTE', $money(abs($diff))],
                ['Pagos cubiertos', $a['covered_count'].' de '.$a['expected_count'].($a['partial_count'] ? ' (+'.$a['partial_count'].' parcial)' : '')],
            ]);

            if ($cp['rows'] !== []) {
                $this->table(['No.', 'Fecha', 'Esperado', 'Aplicado', 'Faltante', 'Estado'], array_map(fn ($r) => [
                    $r['number'] ?? '—',
                    CarbonImmutable::parse($r['scheduled_date'])->format('d/m/Y'),
                    $money($r['expected_payment']),
                    $money($r['applied']),
                    $money($r['shortfall']),
                    ErFinancingSection::coverageLabel($r['coverage'], $a['basis']),
                ], $cp['rows']));
            }

            $branches = collect($contrib['by_branch'] ?? [])->map(fn ($b) => [$b['branch'], $money($b['amount'])])->all();
            if ($branches !== []) {
                $this->line('  Aportaciones por sucursal (identificadas):');
                $this->table(['Sucursal', 'Importe'], $branches);
            }
        }
        $this->newLine();
        $this->line(ErFinancingSection::APPLIED_NOTE);

        return self::SUCCESS;
    }

    /** Periodo por ID o por fecha contenida (mayor duración primero). */
    private function resolvePeriod(string $arg): ?Period
    {
        if (ctype_digit($arg)) {
            $period = Period::query()->find((int) $arg);
            if (! $period) {
                $this->error('No existe el periodo '.$arg.'.');
            }

            return $period;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) || ! strtotime($arg)) {
            $this->error('Indica el ID del periodo o una fecha YYYY-MM-DD.');

            return null;
        }

        /** @var Collection<int, Period> $candidates */
        $candidates = Period::query()->whereDate('start_date', '<=', $arg)->whereDate('end_date', '>=', $arg)->get()
            ->sortBy([
                // Primero el periodo con estados de cuenta cargados (donde viven aportaciones y pagos).
                fn ($a, $b) => self::hasMovements($b) <=> self::hasMovements($a),
                fn ($a, $b) => self::days($b) <=> self::days($a),
                fn ($a, $b) => $b->id <=> $a->id,
            ])->values();
        if ($candidates->isEmpty()) {
            $this->error('Ningún periodo contiene el '.CarbonImmutable::parse($arg)->format('d/m/Y').'.');

            return null;
        }
        $chosen = $candidates->first();
        if ($candidates->count() > 1) {
            $this->warn('Varios periodos contienen el '.CarbonImmutable::parse($arg)->format('d/m/Y').'; se usa el que tiene estados de cuenta cargados (o el más largo). Para otro, indica su ID:');
            $this->table(['', 'ID', 'Periodo', 'Del', 'Al', 'Días'], $candidates->map(fn (Period $p) => [
                $p->is($chosen) ? '→' : '', $p->id, (string) ($p->name ?? ''), $p->start_date->format('d/m/Y'), $p->end_date->format('d/m/Y'), self::days($p),
            ])->all());
        }

        return $chosen;
    }

    private static function hasMovements(Period $p): int
    {
        return DB::table('estado_resultados_movements')->where('period_id', $p->id)->exists() ? 1 : 0;
    }

    private static function days(Period $p): int
    {
        return (int) $p->start_date->diffInDays($p->end_date) + 1;
    }
}

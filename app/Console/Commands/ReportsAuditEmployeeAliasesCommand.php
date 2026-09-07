<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría 07-sep-2026 (cierre, sección 14) — diagnóstico de employee_aliases.
 * SOLO LECTURA — nunca borra ni modifica nada, solo reporta riesgos para
 * revisión humana:
 *   - normalized_alias duplicado entre employee_id DISTINTOS (el mismo alias
 *     apunta a más de una persona — riesgo real de asignación incorrecta si el
 *     matching por alias alguna vez tratara esto como único).
 *   - alias de una sola palabra (demasiado corto/genérico para ser confiable
 *     como identificador — riesgo de falso positivo).
 *   - alias que coincide con un nombre de pila común (heurística simple: 1-2
 *     tokens, ninguno de más de 4 caracteres consonánticos raros — se limita a
 *     señalar "corto" y dejar el juicio a la persona que revisa).
 *
 *   php artisan reports:audit-employee-aliases
 *   php artisan reports:audit-employee-aliases --employee=123
 */
class ReportsAuditEmployeeAliasesCommand extends Command
{
    protected $signature = 'reports:audit-employee-aliases {--employee= : Filtra por employee_id}';

    protected $description = 'Diagnóstico de employee_aliases (alias duplicados entre personas distintas, alias demasiado cortos) — solo lectura, nunca modifica nada.';

    public function handle(): int
    {
        $query = DB::table('employee_aliases as ea')
            ->join('employees as e', 'ea.employee_id', '=', 'e.id')
            ->select('ea.id', 'ea.employee_id', 'ea.alias_name', 'ea.normalized_alias', 'ea.source', 'ea.confidence', 'e.full_name');

        if ($employeeId = $this->option('employee')) {
            $query->where('ea.employee_id', (int) $employeeId);
        }

        $aliases = $query->orderBy('ea.normalized_alias')->get();

        if ($aliases->isEmpty()) {
            $this->comment('No hay alias registrados' . ($this->option('employee') ? ' para ese employee_id.' : '.'));
            return 0;
        }

        $this->info('════════════════════════════════════════════════════════════');
        $this->info('AUDITORÍA DE ALIAS — ' . $aliases->count() . ' alias registrados');
        $this->info('════════════════════════════════════════════════════════════');
        $this->newLine();

        // ── Duplicados: el MISMO normalized_alias apuntando a employee_id distintos ──
        $byAlias = $aliases->groupBy('normalized_alias');
        $duplicated = $byAlias->filter(fn ($group) => $group->pluck('employee_id')->unique()->count() > 1);

        $this->comment('ALIAS DUPLICADOS entre colaboradores DISTINTOS (' . $duplicated->count() . '):');
        if ($duplicated->isEmpty()) {
            $this->line('  Ninguno — cada alias apunta a un solo colaborador.');
        }
        foreach ($duplicated as $normalizedAlias => $group) {
            $this->warn("  \"{$normalizedAlias}\":");
            foreach ($group as $row) {
                $this->warn("    - employee_id={$row->employee_id} ({$row->full_name}) | fuente={$row->source} | confianza={$row->confidence}");
            }
        }
        $this->newLine();

        // ── Alias de una sola palabra ──────────────────────────────────────
        $oneWord = $aliases->filter(fn ($row) => count(array_filter(explode(' ', trim($row->normalized_alias)))) === 1);
        $this->comment('ALIAS DE UNA SOLA PALABRA (' . $oneWord->count() . ') — riesgo de falso positivo en matching:');
        foreach ($oneWord as $row) {
            $this->warn("  \"{$row->normalized_alias}\" → employee_id={$row->employee_id} ({$row->full_name})");
        }
        $this->newLine();

        // ── Alias muy cortos (≤6 caracteres totales, sin espacios) ─────────
        $tooShort = $aliases->filter(fn ($row) => mb_strlen(str_replace(' ', '', $row->normalized_alias)) <= 6);
        $this->comment('ALIAS MUY CORTOS (≤6 caracteres, ' . $tooShort->count() . '):');
        foreach ($tooShort as $row) {
            $this->line("  \"{$row->normalized_alias}\" → employee_id={$row->employee_id} ({$row->full_name})");
        }
        $this->newLine();

        $this->info('Resumen: ' . $duplicated->count() . ' duplicados | ' . $oneWord->count() . ' de una palabra | ' . $tooShort->count() . ' muy cortos.');
        $this->comment('Ningún alias fue modificado ni eliminado — este comando es solo diagnóstico.');

        return 0;
    }
}

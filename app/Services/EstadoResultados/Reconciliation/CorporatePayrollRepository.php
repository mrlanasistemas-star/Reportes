<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Models\EstadoResultados\EstadoResultadosPayrollUpload;
use App\Services\EstadoResultados\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura del NOI de corporativo vigente del periodo (tablas propias del ER), agregado por
 * colaborador. Costo reconocido = Σ percepciones (mismo criterio que el NOI de sucursales).
 * Antes de aplicar la migración 2026_11_06_0000D1 devuelve vacío (lectura tolerante).
 */
class CorporatePayrollRepository
{
    /**
     * @return array{upload:?EstadoResultadosPayrollUpload, employees:list<array{key:string, name:string, code:?string, percepciones:string, deducciones:string, lines:int}>}
     */
    public function forPeriod(int $periodId): array
    {
        if (! Schema::hasTable('estado_resultados_payroll_uploads')) {
            return ['upload' => null, 'employees' => []];
        }
        $upload = EstadoResultadosPayrollUpload::query()->with('uploader:id,name')->active()
            ->where('period_id', $periodId)->where('kind', EstadoResultadosPayrollUpload::KIND_NOI_CORPORATIVO)
            ->latest('id')->first();
        if (! $upload) {
            return ['upload' => null, 'employees' => []];
        }

        $rows = DB::table('estado_resultados_payroll_lines')->where('upload_id', $upload->id)->orderBy('source_row')
            ->get(['employee_name', 'employee_code', 'name_key', 'concept_type', 'amount']);
        $by = [];
        foreach ($rows as $r) {
            $key = (string) ($r->name_key ?: mb_strtoupper(trim((string) $r->employee_name)));
            $by[$key] ??= ['key' => $key, 'name' => (string) $r->employee_name, 'code' => $r->employee_code, 'percepciones' => Money::zero(), 'deducciones' => Money::zero(), 'lines' => 0];
            $by[$key]['lines']++;
            if ($r->concept_type === 'percepcion') {
                $by[$key]['percepciones'] = $by[$key]['percepciones']->plus(Money::of($r->amount));
            } elseif ($r->concept_type === 'deduccion') {
                $by[$key]['deducciones'] = $by[$key]['deducciones']->plus(Money::of($r->amount));
            }
        }
        ksort($by, SORT_STRING);

        return [
            'upload' => $upload,
            'employees' => array_values(array_map(fn ($e) => ['percepciones' => Money::str2($e['percepciones']), 'deducciones' => Money::str2($e['deducciones'])] + $e, $by)),
        ];
    }
}

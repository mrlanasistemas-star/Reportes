<?php

namespace App\Console\Commands;

use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\EstadoResultados\Reconciliation\CorporatePayrollRepository;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Sources\UploadModuleInspector;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\RadiographySummary;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnóstico de SÓLO LECTURA de las fuentes que ve el Estado de Resultados para un periodo:
 * qué archivos hay en Carga de archivos (y su estado), cuántos renglones llegaron a la base de
 * datos, por qué una fuente se ve como faltante y qué hacer. No escribe nada.
 *
 *   php artisan er:diagnosticar-fuentes 2026-10
 *   php artisan er:diagnosticar-fuentes "Octubre 2026"
 *   php artisan er:diagnosticar-fuentes 24 --json
 */
class DiagnoseEstadoResultadosSourcesCommand extends Command
{
    protected $signature = 'er:diagnosticar-fuentes
        {periodo : id, código, AAAA-MM o nombre del periodo mensual (p. ej. "Octubre 2026")}
        {--json : salida en JSON}';

    protected $description = 'Estado de Resultados: explica qué fuentes ve el ER en un periodo y por qué alguna falta (sólo lectura).';

    public function handle(PeriodDataIds $dataIds, UploadModuleInspector $inspector, ErpExpenseRepository $erp, CorporatePayrollRepository $corp, BranchRadiographyCalculator $branches): int
    {
        $period = $this->resolvePeriod((string) $this->argument('periodo'));
        if (! $period) {
            $this->error('No encontré un periodo mensual para «'.$this->argument('periodo').'». Prueba con el id, el código o AAAA-MM.');

            return self::FAILURE;
        }

        $ids = $dataIds->for($period);
        $diag = $inspector->inspect($period, $ids);
        $twins = Period::query()->where('type', 'monthly')->where('year', $period->year)->where('month', $period->month)->where('id', '!=', $period->id)->get();

        // Gastos corporativos: dónde viven y qué toma el ER.
        $erpRows = $erp->forDataIds($ids);
        $byUniverse = [];
        foreach ($erpRows as $r) {
            $byUniverse[$r['universe']] ??= ['count' => 0, 'amount' => Money::zero()];
            $byUniverse[$r['universe']]['count']++;
            $byUniverse[$r['universe']]['amount'] = $byUniverse[$r['universe']]['amount']->plus(Money::of($r['amount']));
        }
        $corpIds = $branches->buildBranchMap()['corporativo'];
        $corpOther = $ids === [] || $corpIds === [] ? collect() : DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
            ->join('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')
            ->whereIn('e.period_id', $ids)->whereIn('e.branch_id', $corpIds)->where('ds.code', '!=', 'gastos_erp')
            ->groupBy('ds.code')->selectRaw('ds.code, COUNT(*) as c, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as t')->get();

        $bank = EstadoResultadosUpload::query()->where('period_id', $period->id)->orderBy('id')->get(['id', 'account_slot', 'original_name', 'status', 'movements_count', 'control_ok']);
        $payroll = $corp->forPeriod($period->id);

        $report = [
            'periodo' => ['id' => $period->id, 'code' => $period->code, 'label' => $period->label, 'type' => $period->type,
                'start' => $period->start_date?->format('Y-m-d'), 'end' => $period->end_date?->format('Y-m-d'),
                'data_ids' => $ids, 'periodos_mismo_mes' => $twins->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'label' => $p->label])->all()],
            'radiografia_generada' => RadiographySummary::current($period->id) !== null,
            'carga_de_archivos' => $diag,
            'gastos_corporativos' => [
                'erp_por_universo' => array_map(fn ($u) => ['count' => $u['count'], 'amount' => Money::str2($u['amount'])], $byUniverse),
                'otras_fuentes_corporativo' => $corpOther->map(fn ($r) => ['code' => $r->code, 'count' => (int) $r->c, 'amount' => Money::str2($r->t ?? 0)])->all(),
            ],
            'estados_de_cuenta' => $bank->map(fn ($u) => ['id' => $u->id, 'slot' => $u->account_slot, 'name' => $u->original_name, 'status' => $u->status, 'movements' => (int) $u->movements_count, 'control_ok' => $u->control_ok])->all(),
            'noi_corporativo' => $payroll['upload'] ? ['id' => $payroll['upload']->id, 'name' => $payroll['upload']->original_name,
                'employees' => count($payroll['employees']), 'total_percepciones' => (string) $payroll['upload']->total_percepciones] : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Estado de Resultados — fuentes de '.$period->label.' (id '.$period->id.', código '.$period->code.', '.$report['periodo']['start'].' a '.$report['periodo']['end'].')');
        $this->line('Universo de period_id (igual que Radiografía): '.implode(', ', $ids));
        if ($twins->isNotEmpty()) {
            $this->warn('⚠ Hay OTRO periodo mensual del mismo mes: '.$twins->map(fn ($p) => $p->label.' (id '.$p->id.', '.$p->code.')')->implode('; ').'. Si el ERP se subió en ése, el ER de este periodo no lo ve.');
        }
        $this->line('Radiografía generada: '.($report['radiografia_generada'] ? 'sí' : 'NO'));

        foreach ($diag as $d) {
            $this->newLine();
            $this->line('<options=bold>'.$d['label'].'</> (Carga de archivos → «'.$d['module_label'].'», código '.$d['code'].')');
            $this->line('  Estado para el ER: '.mb_strtoupper($d['state']).' ['.$d['reason'].'] · registros en la base de datos: '.$d['fact_rows']);
            $this->line('  Qué pasa: '.$d['message']);
            if ($d['fix']) {
                $this->line('  Qué hacer: '.$d['fix']);
            }
            if ($d['uploads'] !== []) {
                $this->table(['Carga', 'Periodo', 'Archivo', 'Estado', 'Registros', 'Subido'], array_map(fn ($u) => [
                    $u['id'], $u['period_id'], $u['name'], $u['status'].' ('.$u['status_label'].')', $u['rows'], ($u['uploaded_at'] ?? '—').' '.($u['uploaded_by'] ?? ''),
                ], $d['uploads']));
            } else {
                $this->line('  Sin archivos de esta fuente en el periodo.');
            }
            if ($d['run']) {
                $this->line('  Última «Cargar registros» del periodo: '.$d['run']['status'].' ('.($d['run']['at'] ?? '—').')'.($d['run']['error'] ? ' · error: '.$d['run']['error'] : ''));
            }
            foreach ($d['elsewhere'] as $e) {
                $this->warn('  ⚠ Archivo «'.$e['name'].'» subido en '.$e['period_label'].' (id '.$e['period_id'].') cubre semanas de este periodo.');
            }
        }

        $this->newLine();
        $this->line('<options=bold>Gastos corporativos</>');
        $labels = ['radiografia' => 'sucursal operativa (ya en Radiografía)', 'corporativo' => 'CORPORATIVO (el ER lo toma como OPEX/nómina corporativa)', 'sin_sucursal' => 'sin sucursal (el ER lo manda a revisión)', 'no_operativa' => 'sucursal no operativa (el ER lo manda a revisión)'];
        foreach ($report['gastos_corporativos']['erp_por_universo'] as $u => $v) {
            $this->line('  ERP · '.($labels[$u] ?? $u).': '.$v['count'].' renglones, $'.number_format((float) $v['amount'], 2));
        }
        if ($byUniverse === []) {
            $this->warn('  El ERP del periodo no tiene renglones: los gastos corporativos del ER quedan en $0 hasta cargarlo.');
        }
        foreach ($report['gastos_corporativos']['otras_fuentes_corporativo'] as $o) {
            $this->line('  Otra fuente con sucursal CORPORATIVO · '.$o['code'].': '.$o['count'].' renglones, $'.number_format((float) $o['amount'], 2).' (informativo: el ER no la suma; Radiografía tampoco).');
        }

        $this->newLine();
        $this->line('<options=bold>Fuentes propias del ER</>');
        foreach (EstadoResultadosUploadService::SLOTS as $slot => $label) {
            $u = $bank->first(fn ($x) => $x->account_slot === $slot && $x->status !== EstadoResultadosUpload::STATUS_REPLACED);
            $this->line('  '.$label.': '.($u ? '«'.$u->original_name.'» ('.$u->status.', '.$u->movements_count.' mov.)' : 'FALTA — se sube en Estado de Resultados → Fuentes'));
        }
        $this->line('  NOI de corporativo: '.($report['noi_corporativo']
            ? '«'.$report['noi_corporativo']['name'].'» ('.$report['noi_corporativo']['employees'].' colaboradores, percepciones $'.number_format((float) $report['noi_corporativo']['total_percepciones'], 2).')'
            : 'FALTA — se sube en Estado de Resultados → Fuentes (recomendada)'));

        return self::SUCCESS;
    }

    private function resolvePeriod(string $arg): ?Period
    {
        $arg = trim($arg);
        $q = fn () => Period::query()->where('type', 'monthly');
        if (ctype_digit($arg) && ($p = $q()->find((int) $arg))) {
            return $p;
        }
        if ($p = $q()->where('code', $arg)->first()) {
            return $p;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $arg, $m)) {
            return $q()->where('year', (int) $m[1])->where('month', (int) $m[2])->orderBy('id')->first();
        }
        $needle = mb_strtolower($arg);

        return $q()->orderByDesc('start_date')->get()->first(fn ($p) => mb_strtolower((string) $p->label) === $needle || mb_strtolower((string) $p->name) === $needle);
    }
}

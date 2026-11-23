<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Services\Access\DataScopeService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Control\MovementControlService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosAbilities;
use App\Services\EstadoResultados\Reporting\EstadoResultadosReportResolver;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Control total de la pestaña Validación (20-nov-2026): filas de los archivos que aportan al
 * periodo que NO están en su dataset (van a otro periodo, repetidas allá, sin periodo,
 * cerrado/existente, o leídas y no guardadas) y exportación a Excel de la lista filtrada.
 *
 * Lo que sí está en el dataset (movimientos del periodo) lo filtra el front desde
 * dataset.movements (ya redactado). Aquí se respeta la misma redacción (ver_nomina / ver_dg).
 */
class EstadoResultadosControlController extends Controller
{
    /** Filas por página en la vista «fuera» (filas que fueron a otro periodo). */
    private const PER_PAGE = 100;

    /**
     * @param  MovementControlService  $control  Lee las filas fuera del dataset y las no guardadas.
     * @param  EstadoResultadosAbilities  $abilities  Permisos finos (ver_nomina / ver_dg) para redactar.
     * @param  DataScopeService  $dataScope  Alcance por sucursal del usuario.
     * @param  EstadoResultadosReportResolver  $resolver  Devuelve el dataset vigente (en vivo o snapshot aprobado).
     */
    public function __construct(
        private readonly MovementControlService $control,
        private readonly EstadoResultadosAbilities $abilities,
        private readonly DataScopeService $dataScope,
        private readonly EstadoResultadosReportResolver $resolver,
    ) {}

    /**
     * GET /estado-resultados/{period}/control-movimientos?vista=fuera|no_guardado&archivo=&ubicacion=&page=
     *
     * Lista las filas de los archivos del periodo que NO quedaron en su dataset:
     *  - vista=fuera: filas ubicadas en otro periodo / duplicadas / sin periodo / periodo cerrado /
     *    periodo existente / legado (filtro `ubicacion`), paginadas de 100 en 100 y redactadas
     *    según ver_nomina / ver_dg.
     *  - vista=no_guardado: filas leídas del archivo que no se guardaron (sin paginar).
     *
     * @param  Request  $request  Query: vista, archivo (id de carga), ubicacion, page, alcance.
     * @param  Period  $period  Debe ser mensual (422 si no).
     * @return JsonResponse { data, total, page, per_page, last_page, ... }; 403/422 según alcance o parámetros.
     */
    public function movements(Request $request, Period $period): JsonResponse
    {
        abort_unless($period->type === 'monthly', 422, 'El Estado de Resultados se genera sobre periodos mensuales.');
        $this->authorizeScope($request);
        $vista = (string) $request->query('vista', 'fuera');
        abort_unless(in_array($vista, ['fuera', 'no_guardado'], true), 422, 'Vista no válida.');
        $upload = $request->query('archivo');
        $uploadId = $upload !== null && $upload !== '' && ctype_digit((string) $upload) ? (int) $upload : null;

        if ($vista === 'no_guardado') {
            $res = $this->control->notSavedRows($period, $uploadId);

            return response()->json(['data' => $res['data'], 'files' => $res['files'], 'total' => count($res['data']), 'page' => 1, 'per_page' => count($res['data']), 'last_page' => 1]);
        }

        $placement = (string) $request->query('ubicacion', '');
        abort_unless($placement === '' || in_array($placement, ['otro_periodo', 'duplicado', 'sin_periodo', 'periodo_cerrado', 'periodo_existente', 'legado'], true), 422, 'Ubicación no válida.');

        return response()->json($this->control->outsideRows(
            $period, $uploadId, $placement ?: null, max(1, (int) $request->query('page', 1)), self::PER_PAGE,
            $this->abilities->can($request->user(), 'ver_nomina'), $this->abilities->can($request->user(), 'ver_dg'),
        ));
    }

    /**
     * GET /estado-resultados/{period}/control-movimientos/exportar?estado=&archivo=&huellas[]= (xlsx)
     *
     * Exporta a Excel la lista filtrada de la pestaña Validación. Junta: movimientos del dataset
     * del periodo (filtrados por estado de control, archivo y huellas seleccionadas), filas que
     * fueron a otro periodo (paginando de 1000 en 1000) y filas no guardadas. Si se mandan
     * `huellas`, sólo exporta esos movimientos del dataset.
     *
     * Efectos: eleva memory_limit a 1 GB; escribe un .xlsx temporal en storage/app/tmp que se
     * borra tras enviarse; si falla la escritura registra el error en el log con una referencia
     * ER-CTRL-… que se muestra al usuario (500).
     *
     * @param  Request  $request  Query: estado (todos|completado|pendiente|duplicado|fuera|no_guardado|revisar),
     *                            archivo, huellas[], alcance.
     * @param  Period  $period  Debe ser mensual.
     * @return BinaryFileResponse|JsonResponse Descarga del Excel o error JSON.
     */
    public function export(Request $request, Period $period)
    {
        @ini_set('memory_limit', '1024M');
        abort_unless($period->type === 'monthly', 422, 'El Estado de Resultados se genera sobre periodos mensuales.');
        $scope = $this->authorizeScope($request);
        $estado = (string) $request->query('estado', 'todos');
        abort_unless(in_array($estado, ['todos', 'completado', 'pendiente', 'duplicado', 'fuera', 'no_guardado', 'revisar'], true), 422, 'Estado no válido.');
        $upload = $request->query('archivo');
        $uploadId = $upload !== null && $upload !== '' && ctype_digit((string) $upload) ? (int) $upload : null;
        $fps = array_values(array_filter(array_map('strval', (array) $request->query('huellas', []))));

        try {
            $dataset = $this->resolver->resolve($period, $scope, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $canNomina = $this->abilities->can($request->user(), 'ver_nomina');
        $canDg = $this->abilities->can($request->user(), 'ver_dg');

        $rows = [];
        if ($estado !== 'no_guardado') {
            $fpSet = $fps === [] ? null : array_flip($fps);
            foreach ($dataset['movements'] ?? [] as $m) {
                $c = self::controlOf($m);
                if ($fpSet !== null && ! isset($fpSet[$m['fingerprint'] ?? ''])) {
                    continue;
                }
                if ($uploadId !== null && (int) ($m['upload_id'] ?? 0) !== $uploadId) {
                    continue;
                }
                if ($estado === 'revisar' ? $c['flags'] === [] : ($estado !== 'todos' && $c['status'] !== $estado)) {
                    continue;
                }
                $rows[] = [
                    'origen' => 'Este periodo', 'status' => $c['label'], 'date' => $m['date'] ?? null, 'account' => $m['account_label'] ?? $m['account_slot'] ?? null,
                    'file' => $m['file'] ?? ($m['source'] === 'erp' ? 'ERP de gastos' : null), 'row' => $m['source_row'] ?? null, 'concept' => $m['concept'] ?? null,
                    'cargo' => (float) ($m['cargo'] ?? 0), 'abono' => (float) ($m['abono'] ?? 0), 'treatment' => $m['treatment_label'] ?? null,
                    'line' => $m['impact']['line'] ?? null, 'rule' => ($m['override'] ?? null) ? 'Manual: '.($m['override']['user'] ?? '—').' '.($m['override']['at'] ?? '').' · '.($m['override']['notes'] ?? '') : (($m['rule_code'] ?? '').' · '.($m['rule_reason'] ?? '')),
                    'explain' => $c['explain'], 'flags' => implode(' | ', array_column($c['flags'], 'label')),
                    'dup' => isset($m['duplicate_of']) && $m['duplicate_of'] ? ($m['duplicate_of']['file'] ?? '—').' fila '.$m['duplicate_of']['source_row'].' ('.($m['duplicate_of']['period_label'] ?? '—').')' : null,
                ];
            }
        }
        if ($fps === [] && in_array($estado, ['todos', 'fuera', 'duplicado'], true)) {
            $page = 1;
            do {
                $res = $this->control->outsideRows($period, $uploadId, $estado === 'duplicado' ? 'duplicado' : null, $page, 1000, $canNomina, $canDg);
                foreach ($res['data'] as $r) {
                    if ($estado === 'fuera' && $r['status'] !== MovementControlService::FUERA) {
                        continue;
                    }
                    $rows[] = [
                        'origen' => 'Otro periodo: '.($r['period_label'] ?? '—'), 'status' => $r['status_label'], 'date' => $r['date'], 'account' => $r['account_label'] ?? $r['account_slot'],
                        'file' => $r['file'], 'row' => $r['source_row'], 'concept' => $r['concept'], 'cargo' => (float) $r['cargo'], 'abono' => (float) $r['abono'],
                        'treatment' => $r['treatment_label'], 'line' => null, 'rule' => ($r['rule_code'] ?? '').' · '.($r['rule_reason'] ?? ''), 'explain' => $r['explain'], 'flags' => '',
                        'dup' => $r['duplicate_of'] ? ($r['duplicate_of']['file'] ?? '—').' fila '.$r['duplicate_of']['source_row'].' ('.($r['duplicate_of']['period_label'] ?? '—').')' : null,
                    ];
                }
                $page++;
            } while ($page <= $res['last_page']);
        }
        if ($fps === [] && in_array($estado, ['todos', 'no_guardado'], true)) {
            foreach ($this->control->notSavedRows($period, $uploadId)['data'] as $r) {
                $rows[] = ['origen' => 'No guardado', 'status' => $r['status_label'], 'date' => $r['date'], 'account' => $r['account_slot'], 'file' => $r['file'], 'row' => $r['source_row'],
                    'concept' => $r['concept'], 'cargo' => (float) $r['cargo'], 'abono' => (float) $r['abono'], 'treatment' => null, 'line' => null, 'rule' => null, 'explain' => $r['explain'], 'flags' => '', 'dup' => null];
            }
        }

        $name = 'MR_LANA_Control_movimientos_'.$period->code.'_'.$estado.'.xlsx';
        $path = storage_path('app/tmp/er-control-'.Str::uuid().'.xlsx');
        @mkdir(dirname($path), 0775, true);
        try {
            $this->writeXlsx($rows, $path, (string) ($dataset['meta']['period']['label'] ?? $period->code), $estado);
        } catch (\Throwable $e) {
            @unlink($path);
            $ref = 'ER-CTRL-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4));
            Log::error('estado_resultados.control_excel_failed', ['ref' => $ref, 'period_id' => $period->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo generar el Excel. Referencia: '.$ref], 500);
        }

        return response()->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * Estado de control de un movimiento del dataset (snapshots anteriores no traen `control`).
     *
     * Si el movimiento ya trae `control` lo usa; si no, lo deduce: duplicado (placement) →
     * fuera (no in_period) → pendiente (tratamiento PENDIENTE) → completado.
     *
     * @param  array  $m  Movimiento tal como viene en dataset.movements.
     * @return array{status:string,label:string,explain:string,flags:array}
     */
    public static function controlOf(array $m): array
    {
        if (isset($m['control']['status'])) {
            return $m['control'] + ['flags' => []];
        }
        $status = match (true) {
            ($m['placement'] ?? null) === 'duplicado' => MovementControlService::DUPLICADO,
            ! ($m['in_period'] ?? true) => MovementControlService::FUERA,
            ($m['treatment'] ?? null) === T::PENDIENTE => MovementControlService::PENDIENTE,
            default => MovementControlService::COMPLETADO,
        };

        return ['status' => $status, 'label' => MovementControlService::STATUS_LABELS[$status], 'explain' => (string) ($m['rule_reason'] ?? ''), 'flags' => []];
    }

    /**
     * Escribe el Excel de control: título en A1, encabezados en la fila 3, datos desde la fila 4,
     * importes con formato #,##0.00, autofiltro y paneles congelados. Todo texto se escribe como
     * TEXTO explícito para que nunca se ejecute una fórmula que venga en el estado de cuenta.
     *
     * @param  array  $rows  Filas ya armadas por export().
     * @param  string  $path  Ruta del archivo a generar.
     * @param  string  $periodLabel  Nombre del periodo para el título.
     * @param  string  $estado  Filtro aplicado (para el título).
     */
    private function writeXlsx(array $rows, string $path, string $periodLabel, string $estado): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Control');
        $sheet->setCellValue('A1', 'Control de movimientos · '.$periodLabel.' · '.($estado === 'todos' ? 'todos los estados' : $estado));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $headers = ['Origen', 'Estado', 'Fecha', 'Cuenta', 'Archivo', 'Fila Excel', 'Concepto', 'Cargo', 'Abono', 'Tratamiento', 'Línea del ER', 'Regla / clasificación', 'Explicación', 'Revisar concepto', 'Copia que cuenta'];
        foreach ($headers as $c => $h) {
            $sheet->setCellValue([$c + 1, 3], $h);
        }
        $sheet->getStyle('A3:O3')->getFont()->setBold(true);
        $r = 4;
        foreach ($rows as $row) {
            $vals = [$row['origen'], $row['status'], $row['date'], $row['account'], $row['file'], $row['row'], $row['concept'], $row['cargo'], $row['abono'], $row['treatment'], $row['line'], $row['rule'], $row['explain'], $row['flags'], $row['dup']];
            foreach ($vals as $c => $v) {
                if (is_float($v) || is_int($v)) {
                    $sheet->setCellValue([$c + 1, $r], $v);
                } else {
                    // Siempre como TEXTO: nunca se interpreta una fórmula que venga del banco.
                    $sheet->setCellValueExplicit([$c + 1, $r], (string) ($v ?? ''), DataType::TYPE_STRING);
                }
            }
            $r++;
        }
        $sheet->getStyle('H4:I'.max(4, $r))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->setAutoFilter('A3:O'.max(3, $r - 1));
        $sheet->freezePane('A4');
        (new Xlsx($book))->save($path);
    }

    /**
     * Mismo alcance por sucursal que el dataset (DataScopeService).
     *
     * CONSOLIDADO (por defecto) exige alcance general; una sucursal exige que el usuario la tenga
     * permitida.
     *
     * @return string Alcance validado (máx. 80 caracteres).
     *
     * @throws HttpException 403 sin sesión o sin acceso.
     */
    private function authorizeScope(Request $request): string
    {
        $user = $request->user();
        abort_if($user === null, 403, 'Inicia sesión para consultar el Estado de Resultados.');
        $scope = trim((string) $request->query('alcance', EstadoResultadosDatasetBuilder::CONSOLIDADO)) ?: EstadoResultadosDatasetBuilder::CONSOLIDADO;
        if ($scope === EstadoResultadosDatasetBuilder::CONSOLIDADO) {
            $this->dataScope->requireGeneral($user, 'El control de movimientos del Estado de Resultados');
        } elseif (! $this->dataScope->allowsBranchName($user, $scope)) {
            abort(403, 'No tienes acceso al Estado de Resultados de la sucursal '.$scope.'.');
        }

        return mb_substr($scope, 0, 80);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Enums\ReportUploadStatus;
use App\Jobs\ImportPortfolioSnapshotJob;
use App\Models\DataSource;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\ReportUpload;
use App\Services\Efectividad\V4\EfectividadV4DataAdapter;
use App\Services\Efectividad\V4\EfectividadV4Exception;
use App\Services\Imports\LendusCarteraFotoImportService;
use App\Support\ReportUploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fuentes de la Efectividad V4: disponibilidad (fotografías por fecha + Ingresos)
 * y carga de fotografías diarias de cartera.
 *
 * Las fotografías usan la MISMA infraestructura de carga de Reportes
 * (`report_uploads`, disco `public`, ReportAnalysisService, `process_runs`) con
 * la fuente `lendus_saldos_cliente_foto` y su fecha en `snapshot_date`. Se cargan
 * desde este módulo porque cada archivo necesita una FECHA (no un mes), y el
 * flujo mensual de Histórico General admite un solo archivo vigente por fuente.
 *
 * Nota (23-nov-2026): este controlador no está referenciado en routes/*.php.
 */
class EfectividadCobranzaFuentesController extends Controller
{
    /**
     * Disponibilidad de fuentes para calcular la Efectividad V4 de un periodo.
     *
     * Sólo lectura. Si el adaptador indica que el periodo/modo no es soportable, responde 200 con
     * `supported: false`, el código y el mensaje (no es un error HTTP).
     *
     * @param  Request  $request  `period_id` (obligatorio), `branch_id`, `mode` (SEMANAL | DIARIO, por
     *                            defecto SEMANAL) y `cutoff_date` opcionales.
     * @param  EfectividadV4DataAdapter  $adapter  Revisa fotografías por fecha e ingresos disponibles.
     * @return JsonResponse { supported, engine_version, ...disponibilidad }.
     */
    public function availability(Request $request, EfectividadV4DataAdapter $adapter): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'mode' => ['nullable', 'string', 'in:SEMANAL,DIARIO'],
            'cutoff_date' => ['nullable', 'date'],
        ]);
        $period = Period::query()->findOrFail($data['period_id']);

        try {
            $disp = $adapter->availability($period, $data['branch_id'] ?? null, $data['mode'] ?? EfectividadCobranzaRun::MODE_SEMANAL, $data['cutoff_date'] ?? null);
        } catch (EfectividadV4Exception $e) {
            return response()->json(['supported' => false, 'code' => $e->codigo, 'message' => $e->getMessage()]);
        }

        return response()->json(['supported' => true, 'engine_version' => EfectividadCobranzaRun::currentEngineVersion()] + $disp);
    }

    /**
     * Lista las fotografías de cartera cargadas con fecha entre `from` y `to` (máx. 200, más recientes primero).
     *
     * Por cada carga devuelve su estado, quién y cuándo la subió, el estado de la fotografía
     * procesada (filas y oficinas) y el último log de proceso en versión pública (máx. 400 caracteres).
     *
     * @param  Request  $request  `from` y `to` (fechas; to ≥ from).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        $uploads = ReportUpload::snapshots()
            ->with('user:id,name')
            ->whereBetween('snapshot_date', [$data['from'], $data['to'].' 23:59:59'])
            ->latest('id')
            ->limit(200)
            ->get();
        $snapshots = PortfolioSnapshot::query()->whereIn('report_upload_id', $uploads->pluck('id'))->get()->keyBy('report_upload_id');
        $logs = DB::table('process_runs')->whereIn('report_upload_id', $uploads->pluck('id'))
            ->orderByDesc('id')->get(['report_upload_id', 'log', 'status'])->unique('report_upload_id')->keyBy('report_upload_id');

        return response()->json(['uploads' => $uploads->map(fn (ReportUpload $u) => [
            'id' => $u->id,
            'snapshot_date' => $u->snapshot_date->format('Y-m-d'),
            'original_name' => $u->original_name,
            'status' => $u->status instanceof ReportUploadStatus ? $u->status->value : $u->status,
            'uploaded_at' => $u->uploaded_at?->toISOString(),
            'uploaded_by' => $u->user?->name,
            'snapshot_status' => $snapshots[$u->id]->status ?? null,
            'rows' => $snapshots[$u->id]->rows_count ?? null,
            'offices' => $snapshots[$u->id]->offices ?? [],
            'log' => isset($logs[$u->id]) ? mb_strimwidth((string) LendusCarteraFotoImportService::mensajePublico($logs[$u->id]->log), 0, 400, '…') : null,
        ])->values()]);
    }

    /**
     * Recibe una fotografía diaria de cartera (Excel de Saldos por Cliente de Lendus) para una fecha.
     *
     * Reglas: la fecha no puede ser futura; debe existir una semana operativa (periodo weekly) que
     * la contenga o, si es el «domingo base», la semana que empieza al día siguiente.
     * Efectos: guarda el archivo en el disco de reportes, crea el ReportUpload (estado Pending,
     * fuente lendus_saldos_cliente_foto, `snapshot_date`) y despacha ImportPortfolioSnapshotJob
     * para procesarlo en segundo plano.
     *
     * @param  Request  $request  `snapshot_date`, `file` (xls/xlsx/xlsm, máx. 128 MB) y `notes` opcional.
     * @return JsonResponse { upload_id, snapshot_date, message }; 422 por fecha/semana; 500 si falta la fuente.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'snapshot_date' => ['required', 'date'],
            'file' => ['required', 'file', 'mimes:xls,xlsx,xlsm', 'max:131072'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'snapshot_date.required' => 'Indica la fecha de la fotografía de cartera.',
            'file.mimes' => 'La fotografía debe ser el Excel de Saldos por Cliente (XLS/XLSX/XLSM).',
        ]);
        $fecha = date('Y-m-d', strtotime($data['snapshot_date']));
        if ($fecha > now()->format('Y-m-d')) {
            return response()->json(['message' => 'La fecha de la fotografía no puede ser futura.'], 422);
        }

        // Periodo ancla: la semana operativa que contiene la fecha; un domingo que solo
        // existe como "domingo base" se ancla a la semana siguiente.
        $period = Period::query()->where('type', 'weekly')
            ->whereDate('start_date', '<=', $fecha)->whereDate('end_date', '>=', $fecha)->first()
            ?? Period::query()->where('type', 'weekly')->whereDate('start_date', date('Y-m-d', strtotime($fecha.' +1 day')))->first();
        if (! $period) {
            return response()->json(['message' => 'No existe una semana operativa que contenga el '.date('d/m/Y', strtotime($fecha)).'. Créala en Periodos antes de cargar la fotografía.'], 422);
        }

        $source = DataSource::query()->where('code', DataSourceCode::LendusSaldosClienteFoto->value)->first();
        if (! $source) {
            return response()->json(['message' => 'La fuente de fotografías de cartera no está configurada (ejecuta las migraciones).'], 500);
        }

        $file = $request->file('file');
        $upload = ReportUpload::query()->create([
            'period_id' => $period->id,
            'covered_period_ids' => [$period->id],
            'data_source_id' => $source->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => ReportUploadStorage::store($file, 'reports'),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
            'uploaded_at' => now(),
            'status' => ReportUploadStatus::Pending,
            'notes' => $data['notes'] ?? null,
            'snapshot_date' => $fecha,
        ]);

        ImportPortfolioSnapshotJob::dispatch($upload->id);

        return response()->json([
            'upload_id' => $upload->id,
            'snapshot_date' => $fecha,
            'message' => 'Fotografía del '.date('d/m/Y', strtotime($fecha)).' recibida. Se está procesando en segundo plano.',
        ]);
    }

    /**
     * Vuelve a procesar una fotografía de cartera ya cargada.
     *
     * Efectos: pone la carga en Pending (escribe en BD) y despacha ImportPortfolioSnapshotJob.
     *
     * @param  int  $upload  Id del ReportUpload (sólo fotografías; 404 si no).
     * @return JsonResponse Mensaje; 409 si ya está pendiente o en proceso.
     */
    public function reprocess(int $upload): JsonResponse
    {
        $model = ReportUpload::snapshots()->findOrFail($upload);
        if (in_array($model->status, [ReportUploadStatus::Pending, ReportUploadStatus::Processing], true)) {
            return response()->json(['message' => 'La fotografía ya está en proceso.'], 409);
        }
        $model->update(['status' => ReportUploadStatus::Pending]);
        ImportPortfolioSnapshotJob::dispatch($model->id);

        return response()->json(['message' => 'Fotografía enviada a reprocesar.']);
    }
}

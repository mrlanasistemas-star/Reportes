<?php

namespace App\Jobs;

use App\Mail\ReprocessUploadCompletedMail;
use App\Mail\ReprocessUploadFailedMail;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\ReportAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job en cola que REPROCESA un solo archivo ya subido (botón "Reprocesar" en
 * Carga de archivos, ReportUploadController).
 *
 * Flujo: borra los registros derivados previos de ese upload
 * (PeriodDerivedDataCleaner::clearForUpload), vuelve a importarlo con
 * ReportAnalysisService y envía correo de éxito o de error al usuario que lo
 * pidió. Un solo intento, timeout de 30 min.
 */
class ReprocessReportUploadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $maxExceptions = 1;

    /**
     * @param  int  $uploadId  Id del upload a reprocesar.
     * @param  int|null  $userId  Usuario que solicitó el reproceso (destinatario del correo).
     */
    public function __construct(
        public int $uploadId,
        public ?int $userId = null,
    ) {}

    /**
     * Efectos: elimina e inserta filas de hechos del upload, crea un ProcessRun y
     * encola ReprocessUploadCompletedMail / ReprocessUploadFailedMail.
     *
     * Lanza ModelNotFoundException (findOrFail) si el upload o su periodo no existen.
     *
     * @param  ReportAnalysisService  $service  Servicio de importación.
     * @param  PeriodDerivedDataCleaner  $cleaner  Limpia los registros previos del upload.
     *
     * @throws \Throwable Relanza el error de importación después de registrar y avisar por correo.
     */
    public function handle(ReportAnalysisService $service, PeriodDerivedDataCleaner $cleaner): void
    {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        $upload = ReportUpload::query()->with('dataSource')->findOrFail($this->uploadId);
        $period = Period::query()->findOrFail($upload->period_id);
        $user = $this->userId ? User::query()->find($this->userId) : null;

        try {
            // Borra los registros previos de este upload antes de insertar los nuevos
            $cleaner->clearForUpload($upload);

            $processRun = $service->analyze($upload);

            $stats = [
                'rows_read' => $processRun->rows_read ?? 0,
                'rows_inserted' => $processRun->rows_inserted ?? 0,
                'rows_skipped' => $processRun->rows_skipped ?? 0,
                'rows_errors' => $processRun->rows_with_errors ?? 0,
                'log' => $processRun->log ?? '',
            ];

            if ($user?->email) {
                Mail::to($user->email)->queue(new ReprocessUploadCompletedMail($period, $user, $upload, $stats));
            }
        } catch (\Throwable $e) {
            Log::error('ReprocessReportUploadJob failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
            ]);

            if ($user?->email) {
                Mail::to($user->email)->queue(new ReprocessUploadFailedMail($period, $user, $upload, $e->getMessage()));
            }

            throw $e;
        }
    }
}

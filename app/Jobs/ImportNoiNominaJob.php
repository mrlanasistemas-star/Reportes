<?php

namespace App\Jobs;

use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job en cola que importa un archivo de nómina NOI ya subido, delegando en
 * ReportAnalysisService::analyze() (que elige el importador según la fuente,
 * registra el ProcessRun y actualiza el estado del upload).
 *
 * Nota: actualmente no se despacha desde ningún punto de app/ ni routes/
 * (posible código heredado); la carga normal usa UpdatePeriodDatabaseJob.
 */
class ImportNoiNominaJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $reportUploadId  Id del registro report_uploads a importar.
     */
    public function __construct(
        public int $reportUploadId
    ) {}

    /**
     * Busca el upload y lo analiza/importa. Si el upload ya no existe, termina sin error.
     * Efectos: escribe en las tablas de hechos de nómina y en process_runs (vía ReportAnalysisService).
     *
     * @param  ReportAnalysisService  $service  Servicio que ejecuta el importador correspondiente.
     */
    public function handle(ReportAnalysisService $service): void
    {
        $upload = ReportUpload::query()->find($this->reportUploadId);
        if (! $upload) {
            return;
        }
        $service->analyze($upload);
    }
}

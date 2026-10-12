<?php

namespace App\Jobs;

use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa en cola una fotografía diaria de cartera (Efectividad V4) con el mismo
 * pipeline de importación de Reportes (ReportAnalysisService → ProcessRun →
 * estado del upload). No envía correo por cada fotografía (son 7-8 por semana):
 * el estado queda visible en «Disponibilidad de información».
 */
class ImportPortfolioSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $uploadId) {}

    public function handle(ReportAnalysisService $service): void
    {
        $upload = ReportUpload::snapshots()->with('dataSource')->find($this->uploadId);
        if (! $upload) {
            return;
        }

        try {
            $service->analyze($upload);
        } catch (\Throwable $e) {
            // analyze() ya dejó el upload en `failed` y el motivo en process_runs.log.
            Log::warning('Efectividad V4: la fotografía de cartera no se pudo importar.', ['upload_id' => $upload->id, 'error' => $e->getMessage()]);
        }
    }
}

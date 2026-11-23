<?php

namespace App\Services;

use App\Enums\ReportUploadStatus;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Support\ReportUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * Registra un archivo fuente (Lendus, ERP, NOI, etc.) subido para un periodo.
 *
 * Lo usa el controlador de cargas (ReportUploadController) en el paso de subir
 * archivos. Si ya existía una carga de la misma fuente para el periodo, la reemplaza:
 * limpia sus datos derivados y obliga a volver a ejecutar "Cargar registros".
 */
class ReportUploadService
{
    /**
     * @param  PeriodRadiographyService  $periodRadiographyService  Para invalidar el resumen del periodo al reemplazar archivos.
     * @param  PeriodDerivedDataCleaner  $cleaner  Para borrar los registros fact_* de las cargas reemplazadas.
     */
    public function __construct(
        protected PeriodRadiographyService $periodRadiographyService,
        protected PeriodDerivedDataCleaner $cleaner,
    ) {}

    /**
     * Guarda el archivo en el disco privado y crea su ReportUpload en estado Pending.
     *
     * Si había cargas previas de la misma fuente en el periodo: invalida el resumen del
     * periodo (Etapa 2 vuelve a "pendiente"), borra los datos derivados de cada carga
     * anterior y las marca como Replaced para que nunca se tomen como vigentes.
     *
     * @param  int  $periodId  Periodo principal al que pertenece la carga.
     * @param  array  $coveredPeriodIds  Periodos que cubre el archivo (se normalizan a enteros únicos).
     * @param  int  $dataSourceId  Fuente de datos (data_sources.id).
     * @param  UploadedFile  $file  Archivo subido por el usuario.
     * @param  string|null  $notes  Notas libres del usuario.
     * @return ReportUpload La carga recién creada.
     */
    public function store(
        int $periodId,
        array $coveredPeriodIds,
        int $dataSourceId,
        UploadedFile $file,
        ?string $notes = null,
    ): ReportUpload {
        // Disco privado: los originales nunca quedan accesibles por URL.
        $storedPath = ReportUploadStorage::store($file, 'reports');

        // Capture existing uploads BEFORE creating the new one so we can clean them up.
        $existingUploads = ReportUpload::query()
            ->where('period_id', $periodId)
            ->where('data_source_id', $dataSourceId)
            ->get();

        $upload = ReportUpload::create([
            'period_id' => $periodId,
            'covered_period_ids' => array_values(array_unique(array_map('intval', $coveredPeriodIds))),
            'data_source_id' => $dataSourceId,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => Auth::id(),
            'uploaded_at' => now(),
            'status' => ReportUploadStatus::Pending,
            'notes' => $notes,
        ]);

        if ($existingUploads->isNotEmpty()) {
            $period = Period::query()->find($periodId);
            if ($period) {
                // Invalidate the period summary — this makes Etapa 2 show as "pendiente de volver a cargar"
                // and prevents a stale 'database_updated' summary from unlocking generation with no data.
                $this->periodRadiographyService->invalidateForPeriod(
                    $period,
                    Auth::id(),
                    "Se reemplazó el archivo '{$file->getClientOriginalName()}' después de la última carga. Vuelve a ejecutar Cargar registros."
                );

                // Clear fact data for every old upload being replaced, and mark it as
                // Replaced so it's never picked as the "vigente" upload nor reprocessed.
                foreach ($existingUploads as $old) {
                    $this->cleaner->clearForUpload($old);
                    $old->update(['status' => ReportUploadStatus::Replaced]);
                }
            }
        }

        return $upload;
    }
}

<?php

namespace App\Notifications\Reporteria;

use App\Models\UserNotificationPreference;

/**
 * Terminó la GENERACIÓN de la Radiografía (GenerateRadiographyJob) — éxito o
 * error. El correo detallado lo sigue enviando el Job (ReportGeneratedMail /
 * ReportGenerationFailedMail); esta notificación cubre campana y push.
 */
class RadiographyGenerationFinished extends ReporteriaProcessNotification
{
    /**
     * @param  int|null  $runId  Id de la ejecución de generación de Radiografía.
     * @param  string  $periodName  Nombre visible del periodo.
     * @param  bool  $success  true si el reporte se generó.
     * @param  string  $url  Ruta relativa al reporte o al detalle del error.
     * @param  string|null  $scopeLabel  Alcance del reporte (sucursal/gerencia) o null si es general.
     */
    public function __construct(
        public readonly ?int $runId,
        public readonly string $periodName,
        public readonly bool $success,
        public readonly string $url,
        public readonly ?string $scopeLabel = null,
    ) {}

    /**
     * Tipo de preferencia de notificación que controla este aviso.
     *
     * @return string Constante UserNotificationPreference::TYPE_*.
     */
    public function preferenceType(): string
    {
        return UserNotificationPreference::TYPE_REPORTE;
    }

    /**
     * Tipo visual del aviso en la campana.
     *
     * @return string success|failed|blocked|cancelled|info.
     */
    protected function kind(): string
    {
        return $this->success ? 'success' : 'failed';
    }

    /**
     * Título corto en español del aviso.
     *
     * @return string Título.
     */
    protected function title(): string
    {
        return $this->success
            ? 'Reporte listo para consultar y descargar.'
            : 'No se pudo generar el reporte.';
    }

    /**
     * Texto descriptivo del aviso.
     *
     * @return string Cuerpo del mensaje.
     */
    protected function body(): string
    {
        return $this->success
            ? "La Radiografía del periodo {$this->periodName} ya está lista."
            : "Periodo {$this->periodName}: revisa el detalle de la fuente con error.";
    }

    /**
     * Ruta relativa a la que lleva el aviso (campana y clic en el push).
     *
     * @return string URL relativa.
     */
    protected function relativeUrl(): string
    {
        return $this->url;
    }

    /**
     * Nombre del periodo que se muestra en el aviso.
     *
     * @return string|null Nombre del periodo.
     */
    protected function periodLabel(): ?string
    {
        return $this->periodName;
    }

    /**
     * Etiqueta de alcance (sucursal/gerencia) del reporte.
     *
     * @return string|null Etiqueta de alcance o null si es general.
     */
    protected function branchLabel(): ?string
    {
        return $this->scopeLabel;
    }

    /**
     * Identificador de la ejecución relacionada (agrupa los push del mismo proceso).
     *
     * @return int|null Id de la ejecución.
     */
    protected function runId(): ?int
    {
        return $this->runId;
    }
}

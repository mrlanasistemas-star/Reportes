<?php

namespace App\Notifications\Reporteria;

use App\Models\UserNotificationPreference;

/**
 * Terminó la LECTURA de archivos del periodo ("Cargar registros",
 * UpdatePeriodDatabaseJob) — con éxito o con error. El correo detallado lo
 * sigue enviando el Job (DatabaseUpdateCompletedMail/FailedMail); esta
 * notificación cubre la campana y el push del teléfono/navegador.
 */
class PeriodDatabaseUpdateFinished extends ReporteriaProcessNotification
{
    /**
     * @param  list<string>  $failedSources  fuentes que no se pudieron importar (éxito parcial)
     * @param  int  $runId  Id de la ejecución de lectura de archivos.
     * @param  int  $periodId  Id del periodo.
     * @param  string  $periodName  Nombre visible del periodo.
     * @param  bool  $success  true si la lectura terminó (aunque sea con fuentes pendientes).
     */
    public function __construct(
        public readonly int $runId,
        public readonly int $periodId,
        public readonly string $periodName,
        public readonly bool $success,
        public readonly array $failedSources = [],
    ) {}

    /**
     * Tipo de preferencia de notificación que controla este aviso.
     *
     * @return string Constante UserNotificationPreference::TYPE_*.
     */
    public function preferenceType(): string
    {
        return UserNotificationPreference::TYPE_CARGA;
    }

    /**
     * Tipo visual del aviso en la campana.
     *
     * @return string success|failed|blocked|cancelled|info.
     */
    protected function kind(): string
    {
        if (! $this->success) {
            return 'failed';
        }

        return $this->failedSources === [] ? 'success' : 'blocked';
    }

    /**
     * Título corto en español del aviso.
     *
     * @return string Título.
     */
    protected function title(): string
    {
        if (! $this->success) {
            return 'No se pudo terminar la lectura de archivos.';
        }

        return $this->failedSources === []
            ? 'La lectura de archivos terminó correctamente.'
            : 'La lectura de archivos terminó con fuentes pendientes.';
    }

    /**
     * Texto descriptivo del aviso.
     *
     * @return string Cuerpo del mensaje.
     */
    protected function body(): string
    {
        if (! $this->success) {
            return "Periodo {$this->periodName}: revisa el detalle del error en Carga de archivos.";
        }

        return $this->failedSources === []
            ? "Periodo {$this->periodName}: los registros ya están en la base de datos."
            : "Periodo {$this->periodName}: revisa las fuentes con error (".implode(', ', $this->failedSources).').';
    }

    /**
     * Ruta relativa a la que lleva el aviso (campana y clic en el push).
     *
     * @return string URL relativa.
     */
    protected function relativeUrl(): string
    {
        return route('historico-general.index', ['period_id' => $this->periodId], false);
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
     * Identificador de la ejecución relacionada (agrupa los push del mismo proceso).
     *
     * @return int|null Id de la ejecución.
     */
    protected function runId(): ?int
    {
        return $this->runId;
    }
}

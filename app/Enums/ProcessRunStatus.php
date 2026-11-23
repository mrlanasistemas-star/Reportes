<?php

namespace App\Enums;

/**
 * Estado de una corrida de proceso (`process_runs.status`).
 *
 * Lo usan ProcessRun y ReportAnalysisService para registrar el avance de
 * importaciones, cruces, consolidaciones y exportaciones:
 * pendiente → en ejecución → éxito / fallido.
 */
enum ProcessRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

}

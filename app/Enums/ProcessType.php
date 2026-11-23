<?php

namespace App\Enums;

/**
 * Tipo de proceso registrado en `process_runs.process_type`.
 *
 * import = importación de archivo, match = cruce de identidades/sucursales,
 * consolidate = consolidación de cifras, export = generación de archivos.
 * Lo usan ProcessRun y ReportAnalysisService.
 */
enum ProcessType: string
{
    case Import = 'import';
    case Match = 'match';
    case Consolidate = 'consolidate';
    case Export = 'export';

}

<?php

namespace App\Enums;

/**
 * Estado de un archivo subido (`report_uploads.status`).
 *
 * pending = subido, aún sin procesar; processing = el importador está corriendo;
 * processed = importado correctamente; failed = el importador falló;
 * replaced = sustituido por un archivo más reciente de la misma fuente y periodo.
 * Lo consultan DatabaseUpdateService, los controladores de carga y los
 * servicios de Efectividad para elegir qué archivo vigente usar.
 */
enum ReportUploadStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Replaced = 'replaced';

}

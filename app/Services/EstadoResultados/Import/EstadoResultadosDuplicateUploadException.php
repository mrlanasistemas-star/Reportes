<?php

namespace App\Services\EstadoResultados\Import;

use App\Models\EstadoResultados\EstadoResultadosUpload;

/** El MISMO archivo (sha256) ya está activo en el periodo: no se duplica nada (reintento idempotente). */
class EstadoResultadosDuplicateUploadException extends EstadoResultadosImportException
{
    public function __construct(string $message, public readonly EstadoResultadosUpload $existing)
    {
        parent::__construct($message);
    }
}

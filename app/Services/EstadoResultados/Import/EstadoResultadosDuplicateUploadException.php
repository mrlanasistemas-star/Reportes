<?php

namespace App\Services\EstadoResultados\Import;

use App\Models\EstadoResultados\EstadoResultadosUpload;

/** El MISMO archivo (sha256) ya está activo en el periodo: no se duplica nada (reintento idempotente). */
class EstadoResultadosDuplicateUploadException extends EstadoResultadosImportException
{
    /**
     * @param  string  $message  mensaje en español
     * @param  EstadoResultadosUpload  $existing  carga vigente que ya contiene ese archivo
     */
    public function __construct(string $message, public readonly EstadoResultadosUpload $existing)
    {
        parent::__construct($message);
    }
}

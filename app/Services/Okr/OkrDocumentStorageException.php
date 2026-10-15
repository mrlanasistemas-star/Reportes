<?php

namespace App\Services\Okr;

use App\Services\Pdf\PdfRenderException;

/**
 * Fallo al escribir o validar el archivo PDF de un documento OKR (4.9). Es un
 * fallo TÉCNICO (no de negocio): hereda de PdfRenderException para que los
 * controladores respondan con referencia para Sistemas y "no se emitió ningún
 * folio", igual que un fallo del motor.
 */
class OkrDocumentStorageException extends PdfRenderException
{
    public const STORAGE_FAILED = 'PDF_STORAGE_FAILED';

    public function __construct(string $message)
    {
        parent::__construct(self::STORAGE_FAILED, $message);
    }
}

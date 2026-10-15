<?php

namespace App\Services\Okr;

use RuntimeException;

/**
 * La Carta Compromiso definitiva no puede emitirse porque falta información
 * indispensable (jefe inmediato confirmado, puesto, KR…). Lleva los campos
 * faltantes para que la UI ofrezca "Completar información" en vez de un
 * error genérico. Extiende RuntimeException: los catch existentes siguen
 * respondiendo 422 con el mensaje.
 */
class OkrCommitmentLetterIncompleteException extends RuntimeException
{
    /** @param array<string, string> $missing */
    public function __construct(string $message, public readonly array $missing = [])
    {
        parent::__construct($message);
    }
}

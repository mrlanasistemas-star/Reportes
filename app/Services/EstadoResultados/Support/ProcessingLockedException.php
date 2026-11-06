<?php

namespace App\Services\EstadoResultados\Support;

/** Otro procesamiento del mismo periodo está en curso: no se ejecuta un segundo en paralelo. */
class ProcessingLockedException extends \RuntimeException
{
    public function __construct(public readonly int $periodId)
    {
        parent::__construct('Ya hay un procesamiento de conciliación en curso para este periodo. Espera a que termine e inténtalo de nuevo.');
    }
}

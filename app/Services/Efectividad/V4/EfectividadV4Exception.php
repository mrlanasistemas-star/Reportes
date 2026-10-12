<?php

namespace App\Services\Efectividad\V4;

use RuntimeException;

/**
 * Equivalente de los `Err.Raise vbObjectError + 9xx` de la macro V4: la macro
 * se detiene SIN generar reporte. Aquí se traduce a un bloqueo por datos
 * (status `blocked`), nunca a un 0% silencioso.
 */
class EfectividadV4Exception extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $message,
        public readonly array $contexto = [],
    ) {
        parent::__construct($message);
    }
}

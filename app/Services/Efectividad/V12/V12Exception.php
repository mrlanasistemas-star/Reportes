<?php

namespace App\Services\Efectividad\V12;

use RuntimeException;

/** Error de cálculo V12.9.4 con código estable (EC-V12-xxx) y mensaje en español. */
class V12Exception extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $contexto = [])
    {
        parent::__construct($mensaje);
    }
}

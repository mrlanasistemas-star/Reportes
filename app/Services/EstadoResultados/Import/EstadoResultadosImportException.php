<?php

namespace App\Services\EstadoResultados\Import;

/**
 * Error de importación con mensajes entendibles para el usuario. Se lanza ANTES
 * de escribir cualquier fila: una importación inválida nunca deja datos parciales.
 */
class EstadoResultadosImportException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function details(): array
    {
        return $this->details;
    }
}

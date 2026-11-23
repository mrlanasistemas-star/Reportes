<?php

namespace App\Services\EstadoResultados\Reporting;

use Illuminate\Http\JsonResponse;

/**
 * Error de gobierno del reporte (bloqueo, aprobación rechazada, conflicto) con
 * su código HTTP y un mensaje en español. Laravel lo convierte en JSON vía render().
 */
class EstadoResultadosGovernanceException extends \RuntimeException
{
    /**
     * @param  string  $message  mensaje en español para el usuario
     * @param  int  $status  código HTTP (422 por defecto; 409 conflicto, 423 bloqueado…)
     * @param  array<string,mixed>  $payload  datos extra que se agregan a la respuesta JSON
     */
    public function __construct(string $message, private readonly int $status = 422, private readonly array $payload = [])
    {
        parent::__construct($message);
    }

    /**
     * Código HTTP de la respuesta.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Datos extra de la respuesta.
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Respuesta JSON {message, ...payload} con el código HTTP del error (Laravel la invoca).
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()] + $this->payload, $this->status);
    }
}

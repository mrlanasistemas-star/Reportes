<?php

namespace App\Services\EstadoResultados\Reporting;

use Illuminate\Http\JsonResponse;

/**
 * Error de gobierno del reporte (bloqueo, aprobación rechazada, conflicto) con
 * su código HTTP y un mensaje en español. Laravel lo convierte en JSON vía render().
 */
class EstadoResultadosGovernanceException extends \RuntimeException
{
    /** @param array<string,mixed> $payload */
    public function __construct(string $message, private readonly int $status = 422, private readonly array $payload = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()] + $this->payload, $this->status);
    }
}

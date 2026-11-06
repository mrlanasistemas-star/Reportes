<?php

namespace App\Services\EstadoResultados\Import;

/**
 * Error de importación con mensajes entendibles para el usuario. Se lanza ANTES
 * de escribir cualquier fila: una importación inválida nunca deja datos parciales.
 *
 * details(): mensajes planos (compatibilidad). errors(): lista estructurada
 * [{row:?int, column:?string, message:string}] que se persiste por intento de carga.
 */
class EstadoResultadosImportException extends \RuntimeException
{
    private ?int $attemptId = null;

    /**
     * @param  list<string>  $details
     * @param  list<array{row:?int, column:?string, message:string}>  $errors
     */
    public function __construct(string $message, private readonly array $details = [], private readonly array $errors = [], private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return list<array{row:?int, column:?string, message:string}> */
    public function errors(): array
    {
        if ($this->errors !== []) {
            return array_values(array_map(fn ($e) => [
                'row' => isset($e['row']) ? (int) $e['row'] : null,
                'column' => $e['column'] ?? null,
                'message' => (string) $e['message'],
            ], $this->errors));
        }
        $list = $this->details === [] ? [$this->getMessage()] : $this->details;

        return array_values(array_map(function (string $m) {
            return ['row' => preg_match('/^Fila (\d+):/u', $m, $mm) ? (int) $mm[1] : null, 'column' => null, 'message' => $m];
        }, $list));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function attemptId(): ?int
    {
        return $this->attemptId;
    }

    public function withAttempt(int $id): static
    {
        $this->attemptId = $id;

        return $this;
    }
}

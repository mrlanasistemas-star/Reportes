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
    /** Intento de carga (estado_resultados_import_attempts) donde quedó registrado el rechazo. */
    private ?int $attemptId = null;

    /**
     * @param  string  $message  mensaje principal en español
     * @param  list<string>  $details  mensajes de detalle (qué hacer / qué falta)
     * @param  list<array{row:?int, column:?string, message:string}>  $errors  errores por fila/columna
     * @param  int  $status  código HTTP (422 por defecto; 409 si hay otra carga en curso)
     */
    public function __construct(string $message, private readonly array $details = [], private readonly array $errors = [], private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * Mensajes de detalle planos (compatibilidad).
     *
     * @return list<string>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Errores estructurados; si no se pasaron, se derivan de los detalles (o del mensaje)
     * extrayendo el número de fila de los textos «Fila N: …».
     *
     * @return list<array{row:?int, column:?string, message:string}>
     */
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

    /**
     * Código HTTP de la respuesta.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Id del intento de carga asociado (null si aún no se registró).
     */
    public function attemptId(): ?int
    {
        return $this->attemptId;
    }

    /**
     * Asocia el intento de carga a la excepción (encadenable).
     *
     * @param  int  $id  id del intento
     */
    public function withAttempt(int $id): static
    {
        $this->attemptId = $id;

        return $this;
    }
}

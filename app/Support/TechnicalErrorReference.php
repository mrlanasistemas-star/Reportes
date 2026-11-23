<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Referencia de un error técnico real: el usuario ve un mensaje humano con la
 * referencia y Sistemas la busca en el log (`error_ref`), donde sí queda el
 * detalle. Una sola referencia por petición, compartida entre el reporte al log
 * y la respuesta.
 */
class TechnicalErrorReference
{
    /**
     * Distingue un error técnico (bug, BD caída, excepción inesperada) de los
     * errores "esperados" del framework (HTTP, validación, autenticación,
     * autorización, modelo no encontrado, token CSRF vencido), que ya tienen su
     * propia respuesta al usuario.
     *
     * @param  Throwable  $e  Excepción capturada.
     * @return bool true si debe mostrarse el mensaje genérico con referencia.
     */
    public static function isTechnical(Throwable $e): bool
    {
        return ! ($e instanceof HttpExceptionInterface
            || $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException
            || $e instanceof TokenMismatchException);
    }

    /**
     * Devuelve la referencia de error de la petición actual; la genera
     * (`ERR-XXXXXXXX`) y la guarda en los atributos del request si aún no existe,
     * para que log y respuesta compartan el mismo valor.
     *
     * @return string Referencia única de la petición.
     */
    public static function forCurrentRequest(): string
    {
        $request = request();
        $ref = $request->attributes->get('error_ref');
        if (! is_string($ref)) {
            $ref = 'ERR-'.strtoupper(Str::random(8));
            $request->attributes->set('error_ref', $ref);
        }

        return $ref;
    }

    /**
     * Mensaje en español que se muestra al usuario cuando ocurre un error técnico.
     *
     * @param  string  $ref  Referencia que debe proporcionar a Sistemas.
     * @return string Mensaje listo para mostrar.
     */
    public static function message(string $ref): string
    {
        return "No pudimos completar la operación por un error técnico. Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: {$ref}.";
    }
}

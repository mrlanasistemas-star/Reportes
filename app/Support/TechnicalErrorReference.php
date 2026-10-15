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
    public static function isTechnical(Throwable $e): bool
    {
        return ! ($e instanceof HttpExceptionInterface
            || $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException
            || $e instanceof TokenMismatchException);
    }

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

    public static function message(string $ref): string
    {
        return "No pudimos completar la operación por un error técnico. Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: {$ref}.";
    }
}

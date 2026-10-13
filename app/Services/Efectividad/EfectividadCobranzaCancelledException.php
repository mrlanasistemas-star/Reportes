<?php

namespace App\Services\Efectividad;

/**
 * Señal interna (no un error) para detener `GenerateEfectividadCobranzaAprobadaJob`
 * en un punto de cancelación cooperativa cuando el usuario solicitó detener la
 * generación. Se captura dentro del propio Job — nunca llega a `failed()` como
 * si fuera una falla técnica.
 */
class EfectividadCobranzaCancelledException extends \RuntimeException {}

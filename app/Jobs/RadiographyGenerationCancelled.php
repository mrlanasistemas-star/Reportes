<?php

namespace App\Jobs;

/**
 * Fase 10 — señal interna de GenerateRadiographyJob: el usuario canceló la
 * generación mientras el Job corría. No es un error (no marca failed, no manda
 * correo de error, no reintenta): el run se queda como `cancelled`.
 */
class RadiographyGenerationCancelled extends \RuntimeException
{
}

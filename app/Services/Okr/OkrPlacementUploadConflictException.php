<?php

namespace App\Services\Okr;

use App\Models\OkrPlacementUpload;
use RuntimeException;

/**
 * Parte 6.7 del cierre (04-oct-2026) — ya existe una carga ACTIVA para este
 * Objective/semana. El controlador atrapa esta excepción y responde con el
 * upload existente para que la UI pregunte "¿deseas reemplazarla?" en vez de
 * duplicar silenciosamente la colocación de esa semana.
 */
class OkrPlacementUploadConflictException extends RuntimeException
{
    /**
     * Crea la excepción conservando la carga activa que provoca el conflicto.
     *
     * @param  OkrPlacementUpload  $existingUpload  Carga activa ya registrada para el mismo Objective/semana; la UI la muestra para ofrecer reemplazo.
     */
    public function __construct(public readonly OkrPlacementUpload $existingUpload)
    {
        parent::__construct('Ya existe colocación cargada para esta semana.');
    }
}

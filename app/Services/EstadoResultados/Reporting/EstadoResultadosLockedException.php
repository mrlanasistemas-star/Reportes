<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\EstadoResultados\EstadoResultadosVersion;

/** El periodo tiene una versión aprobada vigente: cualquier cambio se rechaza con HTTP 423. */
class EstadoResultadosLockedException extends EstadoResultadosGovernanceException
{
    public function __construct(EstadoResultadosVersion $version, string $periodLabel)
    {
        parent::__construct(
            'El Estado de Resultados de '.$periodLabel.' está aprobado (versión '.$version->version.') y no admite cambios. '
            .'Para corregirlo, un usuario con permiso «Aprobar» debe abrir una nueva versión indicando el motivo.',
            423,
            ['locked' => true, 'version' => $version->version],
        );
    }
}

<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;

/**
 * Permisos del usuario dentro del Estado de Resultados. El backend es la
 * autoridad (middleware `permission:` + redacción en EstadoResultadosRedactor);
 * esto se comparte a la UI sólo para ocultar controles.
 *
 * Claves de acción guardadas (role_action_permissions) — se conservan tal cual:
 *   procesar   = conciliar,  clasificar = reclasificar,  ajustes = editar ajustes.
 */
class EstadoResultadosAbilities
{
    public const MODULE = 'estado_resultados';

    public function __construct(
        private readonly ActionPermissionService $actions,
        private readonly ModuleAccessService $modules,
    ) {}

    public function can(?User $user, string $action): bool
    {
        return $this->actions->canAction($user, self::MODULE, $action);
    }

    /** @return array<string,bool> */
    public function for(?User $user): array
    {
        $can = fn (string $a) => $this->can($user, $a);

        return [
            'consultar' => $this->modules->allows($user, self::MODULE),
            'cargar' => $can('cargar'),
            'procesar' => $can('procesar'),
            'conciliar' => $can('procesar'),
            'clasificar' => $can('clasificar'),
            'reclasificar' => $can('clasificar'),
            'ajustes' => $can('ajustes'),
            'editar_ajustes' => $can('ajustes'),
            'aprobar' => $can('aprobar'),
            'exportar' => $can('exportar'),
            'ver_nomina' => $can('ver_nomina'),
            'ver_dg' => $can('ver_dg'),
        ];
    }
}

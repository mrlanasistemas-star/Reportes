<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\DataScopeService;
use App\Services\Access\ModuleAccessService;

/**
 * Permisos del usuario dentro del Estado de Resultados. El backend es la
 * autoridad (middleware `permission:` + redacción en EstadoResultadosRedactor);
 * esto se comparte a la UI sólo para ocultar controles.
 *
 * Claves de acción guardadas (role_action_permissions) — se conservan tal cual:
 *   procesar   = conciliar,  clasificar = reclasificar,  ajustes = editar ajustes.
 *
 * Alcance por sucursal (06-nov-2026): cargar, procesar, clasificar, aprobar, el historial de
 * clasificación y los intentos de carga son operaciones del periodo COMPLETO y el servidor las
 * rechaza (DataScopeService::requireGeneral) a quien sólo ve sus sucursales; aquí se informan en
 * false para que la UI no muestre controles que siempre terminarían en 403. Los ajustes sí se
 * permiten por sucursal (se autorizan contra el alcance del ajuste).
 */
class EstadoResultadosAbilities
{
    public const MODULE = 'estado_resultados';

    public const BRANCH_SCOPE_REASON = 'Tu acceso está limitado a tus sucursales: cargar, procesar, clasificar y aprobar se hacen sobre el periodo completo y los realiza alguien con acceso general.';

    public function __construct(
        private readonly ActionPermissionService $actions,
        private readonly ModuleAccessService $modules,
        private readonly DataScopeService $dataScope,
    ) {}

    public function can(?User $user, string $action): bool
    {
        return $this->actions->canAction($user, self::MODULE, $action);
    }

    /** @return array<string,bool|string|null> */
    public function for(?User $user): array
    {
        $can = fn (string $a) => $this->can($user, $a);
        $general = $user === null || $this->dataScope->isGeneral($user);
        $whole = fn (string $a) => $general && $can($a);

        return [
            'consultar' => $this->modules->allows($user, self::MODULE),
            'cargar' => $whole('cargar'),
            'procesar' => $whole('procesar'),
            'conciliar' => $whole('procesar'),
            'clasificar' => $whole('clasificar'),
            'reclasificar' => $whole('clasificar'),
            'ajustes' => $can('ajustes'),
            'editar_ajustes' => $can('ajustes'),
            'aprobar' => $whole('aprobar'),
            'exportar' => $can('exportar'),
            'ver_nomina' => $can('ver_nomina'),
            'ver_dg' => $can('ver_dg'),
            'historial_clasificacion' => $general,
            'acceso_general' => $general,
            'motivo_restriccion' => $general ? null : self::BRANCH_SCOPE_REASON,
        ];
    }
}

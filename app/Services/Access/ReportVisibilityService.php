<?php

namespace App\Services\Access;

use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;

/**
 * Alcance por AUTOR de los reportes mensuales (27-oct-2026) — fuente única de
 * "¿de quién puede ver reportes este usuario?". Complementa (no reemplaza) a
 * DataScopeService, que decide SOBRE QUÉ sucursales; ambos se aplican siempre:
 *
 *   - Administrador o acción `reportes.ver_todos`: reportes generados por
 *     cualquier usuario.
 *   - Solo `reportes.ver_propios`: únicamente los que generó él mismo.
 *   - Ninguna de las dos: no ve reportes (aunque tenga el módulo).
 *
 * "Ver todos" NUNCA amplía el alcance por sucursal: un usuario restringido a
 * ATLIXCO ve los reportes de ATLIXCO de todos los usuarios, pero nunca los de
 * TULA ni el general/consolidado (eso solo con alcance general en Usuarios).
 *
 * "Propio" = el usuario lo generó: un run (`period_radiography_runs.created_by`)
 * o el consolidado del periodo (`period_summaries.generated_by`). Generar el
 * consolidado de un periodo hace propias todas sus vistas (general, por
 * sucursal y por gestor, siempre dentro de su alcance por sucursal).
 */
class ReportVisibilityService
{
    public const ALL = 'all';
    public const OWN = 'own';
    public const NONE = 'none';

    public function __construct(private readonly ActionPermissionService $actions)
    {
    }

    public function mode(?User $user): string
    {
        if (! $user) {
            return self::NONE;
        }
        if ($user->isAdmin() || $this->actions->canAction($user, 'reportes', 'ver_todos')) {
            return self::ALL;
        }

        return $this->actions->canAction($user, 'reportes', 'ver_propios') ? self::OWN : self::NONE;
    }

    public function canViewAll(?User $user): bool
    {
        return $this->mode($user) === self::ALL;
    }

    public function canViewAny(?User $user): bool
    {
        return $this->mode($user) !== self::NONE;
    }

    public function canViewRun(User $user, PeriodRadiographyRun $run): bool
    {
        return match ($this->mode($user)) {
            self::ALL => true,
            self::OWN => (int) $run->created_by === (int) $user->id,
            default => false,
        };
    }

    public function canViewSummary(User $user, PeriodSummary $summary): bool
    {
        return match ($this->mode($user)) {
            self::ALL => true,
            self::OWN => (int) $summary->generated_by === (int) $user->id,
            default => false,
        };
    }

    /** ¿Generó el consolidado del periodo (o un run simple/general de él)? */
    public function ownsPeriod(User $user, int $periodId): bool
    {
        return PeriodSummary::query()->where('period_id', $periodId)->where('generated_by', $user->id)->exists()
            || PeriodRadiographyRun::query()->where('period_id', $periodId)->where('status', 'success')->where('created_by', $user->id)
                ->where(fn ($q) => $q->whereNull('report_type')->orWhere('report_type', 'simple'))
                ->where(fn ($q) => $q->whereNull('scope')->orWhere('scope', 'general'))
                ->exists();
    }

    /**
     * Vista/exporte de un periodo con un alcance concreto (general, sucursal,
     * gestor y, opcionalmente, comparativo contra otro periodo). El alcance por
     * sucursal lo valida aparte DataScopeService.
     */
    public function canViewPeriodReport(User $user, int $periodId, ?string $scope = 'general', ?int $branchId = null, ?int $employeeId = null, ?int $comparePeriodId = null): bool
    {
        $mode = $this->mode($user);
        if ($mode !== self::OWN) {
            return $mode === self::ALL;
        }

        $comparePeriodId = $comparePeriodId && $comparePeriodId !== $periodId ? $comparePeriodId : null;
        if ($this->ownsPeriod($user, $periodId) && (! $comparePeriodId || $this->ownsPeriod($user, $comparePeriodId))) {
            return true;
        }

        // Un run propio con exactamente esa identidad (p. ej. "su" reporte de ATLIXCO).
        $scope = in_array($scope, ['branch', 'employee'], true) ? $scope : 'general';

        return PeriodRadiographyRun::query()
            ->where('period_id', $periodId)->where('status', 'success')->where('created_by', $user->id)
            ->when($scope === 'general', fn ($q) => $q->where(fn ($w) => $w->whereNull('scope')->orWhere('scope', 'general')))
            ->when($scope === 'branch', fn ($q) => $q->where('scope', 'branch')->where('branch_id', $branchId))
            ->when($scope === 'employee', fn ($q) => $q->where('scope', 'employee')->where('employee_id', $employeeId))
            ->when($comparePeriodId, fn ($q) => $q->where('comparison_period_id', $comparePeriodId), fn ($q) => $q->whereNull('comparison_period_id'))
            ->exists();
    }

    /** IDs de periodos de los que el usuario puede ver algún reporte (null = todos). @return list<int>|null */
    public function visiblePeriodIds(User $user): ?array
    {
        $mode = $this->mode($user);
        if ($mode === self::ALL) {
            return null;
        }
        if ($mode === self::NONE) {
            return [];
        }

        return PeriodSummary::query()->where('generated_by', $user->id)->pluck('period_id')
            ->merge(PeriodRadiographyRun::query()->where('status', 'success')->where('created_by', $user->id)->pluck('period_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function authorizePeriodReport(User $user, int $periodId, ?string $scope = 'general', ?int $branchId = null, ?int $employeeId = null, ?int $comparePeriodId = null): void
    {
        if ($this->canViewPeriodReport($user, $periodId, $scope, $branchId, $employeeId, $comparePeriodId)) {
            return;
        }

        abort(403, $this->deniedMessage($user));
    }

    public function deniedMessage(?User $user): string
    {
        return $this->mode($user) === self::NONE
            ? 'Tu rol no tiene permiso para ver reportes mensuales («Ver reportes propios» o «Ver reportes de todos»). Pide a un administrador que lo habilite en Roles y permisos.'
            : 'Este reporte lo generó otro usuario y tu rol solo permite «Ver reportes propios». Para consultarlo, pide a un administrador que habilite «Ver reportes de todos» en Roles y permisos.';
    }

    /** Texto para la UI: qué reportes ve y de qué sucursales. */
    public function describe(User $user): array
    {
        $scope = app(DataScopeService::class);
        $mode = $this->mode($user);

        return [
            'mode' => $mode,
            'general_scope' => $scope->isGeneral($user),
            'branches_label' => $scope->label($user),
            'text' => match ($mode) {
                self::ALL => $scope->isGeneral($user)
                    ? 'Ves los reportes generados por todos los usuarios, de todas las sucursales.'
                    : 'Ves los reportes generados por todos los usuarios, pero solo de tus sucursales (' . $scope->label($user) . '). Los reportes generales/consolidados requieren alcance general.',
                self::OWN => 'Solo ves los reportes que generaste tú' . ($scope->isGeneral($user) ? '.' : ', y solo de tus sucursales (' . $scope->label($user) . ').'),
                default => 'Tu rol no tiene permiso para ver reportes mensuales.',
            },
        ];
    }
}

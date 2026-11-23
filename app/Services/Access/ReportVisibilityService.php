<?php

namespace App\Services\Access;

use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
 *
 * Lo usan los controladores de reportes/periodos y exportaciones para filtrar
 * listados y autorizar vistas. Solo lee BD (period_summaries, period_radiography_runs).
 */
class ReportVisibilityService
{
    /** Ve reportes generados por cualquier usuario. */
    public const ALL = 'all';

    /** Solo ve los reportes que él mismo generó. */
    public const OWN = 'own';

    /** No ve reportes mensuales. */
    public const NONE = 'none';

    /**
     * @param  ActionPermissionService  $actions  Resuelve las acciones reportes.ver_todos / ver_propios.
     */
    public function __construct(private readonly ActionPermissionService $actions) {}

    /**
     * Modo de visibilidad por autor del usuario.
     *
     * Regla: sin usuario = NONE; admin o `reportes.ver_todos` = ALL;
     * `reportes.ver_propios` = OWN; si no, NONE.
     *
     * @param  User|null  $user  Usuario autenticado.
     * @return string ALL, OWN o NONE.
     */
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

    /**
     * ¿Ve los reportes de todos los usuarios (modo ALL)?
     *
     * @param  User|null  $user  Usuario autenticado.
     */
    public function canViewAll(?User $user): bool
    {
        return $this->mode($user) === self::ALL;
    }

    /**
     * ¿Ve al menos algún reporte (modo ALL u OWN)?
     *
     * @param  User|null  $user  Usuario autenticado.
     */
    public function canViewAny(?User $user): bool
    {
        return $this->mode($user) !== self::NONE;
    }

    /**
     * ¿Puede ver una corrida de radiografía concreta? En modo OWN solo si la creó él.
     *
     * @param  User  $user  Usuario autenticado.
     * @param  PeriodRadiographyRun  $run  Corrida a consultar.
     */
    public function canViewRun(User $user, PeriodRadiographyRun $run): bool
    {
        return match ($this->mode($user)) {
            self::ALL => true,
            self::OWN => (int) $run->created_by === (int) $user->id,
            default => false,
        };
    }

    /**
     * ¿Puede ver el consolidado de un periodo? En modo OWN solo si él lo generó.
     *
     * @param  User  $user  Usuario autenticado.
     * @param  PeriodSummary  $summary  Consolidado del periodo.
     */
    public function canViewSummary(User $user, PeriodSummary $summary): bool
    {
        return match ($this->mode($user)) {
            self::ALL => true,
            self::OWN => (int) $summary->generated_by === (int) $user->id,
            default => false,
        };
    }

    /**
     * ¿Generó el consolidado del periodo (o un run simple/general de él)?
     *
     * Cuenta solo runs con status 'success', report_type null/'simple' y scope
     * null/'general'. Consulta BD.
     *
     * @param  User  $user  Usuario autenticado.
     * @param  int  $periodId  Periodo a evaluar.
     */
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
     *
     * En modo OWN: basta con poseer el periodo (y el de comparación, si lo hay);
     * si no, busca un run exitoso propio con exactamente ese scope, sucursal,
     * gestor y periodo de comparación.
     *
     * @param  User  $user  Usuario autenticado.
     * @param  int  $periodId  Periodo del reporte.
     * @param  string|null  $scope  'general', 'branch' o 'employee' (otro valor = general).
     * @param  int|null  $branchId  Sucursal cuando scope = branch.
     * @param  int|null  $employeeId  Gestor cuando scope = employee.
     * @param  int|null  $comparePeriodId  Periodo comparativo (se ignora si es el mismo).
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

    /**
     * IDs de periodos de los que el usuario puede ver algún reporte (null = todos).
     *
     * En modo OWN une los periodos de sus consolidados y de sus runs exitosos.
     *
     * @param  User  $user  Usuario autenticado.
     * @return list<int>|null null = sin filtro; [] = ninguno.
     */
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

    /**
     * Igual que canViewPeriodReport() pero aborta con HTTP 403 (mensaje de
     * deniedMessage()) si no está permitido.
     *
     * @param  User  $user  Usuario autenticado.
     * @param  int  $periodId  Periodo del reporte.
     * @param  string|null  $scope  'general', 'branch' o 'employee'.
     * @param  int|null  $branchId  Sucursal cuando scope = branch.
     * @param  int|null  $employeeId  Gestor cuando scope = employee.
     * @param  int|null  $comparePeriodId  Periodo comparativo.
     *
     * @throws HttpException 403 si no puede verlo.
     */
    public function authorizePeriodReport(User $user, int $periodId, ?string $scope = 'general', ?int $branchId = null, ?int $employeeId = null, ?int $comparePeriodId = null): void
    {
        if ($this->canViewPeriodReport($user, $periodId, $scope, $branchId, $employeeId, $comparePeriodId)) {
            return;
        }

        abort(403, $this->deniedMessage($user));
    }

    /**
     * Mensaje en español que explica por qué se negó el acceso (sin permiso
     * alguno vs. solo "ver propios").
     *
     * @param  User|null  $user  Usuario autenticado.
     */
    public function deniedMessage(?User $user): string
    {
        return $this->mode($user) === self::NONE
            ? 'Tu rol no tiene permiso para ver reportes mensuales («Ver reportes propios» o «Ver reportes de todos»). Pide a un administrador que lo habilite en Roles y permisos.'
            : 'Este reporte lo generó otro usuario y tu rol solo permite «Ver reportes propios». Para consultarlo, pide a un administrador que habilite «Ver reportes de todos» en Roles y permisos.';
    }

    /**
     * Texto para la UI: qué reportes ve y de qué sucursales.
     *
     * Combina el modo por autor con el alcance por sucursal de DataScopeService.
     *
     * @param  User  $user  Usuario autenticado.
     * @return array{mode:string, general_scope:bool, branches_label:string, text:string}
     */
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
                    : 'Ves los reportes generados por todos los usuarios, pero solo de tus sucursales ('.$scope->label($user).'). Los reportes generales/consolidados requieren alcance general.',
                self::OWN => 'Solo ves los reportes que generaste tú'.($scope->isGeneral($user) ? '.' : ', y solo de tus sucursales ('.$scope->label($user).').'),
                default => 'Tu rol no tiene permiso para ver reportes mensuales.',
            },
        ];
    }
}

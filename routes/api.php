<?php

use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CapabilitiesController;
use App\Http\Controllers\Api\V1\CollectionsController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\FinancialController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OkrController;
use App\Http\Controllers\Api\V1\PeriodController;
use Illuminate\Support\Facades\Route;

// Fase 1 — API general de reportería, servidor a servidor (Sanctum personal
// access tokens emitidos a App\Models\ApiConsumer, NUNCA a User). Ver
// docs/api-reporteria.md. /health es la única ruta pública (sin datos
// sensibles) — todo lo demás exige token + ability + rate limit + auditoría.

Route::get('/v1/health', [HealthController::class, 'index']);

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api-v1', 'log.api-query'])
    ->where(['employeeId' => '[0-9]+', 'branchId' => '[0-9]+', 'periodId' => '[0-9]+'])
    ->group(function () {
        Route::get('/capabilities', [CapabilitiesController::class, 'index']);

        Route::middleware('ensure.ability:branches.read')->group(function () {
            Route::get('/branches', [BranchController::class, 'index']);
        });

        Route::middleware('ensure.ability:employees.read')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::get('/employees/{employeeId}', [EmployeeController::class, 'show']);

            // Fase 2.1 (corrección de seguridad): la ficha financiera de un
            // colaborador exige AMBAS abilities — tener acceso al catálogo de
            // empleados NUNCA debe bastar, por sí solo, para leer sus datos
            // financieros. `ensure.ability` verifica TODAS las que reciba.
            Route::middleware('ensure.ability:employees.read,financial.read')->group(function () {
                Route::get('/employees/{employeeId}/financial-summary', [EmployeeController::class, 'financialSummary']);
            });
        });

        Route::middleware('ensure.ability:periods.read')->group(function () {
            Route::get('/periods', [PeriodController::class, 'index']);
            Route::get('/periods/{periodId}', [PeriodController::class, 'show']);
        });

        Route::middleware('ensure.ability:financial.read')->group(function () {
            Route::get('/financial/summary', [FinancialController::class, 'summary']);
            Route::get('/financial/branches/{branchId}', [FinancialController::class, 'branchSummary']);
            Route::get('/financial/employees/{employeeId}', [FinancialController::class, 'employeeSummary']);
            Route::get('/financial/metrics', [FinancialController::class, 'metrics']);
            Route::get('/financial/metric-values', [FinancialController::class, 'metricValues']);
        });

        Route::middleware('ensure.ability:collections.read')->group(function () {
            Route::get('/collections/effectiveness', [CollectionsController::class, 'effectiveness']);
        });

        // Exportación: ability PROPIA (`collections.export`, nunca basta con leer) +
        // límite más estricto. Entrega el Excel V4 YA generado desde el módulo web
        // (motor + metodología vigentes); NUNCA recalcula en el GET.
        Route::middleware(['ensure.ability:collections.export', 'throttle:api-exports'])->group(function () {
            Route::get('/collections/effectiveness/export', [CollectionsController::class, 'exportEffectiveness']);
        });

        Route::middleware('ensure.ability:okr.read')->group(function () {
            Route::get('/okr/summary', [OkrController::class, 'summary']);
            Route::get('/okr/employees/{employeeId}', [OkrController::class, 'employeeSummary']);
        });
    });

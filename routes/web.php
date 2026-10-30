<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\ReportUploadController;
use App\Http\Controllers\EmployeeBranchAssignmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ValidationController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\SystemGuideController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EfectividadCalculadoraController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\Okr\ResponsibleController as UserAdministrationController;
use App\Http\Controllers\Okr\RoleController as RoleAdministrationController;

Route::redirect('/', '/login');

// SEO — robots.txt y sitemap.xml servidos por ruta (dominio desde APP_URL).
// Qué páginas son públicas/indexables: config/seo.php — ver docs/seo.md.
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

Route::middleware(['auth', 'verified'])->group(function () {
    // Módulo OKR (08-sep-2026) — dentro del MISMO grupo auth+verified que el
    // resto de Reportería, nunca autenticación aparte. Además de su regla propia
    // (EnsureOkrAccessEnabled) exige el módulo 'okr' (ver routes/okr.php).
    require __DIR__ . '/okr.php';

    // Notificaciones internas persistentes (campana de la cabecera). Solo las del
    // propio usuario; los trabajos activos de Reportería solo se incluyen para
    // roles gerenciales (ver NotificationController).
    Route::prefix('notificaciones')
        ->name('notifications.')
        ->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::post('/leidas', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::post('/{notification}/leida', [NotificationController::class, 'markRead'])->name('read');
        });

    // Documentación de la API (14-oct-2026): solo admin, por enlace directo —
    // deliberadamente fuera del sidebar (ver ApiDocumentationController).
    Route::get('/documentacion-api', [ApiDocumentationController::class, 'index'])->name('api-docs.index');

    // 16-oct-2026 — bienvenida al recorrido vista POR USUARIO (antes solo en
    // localStorage y reaparecía en cada inicio de sesión). Sin módulo: aplica a todos.
    Route::post('/guia-sistema/bienvenida-vista', [SystemGuideController::class, 'markWelcomeSeen'])->name('guia-sistema.welcome-seen');

    Route::prefix('guia-sistema')
        ->name('guia-sistema.')
        ->group(function () {
            Route::get('/', [SystemGuideController::class, 'index'])->name('index');
            Route::get('/pdf', [SystemGuideController::class, 'pdf'])->name('pdf');
        });

    // Acceso por módulo (14-oct-2026): cada bloque exige su módulo — qué módulos
    // ve cada rol se configura en "Usuarios y accesos" (ModuleAccessService). El
    // admin ve todo siempre. Reemplaza al antiguo EnsureReporteriaAccess fijo.
    Route::middleware('module:dashboard')->group(function () {
    // Cierre 17-sep-2026 ronda 4 — el Dashboard ahora resume el periodo más reciente
    // con radiografía generada (antes era una página estática sin datos). Ver
    // DashboardController — misma fuente (RadiografiaExportService) que Web/Excel/PDF.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard-data', [DashboardController::class, 'data'])->name('dashboard.data');
    Route::get('/dashboard-trend', [DashboardController::class, 'trend'])->name('dashboard.trend');
    // PDF del Dashboard (15-oct-2026): KPI + gráficas del periodo/sucursal elegidos
    // (antes el botón descargaba la Radiografía completa). Mismo permiso de
    // exportación que tenía el enlace anterior.
    Route::get('/dashboard/exportar.pdf', [DashboardController::class, 'exportPdf'])->middleware('permission:reportes,exportar')->name('dashboard.export-pdf');
    // Fase 7.3 — Excel del Dashboard: mismo alcance, mismos datos y MISMO permiso que el PDF.
    Route::get('/dashboard/exportar.xlsx', [DashboardController::class, 'exportExcel'])->middleware('permission:reportes,exportar')->name('dashboard.export-excel');
    // Alias 'home' — el scaffolding de auth (login/logout/verificación/tests de
    // ejemplo) espera route('home') por convención de Laravel; este proyecto no tenía
    // ese nombre registrado, lo que rompía AuthenticationTest/
    // VerificationNotificationTest/ProfileUpdateTest/ExampleTest con "Route [home] not
    // defined." Debe responder 200 directamente (no un redirect), y en una URI PROPIA:
    // registrar dos rutas con la MISMA URI '/dashboard' (una named 'dashboard', otra
    // 'home') hace que Laravel pierda la resolución por nombre de la primera —
    // confirmado con `php artisan route:list --name=dashboard` (vacío) tras probarlo.
    Route::get('/home', [DashboardController::class, 'index'])->name('home');
    });

    Route::prefix('historico-general')
        ->middleware('module:carga')
        ->name('historico-general.')
        ->group(function () {
            Route::get('/', [ReportUploadController::class, 'index'])->name('index');
            Route::post('/', [ReportUploadController::class, 'store'])->middleware('permission:carga,subir')->name('store');
            Route::delete('/{reportUpload}', [ReportUploadController::class, 'destroy'])->middleware('permission:carga,eliminar')->name('destroy');
            Route::get('/{reportUpload}/descargar', [ReportUploadController::class, 'download'])->middleware('permission:carga,descargar')->name('download');
            Route::post('/{reportUpload}/analizar', [ReportUploadController::class, 'analyze'])->middleware('permission:carga,subir')->name('analyze');
            Route::post('/{period}/actualizar-bd', [ReportUploadController::class, 'updateDatabase'])->middleware('permission:carga,procesar')->name('update-database');
            Route::post('/{period}/actualizacion-bd/cancelar', [ReportUploadController::class, 'cancelDatabaseUpdate'])->middleware('permission:carga,procesar')->name('cancel-database-update');
            Route::post('/{period}/actualizacion-bd/limpiar', [ReportUploadController::class, 'clearStuckDatabaseUpdate'])->middleware('permission:carga,procesar')->name('clear-stuck-database-update');
            Route::get('/{period}/actualizacion-bd/progreso', [ReportUploadController::class, 'loadProgressStatus'])->name('load-progress-status');
            Route::post('/{period}/actualizacion-bd/procesar-ahora', [ReportUploadController::class, 'processNow'])->middleware('permission:carga,procesar')->name('process-now');
            Route::post('/{period}/actualizacion-bd/reencolar', [ReportUploadController::class, 'requeueRun'])->middleware('permission:carga,procesar')->name('requeue-run');
            Route::get('/{period}/incidencias', [ReportUploadController::class, 'incidents'])->name('incidents');
            Route::get('/{period}/colaboradores', [ReportUploadController::class, 'periodEmployeesForConfig'])->name('period-employees');
            Route::post('/{period}/incidencias/{incident}/resolver', [ReportUploadController::class, 'resolveIncident'])->middleware('permission:carga,procesar')->name('incidents.resolve');
            Route::post('/{period}/incidencias/refrescar', [ReportUploadController::class, 'refreshIncidents'])->middleware('permission:carga,procesar')->name('incidents.refresh');
            Route::get('/{period}/personas-sin-sucursal', [ReportUploadController::class, 'personasSinSucursal'])->name('personas-sin-sucursal');
            Route::post('/{period}/personas-sin-sucursal/confirmar-coincidencia', [ReportUploadController::class, 'confirmarCoincidencia'])->middleware('permission:carga,procesar')->name('personas-sin-sucursal.confirmar');
            Route::post('/{period}/coincidencias/descartar', [ReportUploadController::class, 'descartarCoincidencia'])->middleware('permission:carga,procesar')->name('coincidencias.descartar');
            Route::post('/{period}/incidencias/ubicacion-pendiente/resolver', [ReportUploadController::class, 'resolvePendingLocation'])->middleware('permission:carga,procesar')->name('incidents.resolve-location');
            Route::post('/{period}/generar-radiografia', [ReportUploadController::class, 'generateRadiography'])->middleware('permission:carga,generar')->name('generate-radiography');
            Route::get('/{period}/generar-reporte/progreso', [ReportUploadController::class, 'generationProgress'])->name('generation-progress');
            Route::post('/{period}/generar-reporte/cancelar', [ReportUploadController::class, 'cancelGeneration'])->middleware('permission:carga,generar')->name('cancel-generation');
            Route::post('/{period}/generar-reporte/procesar-ahora', [ReportUploadController::class, 'processGenerationNow'])->middleware('permission:carga,generar')->name('process-generation-now');
            Route::post('/{period}/procesar-fuentes-pendientes', [ReportUploadController::class, 'processPendingSources'])->middleware('permission:carga,procesar')->name('process-pending-sources');
            Route::post('/{period}/reprocesar-fuentes-con-error', [ReportUploadController::class, 'reprocessFailedSources'])->middleware('permission:carga,procesar')->name('reprocess-failed-sources');
            Route::post('/{period}/reprocesar-todo', [ReportUploadController::class, 'reprocessAll'])->middleware('permission:carga,procesar')->name('reprocess-all');
        });

    Route::prefix('periodos')
        ->middleware('module:periodos')
        ->name('periodos.')
        ->group(function () {
            Route::get('/', [PeriodController::class, 'index'])->name('index');
            Route::post('/', [PeriodController::class, 'store'])->middleware('permission:periodos,crear')->name('store');
            Route::delete('/{period}', [PeriodController::class, 'destroy'])->middleware('permission:periodos,eliminar')->name('destroy');
            Route::post('/{period}/close', [PeriodController::class, 'close'])->middleware('permission:periodos,cerrar')->name('close');
            Route::post('/{period}/open', [PeriodController::class, 'open'])->middleware('permission:periodos,cerrar')->name('open');
        });

    Route::prefix('asignaciones-empleado-sucursal')
        ->middleware('module:colaboradores')
        ->name('asignaciones-empleado-sucursal.')
        ->group(function () {
            Route::get('/', [EmployeeBranchAssignmentController::class, 'index'])->name('index');
            Route::get('/pendientes', [EmployeeBranchAssignmentController::class, 'pending'])->name('pending');
            Route::post('/match-automatico', [EmployeeBranchAssignmentController::class, 'autoMatch'])->middleware('permission:colaboradores,editar')->name('auto-match');
            Route::post('/movimientos/{period}/conciliar', [EmployeeBranchAssignmentController::class, 'reviewMovement'])->middleware('permission:colaboradores,conciliar')->name('review-movement');
            Route::post('/{assignment}/match-manual', [EmployeeBranchAssignmentController::class, 'manualMatch'])->middleware('permission:colaboradores,editar')->name('manual-match');
            Route::put('/{assignment}', [EmployeeBranchAssignmentController::class, 'update'])->middleware('permission:colaboradores,editar')->name('update');
        });

    Route::prefix('empleados')
        ->middleware('module:colaboradores')
        ->name('empleados.')
        ->group(function () {
            Route::post('/batch-asignar-sucursal', [EmployeeController::class, 'batchAssignBranch'])->middleware('permission:colaboradores,editar')->name('batch-assign-branch');
            Route::post('/{employee}/asignar-sucursal', [EmployeeController::class, 'assignBranch'])->middleware('permission:colaboradores,editar')->name('assign-branch');
            Route::post('/{employee}/puesto', [EmployeeController::class, 'updatePosition'])->middleware('permission:colaboradores,editar')->name('update-position');
        });

    Route::prefix('validaciones')
        ->middleware('module:reportes')
        ->name('validaciones.')
        ->group(function () {
            Route::get('/', [ValidationController::class, 'index'])->name('index');
        });

    Route::prefix('reportes-mensuales')
        ->middleware('module:reportes')
        ->name('reportes-mensuales.')
        ->group(function () {
            Route::get('/', [MonthlyReportController::class, 'index'])->name('index');
            // Comparativo web interactivo (Parte B, cierre 17-sep-2026) — declaradas ANTES de
            // '/{period}' a propósito: esa ruta hace binding por ID y "comparativo"/
            // "comparativo-data" tienen la misma forma de un solo segmento, así que deben
            // ganar la prioridad de coincidencia por orden de declaración.
            Route::get('/comparativo', [MonthlyReportController::class, 'comparativoPage'])->name('comparativo');
            Route::get('/comparativo-data', [MonthlyReportController::class, 'comparativoData'])->name('comparativo-data');
            Route::get('/comparativo-empleados', [MonthlyReportController::class, 'comparativoEmployeesLookup'])->name('comparativo-employees-lookup');
            Route::get('/{period}', [MonthlyReportController::class, 'show'])->name('show');
            Route::post('/{period}/consolidar', [MonthlyReportController::class, 'consolidate'])->middleware('permission:reportes,editar')->name('consolidate');
            Route::get('/{period}/estado-radiografia', [MonthlyReportController::class, 'status'])->name('status');
            Route::get('/{period}/preview', [MonthlyReportController::class, 'previewPage'])->name('preview');
            Route::get('/{period}/scoped-data', [MonthlyReportController::class, 'scopedData'])->name('scoped-data');
            Route::post('/{period}/saldo-inicial', [MonthlyReportController::class, 'updateSaldoInicial'])->middleware('permission:reportes,editar')->name('update-saldo-inicial');
            Route::get('/{period}/radiografia.xlsx', [MonthlyReportController::class, 'exportRadiography'])->middleware('permission:reportes,exportar')->name('export-radiography');
            Route::get('/{period}/radiografia.pdf', [MonthlyReportController::class, 'exportRadiographyPdf'])->middleware('permission:reportes,exportar')->name('export-radiography-pdf');
            Route::get('/{period}/export-filtrado.xlsx', [MonthlyReportController::class, 'exportFilteredRadiography'])->middleware('permission:reportes,exportar')->name('export-filtered-radiography');
            Route::get('/{period}/export-filtrado.pdf', [MonthlyReportController::class, 'exportFilteredRadiographyPdf'])->middleware('permission:reportes,exportar')->name('export-filtered-radiography-pdf');
            Route::get('/{period}/colaboradores.xlsx', [MonthlyReportController::class, 'exportEmployeesHistorico'])->middleware('permission:colaboradores,exportar')->name('export-employees-historico');
            Route::get('/{period}/consolidado.csv', [MonthlyReportController::class, 'exportSummary'])->middleware('permission:reportes,exportar')->name('export-summary');
            // Descargas/"Ver" ligadas a un run específico (identidad real: simple vs
            // comparativo vs por sucursal/gestor) — nunca caen al reporte simple del
            // periodo aunque el run pedido sea un comparativo.
            Route::get('/runs/{run}/excel', [MonthlyReportController::class, 'downloadRunExcel'])->middleware('permission:reportes,exportar')->name('run-excel');
            Route::get('/runs/{run}/pdf', [MonthlyReportController::class, 'downloadRunPdf'])->middleware('permission:reportes,exportar')->name('run-pdf');
            Route::get('/runs/{run}/ver', [MonthlyReportController::class, 'viewRun'])->name('run-ver');
        });

    // Calculadora de Efectividad de Cobranza — flujo único y efímero (sin BD):
    // subir → validar → calcular → exportar → eliminar temporales.
    Route::prefix('efectividad-cobranza')
        ->middleware('module:efectividad')
        ->name('efectividad-cobranza.')
        ->controller(EfectividadCalculadoraController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/calculadora/archivos', 'upload')->middleware(['throttle:120,1', 'permission:efectividad,calcular'])->name('upload');
            Route::delete('/calculadora/{token}/archivos/{archivo}', 'removeFile')->middleware('permission:efectividad,calcular')->name('remove-file');
            Route::post('/calculadora/{token}/limpiar', 'clear')->middleware('permission:efectividad,calcular')->name('clear');
            Route::post('/calculadora/{token}/calcular', 'calculate')->middleware(['throttle:30,1', 'permission:efectividad,calcular'])->name('calculate');
            Route::get('/calculadora/{token}/vista', 'view')->name('view');
            Route::get('/calculadora/{token}/exportar/{formato}', 'export')->whereIn('formato', ['pdf', 'png'])->middleware(['throttle:20,1', 'permission:efectividad,exportar'])->name('export');
            // Excel con su propio permiso (efectividad,exportar_excel) para designar por rol
            // quién ve el botón; mismo formato que el PDF/imagen (hoja EFECTIVIDAD de la macro V4).
            Route::get('/calculadora/{token}/exportar-excel', 'exportExcel')->middleware(['throttle:20,1', 'permission:efectividad,exportar_excel'])->name('export-excel');
        });

    // Administración (prioridad 8): Usuarios y Roles y permisos — módulos propios,
    // fuera de /okr. Cada operación exige su permiso (ActionPermissionService) y,
    // si quien la hace no es admin, AccessDelegationGuard le impide otorgar más
    // de lo que su propio rol tiene. Alias /okr/responsibles/* en routes/okr.php.
    Route::prefix('usuarios')
        ->middleware('module:usuarios')
        ->name('usuarios.')
        ->controller(UserAdministrationController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('permission:usuarios,crear')->name('store');
            Route::post('/{user}/activar-acceso', 'enableAccess')->middleware('permission:usuarios,gestionar_acceso')->name('enable-access');
            Route::post('/{user}/revocar-acceso', 'disableAccess')->middleware('permission:usuarios,gestionar_acceso')->name('disable-access');
            Route::post('/{user}/bloqueo', 'toggleDisabled')->middleware('permission:usuarios,gestionar_acceso')->name('toggle-disabled');
            // Asignación masiva de contraseñas + descarga ÚNICA del Excel (Nombre, Usuario, Contraseña, Correo, Sucursal).
            Route::post('/contrasenas-masivas', 'bulkResetPasswords')->middleware(['throttle:10,1', 'permission:usuarios,restablecer'])->name('bulk-reset-passwords');
            Route::get('/contrasenas-masivas/descargar', 'downloadBulkPasswords')->middleware('permission:usuarios,restablecer')->name('bulk-passwords-download');
            Route::post('/{user}/restablecer-contrasena', 'resetPassword')->middleware(['throttle:10,1', 'permission:usuarios,restablecer'])->name('reset-password');
            Route::post('/{user}/reenviar-invitacion', 'resendWelcomeEmail')->middleware(['throttle:10,1', 'permission:usuarios,invitar'])->name('resend-invitation');
            Route::put('/{user}/rol', 'updateRole')->middleware('permission:usuarios,asignar_rol')->name('update-role');
            Route::put('/{user}/colaborador', 'linkEmployee')->middleware('permission:usuarios,editar')->name('link-employee');
            // Fase 6.4 — alcance por sucursal (mismo permiso que asignar rol: define qué datos ve).
            Route::put('/{user}/sucursales', 'updateBranchScope')->middleware('permission:usuarios,asignar_rol')->name('update-branch-scope');
            // Importación masiva del directorio de gerencias (solo administradores,
            // validado también en el controlador). Previsualizar → confirmar →
            // descargar el reporte de credenciales (una sola vez).
            Route::post('/importar/preview', 'importPreview')->middleware('permission:usuarios,crear')->name('import-preview');
            Route::post('/importar', 'importConfirm')->middleware('permission:usuarios,crear')->name('import');
            Route::get('/importar/credenciales', 'downloadImportCredentials')->middleware('permission:usuarios,crear')->name('import-credentials');
        });

    Route::prefix('roles')
        ->middleware('module:roles')
        ->name('roles.')
        ->controller(RoleAdministrationController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('permission:roles,crear')->name('store');
            Route::put('/{role}', 'update')->middleware('permission:roles,editar')->name('update');
            Route::delete('/{role}', 'destroy')->middleware('permission:roles,eliminar')->name('destroy');
        });
});

if (Features::enabled(Features::updatePasswords())) {
    // Espacio para lógica futura si la ocupas.
}

require __DIR__ . '/settings.php';

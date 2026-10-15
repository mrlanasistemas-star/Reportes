<?php

use App\Http\Controllers\Okr\AlertController;
use App\Http\Controllers\Okr\CheckInController;
use App\Http\Controllers\Okr\CommitmentLetterController;
use App\Http\Controllers\Okr\DashboardController;
use App\Http\Controllers\Okr\EvidenceController;
use App\Http\Controllers\Okr\HistoryController;
use App\Http\Controllers\Okr\KeyResultController;
use App\Http\Controllers\Okr\KpiController;
use App\Http\Controllers\Okr\ObjectiveController;
use App\Http\Controllers\Okr\PlacementUploadController;
use App\Http\Controllers\Okr\ResponsibleController;
use App\Http\Controllers\Okr\RoleController;
use App\Http\Controllers\Okr\RoleModuleAccessController;
use App\Http\Controllers\Okr\WarningController;
use App\Http\Middleware\EnsureOkrAccessEnabled;
use Illuminate\Support\Facades\Route;

/**
 * Módulo OKR (08-sep-2026) — incluido desde routes/web.php dentro del MISMO
 * grupo ['auth', 'verified'] que el resto de Reportería (nunca autenticación
 * aparte). Convención de nombres: prefijo 'okr.' (sección 20 del pedido).
 *
 * EnsureOkrAccessEnabled (D5, cierre 17-sep-2026): SOLO este módulo exige
 * `access_enabled_at` — nunca se aplica al resto de Reportería.
 */
Route::prefix('okr')->name('okr.')->middleware(['module:okr', EnsureOkrAccessEnabled::class])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    // "/create" retirado 10-sep-2026 (auditoría, sección 6) — Asignar OKR ya
    // NO navega a una página aparte, es un Dialog embebido en el Dashboard
    // (ver DashboardController::index(), props wizard*). El Gate 'okr.create'
    // se conserva — sigue protegiendo POST /okr y los endpoints de apoyo.
    Route::get('/employees-lookup', [ObjectiveController::class, 'employeesLookup'])->name('employees.lookup');
    // Prioridad 5 (15-oct-2026): conciliación autorizada de sucursal en conflicto (solo okr.admin).
    Route::post('/branch-reconciliations', [\App\Http\Controllers\Okr\BranchReconciliationController::class, 'store'])->name('branch-reconciliations.store');
    Route::get('/objectives-lookup', [ObjectiveController::class, 'objectivesLookup'])->name('objectives.lookup');
    Route::get('/baseline-preview', [ObjectiveController::class, 'baselinePreview'])->middleware('permission:okr,crear')->name('baseline-preview');
    Route::post('/', [ObjectiveController::class, 'store'])->middleware('permission:okr,crear')->name('store');
    Route::get('/history', [HistoryController::class, 'index'])->name('history');
    Route::get('/kpis', [KpiController::class, 'index'])->name('kpis.index');
    Route::post('/kpis', [KpiController::class, 'store'])->name('kpis.store');
    Route::put('/kpis/{kpi}', [KpiController::class, 'update'])->name('kpis.update');
    // Usuarios / Roles y permisos se mudaron a /usuarios y /roles (prioridad 8, ver
    // routes/web.php). Estas rutas quedan como ALIAS de compatibilidad, con los
    // MISMOS módulos y permisos que las nuevas — nunca más permisivas.
    Route::get('/responsibles', fn () => redirect()->route('usuarios.index'))->middleware('module:usuarios')->name('responsibles.index');
    Route::middleware('module:usuarios')->group(function () {
        Route::post('/responsibles', [ResponsibleController::class, 'store'])->middleware('permission:usuarios,crear')->name('responsibles.store');
        Route::post('/responsibles/{user}/enable-access', [ResponsibleController::class, 'enableAccess'])->middleware('permission:usuarios,gestionar_acceso')->name('responsibles.enable-access');
        Route::post('/responsibles/{user}/disable-access', [ResponsibleController::class, 'disableAccess'])->middleware('permission:usuarios,gestionar_acceso')->name('responsibles.disable-access');
        Route::post('/responsibles/{user}/reset-password', [ResponsibleController::class, 'resetPassword'])->middleware(['throttle:10,1', 'permission:usuarios,restablecer'])->name('responsibles.reset-password');
        Route::post('/responsibles/{user}/resend-welcome-email', [ResponsibleController::class, 'resendWelcomeEmail'])->middleware(['throttle:10,1', 'permission:usuarios,invitar'])->name('responsibles.resend-welcome-email');
        Route::post('/responsibles/{user}/toggle-disabled', [ResponsibleController::class, 'toggleDisabled'])->middleware('permission:usuarios,gestionar_acceso')->name('responsibles.toggle-disabled');
        Route::put('/responsibles/{user}/role', [ResponsibleController::class, 'updateRole'])->middleware('permission:usuarios,asignar_rol')->name('responsibles.update-role');
        Route::put('/responsibles/{user}/employee', [ResponsibleController::class, 'linkEmployee'])->middleware('permission:usuarios,editar')->name('responsibles.link-employee');
    });
    Route::middleware('module:roles')->group(function () {
        Route::post('/responsibles/roles', [RoleController::class, 'store'])->middleware('permission:roles,crear')->name('responsibles.roles.store');
        Route::put('/responsibles/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles,editar')->name('responsibles.roles.update');
        Route::delete('/responsibles/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles,eliminar')->name('responsibles.roles.destroy');
        // Matriz masiva heredada (14/15-oct-2026) — solo Administrador (Gate okr.admin en el controlador).
        Route::put('/responsibles/module-access', [RoleModuleAccessController::class, 'update'])->name('responsibles.module-access');
        Route::put('/responsibles/action-permissions', [RoleModuleAccessController::class, 'updateActions'])->name('responsibles.action-permissions');
    });
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/{alert}/read', [AlertController::class, 'markRead'])->name('alerts.read');
    Route::get('/evidences/{evidence}/download', [EvidenceController::class, 'download'])->middleware('permission:okr,exportar')->name('evidences.download');
    Route::get('/commitment-letters/{letter}/download', [CommitmentLetterController::class, 'download'])->middleware('permission:okr,exportar')->name('commitment-letters.download');
    Route::post('/commitment-letters/{letter}/signed', [CommitmentLetterController::class, 'uploadSigned'])->middleware('permission:okr,emitir_carta')->name('commitment-letters.signed');
    Route::get('/warnings/{warning}/download', [WarningController::class, 'download'])->middleware('permission:okr,exportar')->name('warnings.download');
    Route::post('/warnings/{warning}/signed', [WarningController::class, 'uploadSigned'])->middleware('permission:okr,generar_warning')->name('warnings.signed');
    // 15-oct-2026 — anulación auditada de documentos (el PDF y el folio se conservan).
    Route::post('/warnings/{warning}/void', [WarningController::class, 'void'])->middleware('permission:okr,anular_documentos')->name('warnings.void');
    Route::post('/commitment-letters/{letter}/void', [CommitmentLetterController::class, 'void'])->middleware('permission:okr,anular_documentos')->name('commitment-letters.void');

    Route::get('/{objective}', [ObjectiveController::class, 'show'])->name('show');
    Route::get('/{objective}/activation-check', [ObjectiveController::class, 'activationCheck'])->middleware('permission:okr,activar')->name('activation-check');
    Route::post('/{objective}/activate', [ObjectiveController::class, 'activate'])->middleware('permission:okr,activar')->name('activate');
    Route::put('/{objective}/accountable', [CommitmentLetterController::class, 'assignAccountable'])->middleware('permission:okr,emitir_carta')->name('accountable.update');
    Route::get('/{objective}/placement-weeks/{week}', [PlacementUploadController::class, 'breakdown'])->whereNumber('week')->name('placement-weeks.breakdown');
    Route::post('/{objective}/refresh', [ObjectiveController::class, 'refresh'])->middleware('permission:okr,registrar_seguimiento')->name('refresh');
    Route::put('/{objective}/goal', [ObjectiveController::class, 'updateGoal'])->middleware('permission:okr,editar_borradores')->name('goal.update');
    Route::put('/{objective}/weights', [ObjectiveController::class, 'updateWeights'])->middleware('permission:okr,editar_borradores')->name('weights.update');
    Route::delete('/{objective}', [ObjectiveController::class, 'destroy'])->name('destroy');

    Route::post('/{objective}/key-results', [KeyResultController::class, 'store'])->middleware('permission:okr,configurar_kr')->name('key-results.store');
    Route::post('/{objective}/key-results/complete', [KeyResultController::class, 'complete'])->middleware('permission:okr,configurar_kr')->name('key-results.complete');
    Route::put('/{objective}/key-results/{keyResult}', [KeyResultController::class, 'update'])->middleware('permission:okr,configurar_kr')->name('key-results.update');
    Route::delete('/{objective}/key-results/{keyResult}', [KeyResultController::class, 'destroy'])->middleware('permission:okr,configurar_kr')->name('key-results.destroy');

    Route::post('/{objective}/check-ins', [CheckInController::class, 'store'])->middleware('permission:okr,registrar_seguimiento')->name('check-ins.store');
    Route::post('/{objective}/evidences', [EvidenceController::class, 'store'])->middleware('permission:okr,cargar_evidencia')->name('evidences.store');
    Route::post('/{objective}/placement-uploads', [PlacementUploadController::class, 'store'])->middleware('permission:okr,cargar_colocacion')->name('placement-uploads.store');
    Route::get('/{objective}/commitment-letter/preview', [CommitmentLetterController::class, 'preview'])->middleware('permission:okr,revisar_carta')->name('commitment-letter.preview');
    Route::get('/{objective}/commitment-letter/review', [CommitmentLetterController::class, 'review'])->middleware('permission:okr,revisar_carta')->name('commitment-letter.review');
    Route::post('/{objective}/commitment-letter', [CommitmentLetterController::class, 'generate'])->middleware('permission:okr,emitir_carta')->name('commitment-letter.generate');
    Route::get('/{objective}/warnings/eligibility', [WarningController::class, 'eligibility'])->middleware('permission:okr,generar_warning')->name('warnings.eligibility');
    Route::post('/{objective}/warnings', [WarningController::class, 'store'])->middleware('permission:okr,generar_warning|autorizar_warning')->name('warnings.store');
});

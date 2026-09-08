<?php

use App\Http\Controllers\Okr\AlertController;
use App\Http\Controllers\Okr\CheckInController;
use App\Http\Controllers\Okr\DashboardController;
use App\Http\Controllers\Okr\EvidenceController;
use App\Http\Controllers\Okr\HistoryController;
use App\Http\Controllers\Okr\KeyResultController;
use App\Http\Controllers\Okr\KpiController;
use App\Http\Controllers\Okr\ObjectiveController;
use Illuminate\Support\Facades\Route;

/**
 * Módulo OKR (08-sep-2026) — incluido desde routes/web.php dentro del MISMO
 * grupo ['auth', 'verified'] que el resto de Reportería (nunca autenticación
 * aparte). Convención de nombres: prefijo 'okr.' (sección 20 del pedido).
 */
Route::prefix('okr')->name('okr.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/create', [ObjectiveController::class, 'create'])->name('create');
    Route::post('/', [ObjectiveController::class, 'store'])->name('store');
    Route::get('/history', [HistoryController::class, 'index'])->name('history');
    Route::get('/kpis', [KpiController::class, 'index'])->name('kpis.index');
    Route::post('/kpis', [KpiController::class, 'store'])->name('kpis.store');
    Route::put('/kpis/{kpi}', [KpiController::class, 'update'])->name('kpis.update');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/{alert}/read', [AlertController::class, 'markRead'])->name('alerts.read');
    Route::get('/evidences/{evidence}/download', [EvidenceController::class, 'download'])->name('evidences.download');

    Route::get('/{objective}', [ObjectiveController::class, 'show'])->name('show');
    Route::post('/{objective}/activate', [ObjectiveController::class, 'activate'])->name('activate');
    Route::post('/{objective}/refresh', [ObjectiveController::class, 'refresh'])->name('refresh');
    Route::put('/{objective}/goal', [ObjectiveController::class, 'updateGoal'])->name('goal.update');
    Route::delete('/{objective}', [ObjectiveController::class, 'destroy'])->name('destroy');

    Route::post('/{objective}/key-results', [KeyResultController::class, 'store'])->name('key-results.store');
    Route::put('/{objective}/key-results/{keyResult}', [KeyResultController::class, 'update'])->name('key-results.update');
    Route::delete('/{objective}/key-results/{keyResult}', [KeyResultController::class, 'destroy'])->name('key-results.destroy');

    Route::post('/{objective}/check-ins', [CheckInController::class, 'store'])->name('check-ins.store');
    Route::post('/{objective}/evidences', [EvidenceController::class, 'store'])->name('evidences.store');
});

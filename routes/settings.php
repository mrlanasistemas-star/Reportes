<?php

use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    // Notificaciones (23-nov-2026): preferencias por tipo + suscripción Web Push del dispositivo.
    Route::get('settings/notificaciones', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
    Route::patch('settings/notificaciones', [NotificationSettingsController::class, 'updatePreferences'])->name('notification-settings.update');
    Route::post('settings/notificaciones/suscripcion', [NotificationSettingsController::class, 'subscribe'])->middleware('throttle:30,1')->name('notification-settings.subscribe');
    Route::delete('settings/notificaciones/suscripcion', [NotificationSettingsController::class, 'unsubscribe'])->middleware('throttle:30,1')->name('notification-settings.unsubscribe');
    Route::post('settings/notificaciones/prueba', [NotificationSettingsController::class, 'test'])->middleware('throttle:5,1')->name('notification-settings.test');
});

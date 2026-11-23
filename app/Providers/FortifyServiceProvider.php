<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

/**
 * Configura Laravel Fortify (autenticación): acciones de alta/restablecimiento,
 * login con candado de cuenta deshabilitada, vistas Inertia de autenticación y
 * límites de intentos de login y 2FA. El registro público está deshabilitado.
 */
class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Registra la respuesta de login personalizada (LoginResponse).
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Inicializa Fortify: acciones, vistas y límites de intentos.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configura las acciones de Fortify. El login valida usuario/contraseña y, si la
     * cuenta tiene `disabled_at`, lanza ValidationException con un mensaje en español
     * en lugar de autenticar.
     *
     * @throws ValidationException Si la cuenta está deshabilitada.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Mismo login de Fortify, más el candado de cuenta deshabilitada por un administrador.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where(Fortify::username(), $request->input(Fortify::username()))->first();
            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }
            if ($user->disabled_at !== null) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Tu cuenta está deshabilitada. Contacta a un administrador.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configura las vistas Inertia de Fortify (login, restablecer/olvidé contraseña,
     * verificar correo, reto 2FA y confirmar contraseña).
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        // registerView() eliminado (cierre 17-sep-2026, ronda 4) — Features::registration()
        // está deshabilitado en config/fortify.php (registro público quitado a petición del
        // usuario), así que esta vista nunca se sirve; se quitó junto con
        // resources/js/pages/auth/Register.vue (importaba una ruta Wayfinder que ya no
        // existe, rompía `npm run build`).

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configura límites de intentos: 2FA 5/min por sesión de login; login 5/min por
     * combinación usuario+IP.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}

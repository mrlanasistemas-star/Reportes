<?php

namespace App\Services\Notifications;

use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodRadiographyRun;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Reporteria\MonthlyPeriodCreated;
use App\Notifications\Reporteria\PeriodDatabaseUpdateFinished;
use App\Notifications\Reporteria\RadiographyGenerationFinished;
use App\Services\Access\ModuleAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Punto único para avisar el fin de los procesos de Reportería (23-nov-2026):
 * campana (BD) + Web Push (+ correo en el nuevo periodo). Todas las
 * notificaciones van en cola, y TODO método de esta clase atrapa cualquier
 * excepción y solo la registra en el log: un aviso fallido jamás debe
 * convertir un proceso exitoso en error ni romper el Job que lo llama.
 *
 * La usan los Jobs de actualización de BD del periodo y de generación de
 * radiografía, y el flujo de creación de periodos mensuales.
 */
class ReporteriaNotificationService
{
    /**
     * @param  ModuleAccessService  $modules  Permite saber qué usuarios tienen acceso a los módulos Carga/Periodos.
     */
    public function __construct(private readonly ModuleAccessService $modules) {}

    /**
     * ¿El usuario quiere el correo detallado de este tipo? (los Jobs lo consultan antes de enviar su Mailable).
     *
     * Consulta la preferencia del usuario en BD para el canal correo.
     *
     * @param  User|null  $user  Usuario destinatario; null → false.
     * @param  string  $type  Tipo de notificación (clave de preferencia).
     * @return bool true si el usuario permite el correo para ese tipo.
     */
    public function wantsMail(?User $user, string $type): bool
    {
        return $user !== null
            && UserNotificationPreference::allows($user, $type, UserNotificationPreference::CHANNEL_MAIL);
    }

    /**
     * Avisa al usuario que lanzó la lectura de archivos (actualización de BD
     * del periodo) que el proceso terminó, con éxito o con fuentes fallidas.
     *
     * Envía la notificación PeriodDatabaseUpdateFinished (en cola) solo si el
     * usuario existe y está activo. Cualquier error se registra en el log y
     * no se propaga.
     *
     * @param  int|null  $userId  Usuario que inició el proceso; null → no se notifica.
     * @param  Period  $period  Periodo procesado.
     * @param  PeriodDatabaseUpdateRun  $run  Corrida de actualización.
     * @param  bool  $success  Si la corrida terminó correctamente.
     * @param  list<string>  $failedSources  Nombres de las fuentes que fallaron.
     */
    public function databaseUpdateFinished(?int $userId, Period $period, PeriodDatabaseUpdateRun $run, bool $success, array $failedSources = []): void
    {
        $this->safely('lectura de archivos', ['run_id' => $run->id, 'period_id' => $period->id], function () use ($userId, $period, $run, $success, $failedSources) {
            $user = $this->activeUser($userId);
            if (! $user) {
                return;
            }

            $user->notify(new PeriodDatabaseUpdateFinished(
                runId: $run->id,
                periodId: $period->id,
                periodName: (string) $period->label,
                success: $success,
                failedSources: array_values(array_map('strval', $failedSources)),
            ));
        });
    }

    /**
     * Avisa al usuario el fin de la generación del reporte (radiografía).
     *
     * Si tuvo éxito y hay URL de resultado, el enlace apunta a ella (convertida
     * a ruta relativa); en otro caso apunta al histórico general del periodo.
     * Envía RadiographyGenerationFinished (en cola) solo a usuarios activos;
     * los errores solo se registran en el log.
     *
     * @param  int|null  $userId  Usuario que solicitó el reporte.
     * @param  Period  $period  Periodo del reporte.
     * @param  PeriodRadiographyRun|null  $run  Corrida de generación (para id y alcance).
     * @param  bool  $success  Si la generación terminó correctamente.
     * @param  string|null  $successUrl  URL del reporte generado.
     */
    public function radiographyFinished(?int $userId, Period $period, ?PeriodRadiographyRun $run, bool $success, ?string $successUrl = null): void
    {
        $this->safely('generación de reporte', ['run_id' => $run?->id, 'period_id' => $period->id], function () use ($userId, $period, $run, $success, $successUrl) {
            $user = $this->activeUser($userId);
            if (! $user) {
                return;
            }

            $url = $success && $successUrl
                ? $this->relative($successUrl)
                : route('historico-general.index', ['period_id' => $period->id], false);

            $user->notify(new RadiographyGenerationFinished(
                runId: $run?->id,
                periodName: (string) $period->label,
                success: $success,
                url: $url,
                scopeLabel: $this->scopeLabel($run),
            ));
        });
    }

    /**
     * Nuevo mes operativo: quien lo creó + usuarios con acceso a Carga o Periodos.
     *
     * Envía MonthlyPeriodCreated (en cola) a todos los destinatarios. Los
     * errores solo se registran en el log.
     *
     * @param  Period  $period  Periodo mensual recién creado.
     * @param  User|null  $creator  Usuario que lo creó (null si fue automático).
     */
    public function monthlyPeriodCreated(Period $period, ?User $creator): void
    {
        $this->safely('nuevo periodo', ['period_id' => $period->id], function () use ($period, $creator) {
            $recipients = $this->periodRecipients($creator);
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new MonthlyPeriodCreated(
                periodId: $period->id,
                periodName: (string) $period->label,
                createdByName: $creator?->name,
            ));
        });
    }

    /**
     * Calcula los destinatarios del aviso de nuevo periodo: usuarios activos
     * (sin `disabled_at`) que sean el creador o tengan acceso al módulo
     * "carga" o "periodos".
     *
     * Carga todos los usuarios activos en memoria y filtra en PHP.
     *
     * @param  User|null  $creator  Usuario creador del periodo.
     * @return Collection<int, User>
     */
    public function periodRecipients(?User $creator): Collection
    {
        $users = User::query()->whereNull('disabled_at')->get()
            ->filter(fn (User $u) => ($creator && $u->id === $creator->id)
                || $this->modules->allows($u, 'carga')
                || $this->modules->allows($u, 'periodos'));

        return $users->values();
    }

    /**
     * Busca al usuario y lo devuelve solo si existe y no está deshabilitado.
     *
     * @param  int|null  $userId  ID del usuario.
     * @return User|null Usuario activo, o null.
     */
    private function activeUser(?int $userId): ?User
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user && $user->disabled_at === null ? $user : null;
    }

    /**
     * Convierte una URL absoluta en ruta relativa (ruta + query), para que el
     * enlace de la notificación funcione sin depender del dominio.
     *
     * @param  string  $url  URL absoluta o relativa.
     * @return string Ruta relativa (mínimo "/").
     */
    private function relative(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? "{$path}?{$query}" : $path;
    }

    /**
     * Etiqueta en español del alcance de la corrida de radiografía.
     *
     * @param  PeriodRadiographyRun|null  $run  Corrida; null → null.
     * @return string|null "Sucursal", "Colaborador" o "General".
     */
    private function scopeLabel(?PeriodRadiographyRun $run): ?string
    {
        if (! $run) {
            return null;
        }

        return match ($run->scope ?: 'general') {
            'branch' => 'Sucursal',
            'employee' => 'Colaborador',
            default => 'General',
        };
    }

    /**
     * Ejecuta el envío atrapando cualquier Throwable y registrándolo como
     * warning en el log, para que un aviso fallido nunca afecte al proceso.
     *
     * @param  string  $what  Descripción del aviso (para el mensaje de log).
     * @param  array  $context  Contexto adicional para el log (ids de corrida/periodo).
     * @param  \Closure  $callback  Lógica de envío.
     */
    private function safely(string $what, array $context, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning("Notificaciones: no se pudo enviar el aviso de {$what} (el proceso no se ve afectado).", $context + [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }
}

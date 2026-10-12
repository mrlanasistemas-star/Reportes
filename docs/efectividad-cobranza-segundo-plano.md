# Efectividad de Cobranza — procesamiento en segundo plano

Documento técnico del flujo de generación en cola (Laravel Queue) del módulo
`/efectividad-cobranza`. Las fórmulas y la calculadora aprobada (VBA) **no cambiaron**;
todo lo descrito aquí es orquestación, seguimiento, diagnóstico y notificación.

## 1. Causa del seguimiento perdido (antes)

- La página solo cargaba la última ejecución cuando la URL traía `period_id`. Al volver desde
  el menú (sin query string), `latestRun` llegaba en `null`.
- El ID del trabajo vivía solo en una variable de Vue (`run.value`) y el polling era un
  `setInterval` local: al cambiar de módulo, el componente se destruía y el rastro se perdía.
- **El Job sí continuaba en el worker** (ya era `ShouldQueue` sobre la cola `database`); lo que
  se perdía era la referencia en pantalla. Además, con `$tries = 2`, un primer intento fallido
  se marcaba `failed` y luego "resucitaba" con el reintento.

## 2. Flujo actual

1. `POST /efectividad-cobranza/generar` → dentro de `Cache::lock()` reutiliza una ejecución
   activa de la misma identidad (periodo + sucursal) o crea una nueva (`status=queued`).
   Quien pide lo mismo mientras está activa queda en `subscriber_ids` (también se le notifica).
2. `Bus::dispatch()` devuelve el id del registro en `jobs` → se guarda en `queue_job_id`.
   Si la cola no acepta el trabajo → la ejecución queda `failed` (`EC-TEC-COLA-NO-DISPONIBLE`)
   y la respuesta es **503 con `accepted=false`**: la UI nunca muestra la confirmación.
3. El worker ejecuta `GenerateEfectividadCobranzaAprobadaJob` por etapas, persistiendo en BD
   etapa, avance y última actividad.
4. La UI (`Index.vue`) recibe del servidor, en cada visita: `focusRun` (detalle), `activeRuns`
   (activas del usuario, sin importar el periodo seleccionado) e `history` (paginado). El
   polling solo refresca lo que ya está en BD.
5. Al terminar: notificación interna (`notifications`) + correo, cada uno idempotente.

## 3. Etapas reales (`App\Enums\EfectividadCobranzaStage`)

| # | Clave | Qué ocurre | Avance |
|---|---|---|---|
| 1 | `validate_files` | `report_uploads` / `process_runs` del corte (Saldos) y del periodo (Ingresos), archivo físico, cobertura de fechas | indeterminado |
| 2 | `verify_columns` | Encabezados del archivo físico (OpenSpout, primeras 30 filas) contra `ColumnMapService` + conteo agregado de vacíos en BD + `importer_version` | indeterminado |
| 3 | `prepare_portfolio` | Lectura de CARTERA del adaptador aprobado | **medible** (registros) |
| 4 | `process_income` | Lectura del histórico de INGRESOS y regla de pagos válidos | **medible** (registros) |
| 5 | `calculate` | Calculadora aprobada (una sola llamada) | indeterminado |
| 6 | `reconcile` | Conciliación **interna**: promotores = oficinas = GRAN TOTAL. No es comparación externa | indeterminado |
| 7 | `build_excel` | Exportador existente → disco privado `local` | indeterminado |
| 8 | `finish` | Persistencia del resultado | — |

El avance de las etapas 3-4 se escribe cada `EFECTIVIDAD_PROGRESS_EVERY_ROWS` registros o
`EFECTIVIDAD_PROGRESS_EVERY_SECONDS` segundos (nunca por registro). El porcentaje solo se
muestra en esas etapas; el resto usa un indicador indeterminado + "Paso N de 8".

## 4. Estados

Persistidos (`efectividad_cobranza_runs.status`): `queued`, `running`, `retrying`, `success`,
`blocked`, `failed`.

Visibles (derivados por `EfectividadCobranzaRunHealthService`): En cola · Procesando ·
Reintentando · **Retrasado** · **Sin actividad del worker** · Bloqueado por datos · Error ·
Terminado · **Obsoleto** (existe una versión más reciente de la misma identidad, o cambió la
huella de las fuentes).

## 5. Incidencias

Forma única (`EfectividadCobranzaIssue::make`): `code`, `severity` (critical/warning/info),
`category`, `message`, `source`, `file`, `upload_id`, `period`, `sheet`, `original_header`,
`expected_column`, `affected_rows` (siempre `null`: los importadores no guardan número de
fila de origen — no se inventa), `record_count`, `action`.

Categorías: `technical`, `missing_data`, `missing_columns`, `partial_coverage`,
`incompatible`, `stale`, `file_missing`, `worker_stalled`.

- **Bloqueo** (`blocked`) = decisión de la cobertura del adaptador aprobado (`sin_datos`,
  `sin_cartera`, `bloqueado_sin_fechas`). `failure_kind` explica la causa
  (`missing_data`, `missing_columns`, `incompatible`, `stale`).
- **Error técnico** (`failed`) = excepción, timeout, conciliación interna inconsistente,
  worker perdido. El usuario ve un mensaje comprensible; la excepción completa y el stack
  trace quedan solo en `storage/logs`. Los administradores ven además la clase de la
  excepción (`technical_reference`).

Caso real detectado en la BD local (Junio 2026): los archivos físicos **sí** traen
`Fecha cuota`, `Estatus`, etc., pero los registros se importaron con una versión anterior del
importador (`importer_version` nulo). Diagnóstico: `EC-DATA-REPROCESAR` (categoría `stale`) →
"Vuelve a procesar el archivo… (no es necesario volver a subirlo)".

## 6. Trabajos atascados

- `queued` > `EFECTIVIDAD_QUEUED_DELAY_MINUTES` → **Retrasado** si el Job sigue en `jobs`
  (ningún worker lo toma); **Sin actividad del worker** si ya no está en la cola.
- `running`/`retrying` sin heartbeat > `EFECTIVIDAD_HEARTBEAT_STALE_MINUTES` → **Retrasado**
  si el Job sigue reservado/pendiente; **Sin actividad del worker** solo si desapareció de
  `jobs` (o está en `failed_jobs`). Nunca se declara muerto solo por tiempo.
- Ejecuciones sin `queue_job_id` (anteriores a este cambio) o drivers distintos de `database`:
  estado de cola `unknown` → como máximo **Retrasado**.

Recuperación segura:

```bash
php artisan efectividad:recover-stalled            # solo diagnóstico
php artisan efectividad:recover-stalled --apply    # cierra huérfanos como failed (worker_stalled) y notifica
php artisan efectividad:recover-stalled --apply --requeue   # re-encola la MISMA fila (sin duplicar)
```

Agendado: `efectividad:recover-stalled --apply` cada 10 min (nunca re-encola solo).

## 7. Notificaciones

- **Interna**: canal `database` de Laravel (tabla `notifications`), campana en la cabecera
  (`BackgroundActivityBell.vue`). Endpoints: `GET /notificaciones`,
  `POST /notificaciones/{id}/leida`, `POST /notificaciones/leidas`.
- **Correo**: `App\Mail\EfectividadCobranzaRunMail` (asuntos: "Reporte disponible",
  "Se requiere corregir información", "No fue posible completar el reporte"). Sin adjuntos;
  enlace a `/efectividad-cobranza/runs/{id}` (requiere sesión).
- Idempotencia: `UPDATE … WHERE notification_status IS NULL OR = 'failed'` (reclamo atómico)
  + `metadata.mail_notified_user_ids` por destinatario + `in_app_notified_at`.
- SMTP sin configurar o caído: el reporte queda intacto; `notification_status=failed` con
  motivo; `efectividad:retry-notifications` (agendado cada 15 min, máx.
  `EFECTIVIDAD_NOTIFICATION_MAX_ATTEMPTS`). `sent` solo cuando el transportador aceptó el
  mensaje. Nunca notifica retroactivamente ejecuciones históricas.

## 8. VPS (no se tocó ningún servicio)

Worker con Supervisor (ejemplo):

```ini
[program:reportes-worker]
command=php /var/www/reportes/artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=1800 --memory=1024
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=1900
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/reportes/storage/logs/worker.log
```

Equivalente systemd: `Restart=always`, `TimeoutStopSec=1900`, mismo `ExecStart`.

Requisitos:

- `DB_QUEUE_RETRY_AFTER` (1900) **mayor** que `EFECTIVIDAD_JOB_TIMEOUT` (900) y que
  `--timeout` del worker. El `$timeout` del Job manda sobre el del worker.
- Extensión `pcntl` (Linux) para que el timeout se aplique y `failed()` se ejecute al
  excederlo. En Windows/WAMP no hay `pcntl`: un Job colgado se detecta por el health check.
- Cron del scheduler (`* * * * * php artisan schedule:run`).
- `php artisan queue:restart` después de cada despliegue.
- SMTP: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`; `APP_URL` con el dominio real (los enlaces del
  correo se construyen con él).
- Migraciones aditivas: `2026_10_12_120000_add_tracking_fields_to_efectividad_cobranza_runs_table`,
  `2026_10_12_120100_create_notifications_table`.

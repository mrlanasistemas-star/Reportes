# Despliegue — workers, scheduler y requisitos de runtime

Documenta lo que este trabajo requiere para correr en el VPS. No se modificó ninguna
configuración de producción desde este entorno de desarrollo — esto es documentación, no un
cambio de infraestructura.

## 1. Colas (queue worker)

Ya existía (`composer.json`, script `worker`):

```bash
php -d memory_limit=1024M -d max_execution_time=0 artisan queue:work database \
    --queue=default --tries=1 --timeout=1800 --memory=1024 --sleep=3 -vvv
```

`QUEUE_CONNECTION=database` (ver `.env`). La API (`/api/v1`) sigue siendo síncrona (lectura).

**Nuevo (cierre Efectividad de Cobranza Aprobada, 2026-10-11):**
`App\Jobs\GenerateEfectividadCobranzaAprobadaJob` — generado por el módulo web
"Efectividad de Cobranza" (`/efectividad-cobranza`), corre en la misma cola `database` que el
resto del proyecto (ningún worker/cola adicional que configurar). Usa `WithoutOverlapping` por
periodo+sucursal — dos clics en "Generar reporte" para la misma combinación nunca corren en
paralelo. Ver `docs/efectividad-cobranza-aprobada.md` sección 1 para el detalle completo.

Debe seguir corriendo exactamente un worker por servidor (o varios con cuidado de
concurrencia) vía `supervisor`/`systemd` — la configuración real del VPS no se tocó ni se
verificó desde este entorno de desarrollo (no hay acceso a él).

## 2. Scheduler

`routes/console.php` ya registra (sin cambios en esta sesión):

```
okr:refresh     diario 06:00
okr:close-due   diario 06:15
```

Requiere el cron de Laravel estándar en el VPS:

```
* * * * * php /ruta/al/proyecto/artisan schedule:run >> /dev/null 2>&1
```

## 3. Variables de entorno nuevas de esta sesión

| Variable | Dónde | Por qué |
|---|---|---|
| (Sanctum no agrega variables de entorno nuevas — usa `personal_access_tokens`) | — | — |
| `BROWSERSHOT_NODE_BINARY` | ya existía en `.env.example`, estaba vacía en este `.env` local | se completó para poder VERIFICAR Browsershot real en este entorno (ver `docs/okr-documentos-browsershot.md`) — en el VPS debe apuntar al `node` real de ese servidor |

Ninguna variable nueva se agregó a `.env.example` — las necesarias (Browsershot) ya estaban
documentadas ahí desde antes de esta sesión.

## 4. Dependencias nuevas de esta sesión

- `laravel/sanctum` (composer) — API tokens para `/api/v1`. Requiere migrar
  `personal_access_tokens` (incluida en las migraciones aditivas de esta sesión).
- Ninguna dependencia de Node nueva — Puppeteer ya estaba declarado.

## 5. Extensiones PHP

Sin cambios — no se requirió ninguna extensión PHP adicional para lo implementado en esta
sesión (Sanctum y las nuevas tablas usan solo Eloquent/DB estándar).

## 6. Migraciones (todas aditivas)

```
2026_10_11_011757_create_personal_access_tokens_table   (Sanctum, paquete oficial)
2026_10_11_011801_create_api_consumers_table
2026_10_11_011802_create_employee_external_identities_table
2026_10_11_011803_create_api_query_logs_table
2026_10_11_071254_add_branch_access_mode_to_api_consumers_table   (Fase 2.5, corrección de seguridad)
2026_10_11_225022_add_efectividad_aprobada_fields_to_fact_recoveries_table
2026_10_11_225023_add_efectividad_aprobada_fields_to_fact_portfolios_table
2026_10_11_230756_create_efectividad_cobranza_runs_table
```

Ninguna modifica ni borra una tabla existente. La última agrega la columna
`branch_access_mode` con backfill automático que PRESERVA el comportamiento de cualquier
`ApiConsumer` ya creado (ver `docs/seguridad-fase2-correcciones.md` punto 2.5) — portable
entre MySQL/MariaDB y SQLite (backfill en PHP, no `JSON_LENGTH`). Correr
`php artisan migrate --force` en el VPS es seguro y no destructivo.

## 7. Nuevo comando agendado

```
api:query-logs:prune   diario 03:00   (retención API_QUERY_LOG_RETENTION_DAYS, default 90)
```

## 7. Verificación posterior al despliegue (checklist)

- [ ] `php artisan migrate --force` corrió sin errores.
- [ ] `php artisan route:list --path=api` muestra las 16 rutas `/api/v1/*`.
- [ ] `GET /api/v1/health` responde 200 sin autenticación.
- [ ] `php artisan api:consumers:create ...` emite un token y `GET /api/v1/branches` con ese
      token responde 200.
- [ ] Worker de colas sigue corriendo (sin cambios de esta sesión, pero confirmar que el
      despliegue no lo mató).
- [ ] Scheduler (`okr:refresh`/`okr:close-due`) sigue corriendo a las 06:00/06:15.
- [ ] `BROWSERSHOT_NODE_BINARY`/`BROWSERSHOT_CHROME_PATH` apuntan a binarios reales del VPS
      (Linux) — **no verificado desde este entorno de desarrollo (Windows)**, ver
      `docs/okr-documentos-browsershot.md` punto 5.

## 8. Respaldos y recuperación

Sin cambios de esta sesión — las migraciones son aditivas y reversibles
(`php artisan migrate:rollback`), así que un respaldo estándar de base de datos antes de
desplegar basta como red de seguridad. No se tocó ningún proceso de respaldo existente.

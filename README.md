# Reportería MR LANA

Sistema interno de MR LANA para producir la **radiografía financiera mensual** de la operación
(colocación, recuperación, cartera, mora, OPEX, nómina y EBITDA por sucursal, por colaborador y
consolidado) a partir de los archivos que exportan Lendus, el ERP y NOI.

Además de la radiografía incluye:

- **Carga de archivos** por periodo, con lectura en segundo plano y revisión opcional de gastos.
- **Periodos** de cuatro semanas (lunes a domingo) agrupados en meses operativos, bimestres,
  trimestres, semestres y años.
- **Estado de Resultados** gerencial (estados de cuenta bancarios conciliados contra el ERP,
  clasificación, ajustes, aprobación con versiones, exportes Excel y PDF).
- **Efectividad de cobranza** (calculadora temporal, metodología V12.9.4).
- **OKR** (objetivos y resultados clave por sucursal y por gestor, check-ins, Warnings y Cartas
  Compromiso en PDF).
- **Usuarios, roles y permisos** con alcance por sucursal.
- **API REST v1** de solo lectura para otros sistemas (Auditoría Operativa, MR. LANA PEOPLE).
- **Notificaciones** en la campana, por correo y Web Push (PWA instalable).

Todo el sistema (interfaz, mensajes, documentación y comandos) está en español. Está **en
producción** en un VPS Ubuntu.

> Documentación técnica completa (arquitectura, módulos, base de datos, API, despliegue,
> comandos, pendientes y glosario): **[`docs/MANUAL-DESARROLLADOR.md`](docs/MANUAL-DESARROLLADOR.md)**.
> Contrato OpenAPI de la API: [`docs/api-openapi.yaml`](docs/api-openapi.yaml).

---

## 1. Stack y versiones

Versiones tomadas de `composer.json` / `composer.lock` y `package.json` / `package-lock.json`
(23-nov-2026).

| Capa | Paquete | Versión |
|---|---|---|
| Lenguaje | PHP | `^8.3` (producción en **8.4**; **8.5 no sirve**: PhpSpreadsheet exige `<8.5.0`) |
| Framework | `laravel/framework` | 13.43 |
| Autenticación | `laravel/fortify` | 1.36 (registro público deshabilitado) |
| API | `laravel/sanctum` | 4.3 (tokens personales para `/api/v1`) |
| Puente SPA | `inertiajs/inertia-laravel` / `@inertiajs/vue3` | 3.0 (SSR **desactivado**) |
| Rutas tipadas | `laravel/wayfinder` / `@laravel/vite-plugin-wayfinder` | 0.1.23 / 0.1.3 |
| Excel | `maatwebsite/excel` + `phpoffice/phpspreadsheet`, `openspout/openspout` | 3.1.70 + 1.30.9, 5.3 |
| PDF (lectura) | `smalot/pdfparser` | 2.12 |
| PDF (generación) | `spatie/browsershot` + `puppeteer` (Chrome headless) | 5.4 + 25.18 |
| Web Push | `laravel-notification-channels/webpush` (`minishlink/web-push`) | 13.0 (11.0) |
| Frontend | Vue | 3.5 |
| Build | Vite | 8.3 |
| Estilos | Tailwind CSS 4.2 + componentes shadcn-vue (`reka-ui` 2.9), `lucide-vue-next` | — |
| Gráficas | ApexCharts (`vue3-apexcharts`) en la web; Chart.js / SVG en los PDF | 5.15 |
| Diálogos | SweetAlert2 | 11.26 |
| Pruebas PHP | Pest | 4.4 |
| Pruebas JS | Vitest (happy-dom) | 5.0 |
| Tipos | TypeScript / `vue-tsc` | 5.9 / 2.2 |
| Node | `engines.node` | `>= 22.12.0` (producción con Node 24 LTS) |
| Base de datos | MySQL 8 / MariaDB con **InnoDB** (SQLite solo para pruebas) | — |

## 2. Requisitos

- **PHP 8.3 o 8.4** (CLI y FPM con las mismas extensiones): `pdo_mysql`, `mbstring`, `intl`,
  `bcmath` o `gmp` (Web Push; `gmp` es más rápida), `zip`, `gd`, `xml`, `xmlreader`,
  `xmlwriter`, `dom`, `simplexml`, `curl`, `openssl`, `fileinfo`, `iconv`, `zlib`, `ctype`,
  `tokenizer`, `calendar` (Efectividad usa `cal_days_in_month`), `pcntl` (tiempo máximo real de
  los jobs; no existe en Windows) y, opcional, `redis` (phpredis).
- **Composer 2**.
- **Node.js ≥ 22.12** y npm. `puppeteer` es dependencia de **ejecución**: no borres
  `node_modules` ni instales con `--omit=dev`.
- **Google Chrome / Chromium** para Browsershot (lo descarga `npx puppeteer browsers install
  chrome`). En Linux requiere librerías del sistema (`libnss3`, `libgbm1`, `libasound2`,
  `fonts-liberation`, …; lista completa en el manual).
- **MySQL 8 / MariaDB** con motor **InnoDB**.
- Servidor SMTP para correos (invitaciones, contraseñas, avisos de procesos).
- En producción: Nginx, PHP-FPM, Supervisor (worker de colas), cron (scheduler) y **HTTPS**.

## 3. Instalación local paso a paso

```bash
# 1) Clonar
git clone <url-del-repositorio> Reportes
cd Reportes

# 2) Dependencias
composer install
npm ci

# 3) Entorno
cp .env.example .env
php artisan key:generate
```

Edita `.env` (las variables importantes se explican en la sección 4). Para desarrollo con MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reportes
DB_USERNAME=root
DB_PASSWORD=
DB_ENGINE=InnoDB
```

```sql
CREATE DATABASE reportes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
# 4) Base de datos + catálogos iniciales
php artisan migrate --seed
#   DatabaseSeeder ejecuta, en este orden (todos idempotentes):
#   - DataSourceSeeder  → fuentes de datos de carga (NOI, Lendus, ERP, fotografía de cartera…)
#   - BranchSeeder      → las 13 sucursales/rutas oficiales (branches)
#   - SucursalSeeder    → las 11 sucursales institucionales de OKR y branches.sucursal_id
#   - OkrKpiSeeder      → catálogo de KPI de OKR
#   Los roles de sistema (admin, gerencial, colaborador) y sus permisos por defecto los
#   insertan las propias migraciones. No se crea ningún usuario.

# 5) Primer administrador (no hay registro público ni comando propio)
php artisan tinker --execute="
\$u = new App\Models\User;
\$u->forceFill([
  'name' => 'Administrador',
  'email' => 'admin@ejemplo.mx',
  'password' => Illuminate\Support\Facades\Hash::make('cambia-esta-clave'),
  'role' => 'admin',
  'branch_scope' => 'all',
  'email_verified_at' => now(),
  'access_enabled_at' => now(),
])->save();"

# 6) Rutas tipadas de Wayfinder (el plugin de Vite también las genera al compilar)
php artisan wayfinder:generate --with-form

# 7) Frontend
npm run dev          # desarrollo (Vite en 127.0.0.1:5173, strictPort)
# o
npm run build        # compilación de producción en public/build

# 8) Servidor, cola y scheduler
composer run dev:full    # php artisan serve + queue:work + vite, todo junto
# o por separado:
php artisan serve
composer run worker      # queue:work database --timeout=3600 --tries=1 --memory=1024
php artisan schedule:work

# 9) PDF (opcional en local, obligatorio para OKR/Dashboard/radiografía en PDF)
npx puppeteer browsers install chrome
php artisan reports:pdf-health
```

Los demás usuarios se dan de alta desde **Usuarios**. Reciben un correo con su usuario y una
contraseña temporal que se les pide cambiar al entrar. En local, `email_verified_at` es
obligatorio porque las rutas exigen `verified`.

En WAMP sin `php artisan serve`, la app y la API responden en
`http://localhost/Reportes/public` (p. ej. `/api/v1/health`).

## 4. Variables de entorno importantes

| Variable | Valor recomendado | Para qué |
|---|---|---|
| `APP_NAME` | `"Reportes MR LANA"` | Nombre en títulos, correos y PWA. |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` en el VPS | Con `debug=false` los errores no muestran trazas. |
| `APP_URL` | dominio real con `https://` | Enlaces de correos, canonical, `sitemap.xml`, `robots.txt` y URL base de la API. |
| `APP_KEY` | `php artisan key:generate` | Cifra sesiones, archivos temporales de Efectividad y credenciales masivas. **No la cambies** en producción (cierra sesiones e invalida lo cifrado). |
| `GOOGLE_SITE_VERIFICATION` | código de Search Console | Meta de verificación (solo login es indexable). |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `warning` en producción | `.env.example` trae `single`/`debug`: crece sin límite y guarda detalle. |
| `LOG_DAILY_DAYS` | `14` | Rotación del log. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql` | Conexión. |
| `DB_ENGINE` | `InnoDB` | Fuerza InnoDB al crear tablas (claves foráneas, transacciones, `lockForUpdate`). |
| `SESSION_DRIVER` | `database` | Sesiones. |
| `SESSION_ENCRYPT` / `SESSION_SECURE_COOKIE` | `true` / `true` en producción | Sesión cifrada y cookie solo por HTTPS. |
| `QUEUE_CONNECTION` | `database` | **Mantener `database`**: pantallas y diagnósticos leen la tabla `jobs`. |
| `DB_QUEUE_RETRY_AFTER` | `3700` | Debe ser **mayor** que el `$timeout` más alto (3600 s de `ReprocessPeriodUploadsJob`). |
| `REDIS_QUEUE_RETRY_AFTER` | `3700` | Solo si la cola fuera Redis (no recomendado). |
| `CACHE_STORE` | `database` (o `redis`) | Caché de radiografía, comparativas y candados `Cache::lock()`. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_PREFIX` | `phpredis`, `127.0.0.1`, `6379`, … | Solo si se activa Redis para la caché. |
| `MAIL_MAILER` | `smtp` (**nunca `log`** en producción) | Con `log`, los correos con contraseñas quedan escritos en `storage/logs`. |
| `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | — | SMTP. En Laravel 13 `MAIL_ENCRYPTION` no existe: `null`/`smtp` = STARTTLS en 587, `smtps` = TLS en 465. |
| `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | `php artisan webpush:vapid --show` | Web Push. **Se generan una sola vez y no se cambian.** Sin ellas el push se omite (campana y correo siguen). |
| `BROWSERSHOT_NODE_BINARY`, `BROWSERSHOT_NPM_BINARY`, `BROWSERSHOT_CHROME_PATH` | rutas **absolutas** | Motor de PDF (`config/pdf.php`). Vacías = autodetección / `PUPPETEER_EXECUTABLE_PATH`. |
| `BROWSERSHOT_TIMEOUT`, `BROWSERSHOT_NO_SANDBOX`, `BROWSERSHOT_PDF_READY_TIMEOUT` | `180`, `true` con `www-data`, `20000` | Tiempo máximo, sandbox de Chrome y espera de `window.__PDF_READY__`. |
| `API_QUERY_LOG_RETENTION_DAYS` | `90` | Retención de `api_query_logs` (purga diaria 03:00). |
| `RADIOGRAFIA_PRECALENTAR`, `RADIOGRAFIA_PRECALENTAR_PERIODOS`, `RADIOGRAFIA_PRECALENTAR_REFRESCAR_HORAS`, `RADIOGRAFIA_PRECALENTAR_CRON` | `true`, `3`, `6`, `*/30 * * * *` | Mantiene caliente la corrida general de la radiografía que leen API, Dashboard, comparativo y OKR. |
| `RADIOGRAFIA_CACHE_TTL_HORAS`, `RADIOGRAFIA_CACHE_LOCK_ESPERA_SEGUNDOS`, `RADIOGRAFIA_CACHE_LOCK_SEGUNDOS` | `12`, `45`, `300` | Vigencia y candado de esa caché. No subir el TTL a días. |
| `EFECTIVIDAD_JOB_TIMEOUT`, `EFECTIVIDAD_QUEUED_DELAY_MINUTES`, `EFECTIVIDAD_HEARTBEAT_STALE_MINUTES`, `EFECTIVIDAD_NOTIFICATION_MAX_ATTEMPTS` | valores de `.env.example` | Del generador V4 en segundo plano, ya sin rutas. Hoy no afectan nada. |
| `FILESYSTEM_DISK` | `local` | Disco por omisión (privado, `storage/app/private`). |

## 5. Producción (resumen)

El procedimiento completo (paquetes, Nginx, PHP-FPM, Supervisor, cron, Browsershot, SMTP,
Redis, VAPID, mover cargas a disco privado, actualización y reversión) está en el
[manual, sección «Despliegue en el VPS»](docs/MANUAL-DESARROLLADOR.md#12-despliegue-en-el-vps).
En resumen:

1. Paquetes: Nginx, MySQL 8 (InnoDB), PHP 8.4-FPM con las extensiones, Node 24, Composer,
   Supervisor y librerías de Chrome.
2. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `.env` de
   producción, `php artisan migrate --seed --force`, crear el administrador.
3. Permisos de `storage/` y `bootstrap/cache` para `www-data`. **No ejecutar `storage:link`.**
4. Nginx con HTTPS, `root` en `public/`, `location ^~ /storage/ { return 404; }` y
   `client_max_body_size 140M`.
5. Supervisor: `queue:work database --queue=default --tries=1 --timeout=3600 --memory=1024`,
   `stopwaitsecs=3700`.
6. Cron: `* * * * * cd /var/www/reportes && php artisan schedule:run >> /dev/null 2>&1`.
7. `php artisan optimize`, `php artisan reportes:verificar-despliegue` (debe terminar en «Todo
   en orden») y `php artisan reports:pdf-health`.

Cada actualización: respaldo → `git pull` → `composer install` → `npm ci && npm run build` →
`migrate --force` → `optimize:clear && optimize` → `reload php8.4-fpm` → `queue:restart`.

## 6. Comandos clave

| Comando | Para qué |
|---|---|
| `composer run dev:full` | Servidor + cola + Vite en desarrollo. |
| `composer run worker` | Worker de colas con los parámetros de producción. |
| `php artisan reportes:run-database-update {period_id}` | «Actualizar BD» de un periodo desde consola. |
| `php artisan reportes:generar-radiografia {period_id}` | Genera la radiografía (summary, Excel, PDF) sin cola. |
| `php artisan reportes:rebuild-derived-noi {period_id}` | Recalcula roster, IMSS y rotación desde NOI. |
| `php artisan periods:generate-weekly {year} {month?}` | Genera/actualiza semanas. |
| `php artisan radiografia:precalentar` | Calienta la caché de la radiografía general. |
| `php artisan api:consumers:create "Nombre" codigo --ability=…` | Alta de consumidor de la API (el token se muestra una sola vez). |
| `php artisan api:consumers:revoke codigo --deactivate` | Revoca tokens y desactiva al consumidor. |
| `php artisan reportes:mover-cargas-a-privado --dry-run` | Mueve originales del disco público al privado (verifica SHA-256). |
| `php artisan reportes:verificar-despliegue` | Checklist posterior al despliegue. |
| `php artisan reports:pdf-health` | Diagnóstico de Node/Chrome/Browsershot. |
| `php artisan reportes:debug-queue-health` | Salud de la cola y runs activos. |
| `php artisan webpush:vapid --show` | Genera las claves VAPID (una sola vez). |

Catálogo completo (≈148 comandos, con tipo, si escriben en BD y si son seguros en producción):
[manual, sección «Catálogo de comandos»](docs/MANUAL-DESARROLLADOR.md#13-catálogo-de-comandos-artisan).

## 7. Pruebas

```bash
php artisan test                     # Pest sobre SQLite en memoria (suite principal, ~1,250 pruebas)
php artisan test --filter=Okr        # un subconjunto
npx vitest run                       # pruebas del frontend (resources/js/tests, Vitest + happy-dom)
npm run test:browser:okr             # pruebas en Chrome real de los inputs numéricos de OKR
npm run lint:check && npm run format:check && npm run types:check
composer run ci:check                # todo lo anterior + Pest
```

Suite de integración contra **MySQL real** (el SQL de la radiografía usa `REGEXP` y
`JSON_EXTRACT`, que SQLite no admite): copia `.env.testing.mysql.example` a `.env.testing.mysql`,
crea una base dedicada (`reportes_test`), migra con
`php artisan migrate --database=mysql_testing --force` y corre
`php artisan test --configuration=phpunit.integration.xml --testsuite=RadiographyIntegration`.
Detalle en el manual (sección «Pruebas»).

## 8. Qué debe considerar quien reciba el proyecto

- **InnoDB es obligatorio.** Los candados de doble clic (`lockForUpdate`), las transacciones y
  las ~150 claves foráneas no funcionan en MyISAM (el WAMP local suele traer MyISAM).
  Comprueba con `SELECT ENGINE, COUNT(*) FROM information_schema.TABLES WHERE
  TABLE_SCHEMA='reportes' GROUP BY ENGINE;`.
- **HTTPS** es requisito para Web Push, el service worker (PWA) y la cookie segura.
- **Claves VAPID**: se generan una sola vez; si cambian, todas las suscripciones push dejan de
  funcionar. Guárdalas en el respaldo de secretos junto con `APP_KEY`.
- **Colas**: `DB_QUEUE_RETRY_AFTER=3700`, worker con `--timeout=3600` y `stopwaitsecs=3700`.
  Si `retry_after` fuera menor que el timeout, la cola re-entregaría un job vivo (importaciones
  dobles). Ejecuta `php artisan queue:restart` en cada despliegue.
- **Cargas en disco privado**: los originales (NOI, Lendus, ERP, cartera, estados de cuenta)
  viven en `storage/app/private` y solo se descargan por controladores con permiso y alcance.
  No ejecutes `storage:link` y bloquea `/storage/` en Nginx.
- **Redis es opcional** y solo se recomienda para la **caché** (`CACHE_STORE=redis`). La cola y
  las sesiones se quedan en `database`.
- **No versionar Excel, PDF ni datos reales.** El `.gitignore` excluye `*.pdf`, `*.xlsx`,
  `*.xlsm`, `*.xls`, `*.csv` de `docs/`, `documentos*/`, `storage/audit*/` y respaldos SQL. El
  historial de git **todavía contiene datos reales** (nóminas, estados de cuenta): hacer el
  repositorio privado y limpiar el historial está pendiente (ver manual, «Pendientes»).
- **Reglas de negocio sensibles — no se tocan sin validar cifras contra un periodo real**:
  recuperación (PAGO/DESCUENTO, seguros fuera, CRECE solo 30 %, unificación y condonación
  excluidas), colocación bruta (el seguro es informativo), OPEX ERP filtrado por sucursal y
  reclasificaciones a nómina, cartera vencida (DPD > 0, sin Aguascalientes), EBITDA único del
  Estado de Resultados, identidad de colaboradores (fusión por nombre canónico, rechazos de
  fusión), metodología V12.9.4 de Efectividad y fórmulas de OKR. La API, OKR, Excel y PDF leen
  **la misma** fuente (`RadiografiaExportService::buildSnapshot()` y
  `EstadoResultadosDatasetBuilder`); nunca dupliques un cálculo en otro lado.
- `php artisan migrate:fresh`, `employees:normalize --apply`, `reportes:branches --apply` y los
  comandos «No recomendado» del catálogo **borran o fusionan datos oficiales**: respaldo antes.
- Los logs pueden contener nombres completos de colaboradores: trátalos como dato personal.

# Instalación limpia en el VPS nuevo (Reportería + OKR + Efectividad V7)

> **Solo instrucciones.** Este documento no despliega nada. Instalación **desde cero**: no se
> migra la base anterior, ni usuarios, sesiones, tokens o importaciones viejas. El usuario
> volverá a cargar su información. **No borres la BD ni el servidor anterior** hasta validar
> el nuevo.

Stack (revisado contra el código el 15-oct-2026): **Laravel 13.2** (`composer.lock`),
**PHP 8.4** (`composer.json` pide `^8.3`; **8.5 no sirve** porque `phpoffice/phpspreadsheet`
exige `<8.5.0`), Nginx, MySQL 8 (InnoDB), Node.js 24 LTS (`package.json` →
`engines.node >= 22.12.0`; no hay `.nvmrc`), Composer 2, Supervisor, Laravel Scheduler
(cron), Puppeteer/Chrome (Browsershot), SMTP y Laravel Sanctum (API). Inertia SSR está
**desactivado** (`config/inertia.php`): no hace falta `build:ssr` ni el proceso SSR.

Rutas de ejemplo: proyecto en `/var/www/reportes`, usuario de servicio `www-data`, dominio
`reportes.mr-lana.com`. Ajústalas a las reales.

Orden obligatorio: paquetes → clon → Composer → `.env` → npm build → **BD + `migrate --seed`**
→ administrador → permisos de storage → caches → Nginx/SSL → workers → cron → validación.
Nginx no debe publicar el sitio antes de que la base esté migrada.

---

## 0. Paquetes del sistema (Ubuntu/Debian, referencia)

```bash
sudo apt install nginx mysql-server supervisor git unzip \
  php8.4-fpm php8.4-cli php8.4-common php8.4-mysql php8.4-mbstring php8.4-xml php8.4-zip \
  php8.4-gd php8.4-curl php8.4-intl php8.4-bcmath
# Node.js 24 (NodeSource o nvm) y Composer 2 según su guía oficial.
# Librerías que Chrome headless necesita:
sudo apt install libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libxkbcommon0 libxcomposite1 \
  libxdamage1 libxrandr2 libgbm1 libpango-1.0-0 libasound2 fonts-liberation
```

Comprueba que **CLI y FPM** tienen todas las extensiones (en los paquetes de Ubuntu/ondrej,
`calendar`, `ctype`, `fileinfo`, `iconv` y `tokenizer` vienen en `php8.4-common` y `pcntl` en
`php8.4-cli`; si alguna falta, instala su paquete):

```bash
for e in calendar ctype curl dom fileinfo filter gd iconv intl libxml mbstring openssl pcntl \
         pdo_mysql session simplexml tokenizer xml xmlreader xmlwriter zip zlib; do
  php -m | grep -qix "$e" || echo "FALTA (CLI): $e"; done
```

Para qué se usan: `calendar` (`cal_days_in_month` en `EfectividadV4Calculator`, lo usa la
calculadora de Efectividad); `zip`, `xml`, `xmlreader`, `xmlwriter`, `dom`, `simplexml`,
`gd`, `iconv` y `zlib` (PhpSpreadsheet/OpenSpout, `ZipArchive` en la exportación PNG de
Efectividad y `smalot/pdfparser`); `fileinfo` (Flysystem y Browsershot); `pcntl` (tiempo
máximo real de los jobs en `queue:work`); `mbstring`, `openssl`, `ctype`, `tokenizer` y
`session` (Laravel). `intl` y `bcmath` no se llaman directamente, pero se recomiendan para
los helpers de Laravel.

## 1. Clonar el repositorio

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/mrlanasistemas-star/Reportes.git reportes
sudo chown -R www-data:www-data /var/www/reportes
cd /var/www/reportes
```

> **Antes de clonar:** el repositorio (`JesusA2004/Reportes` redirige a
> `mrlanasistemas-star/Reportes`) es **público** y contiene nóminas, clientes, gastos e IMSS
> reales en `main` y en el historial. Con autorización del dueño, primero hazlo privado y
> retira los datos (`docs/seguridad-archivos-versionados.md`). Ya en privado, clona con una
> *deploy key* de solo lectura. Si clonas antes de la limpieza, borra del servidor
> `documentosEjemplo/ documentosJunio/ documentosMayo/ documentosColumnas/ docRadios/
> storage/audit/ storage/audit_before/` y `docs/efectividad/*.xlsm`. No están bajo `public/`,
> pero no deben quedarse en producción.

## 2. Dependencias PHP

```bash
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
```

## 3. Dependencias Node y compilación del frontend

```bash
sudo -u www-data npm ci
sudo -u www-data npm run build      # el plugin Wayfinder llama a `php artisan`: PHP debe estar en PATH
```

- Conviene hacer primero el paso 4 (`.env` con `APP_KEY`): el plugin Wayfinder arranca
  Laravel para generar `resources/js/routes`. Si el build falla por configuración, completa
  el `.env` y vuelve a compilar.
- **No borres `node_modules` después del build**, ni uses `npm ci --omit=dev`. `puppeteer` es
  dependencia de ejecución: Browsershot la necesita en `node_modules/` para generar los PDF
  de OKR, del Dashboard y de Efectividad. Su `postinstall` descarga Chrome en
  `~www-data/.cache/puppeteer` (paso 13).

## 4. Configurar `.env`

```bash
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

Valores mínimos (sin comillas sobrantes):

```dotenv
APP_NAME="Reportes MR LANA"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://reportes.mr-lana.com      # los enlaces de los correos usan este dominio

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reportes
DB_USERNAME=reportes
DB_PASSWORD=********
DB_ENGINE=InnoDB                          # fuerza InnoDB (claves foráneas reales)

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true                # solo HTTPS
CACHE_STORE=database                      # Cache::lock() protege "Generar" contra doble clic
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3700                 # > el $timeout más alto de los jobs (ReprocessPeriodUploadsJob = 3600 s)

LOG_CHANNEL=stack
LOG_STACK=daily                           # rota el log; .env.example trae `single` (crece sin límite)
LOG_DAILY_DAYS=14
LOG_LEVEL=warning                         # `debug` en producción guarda demasiado detalle

MAIL_MAILER=smtp                          # NUNCA `log`: escribiría en el log los enlaces de invitación
MAIL_SCHEME=null                          # Laravel 13: null/smtp = STARTTLS en 587; `smtps` = TLS directo en 465
MAIL_HOST=smtp.proveedor.mx
MAIL_PORT=587
MAIL_USERNAME=********
MAIL_PASSWORD=********
MAIL_FROM_ADDRESS="reportes@mr-lana.com"
MAIL_FROM_NAME="${APP_NAME}"

BROWSERSHOT_NODE_BINARY=/usr/bin/node
BROWSERSHOT_NPM_BINARY=/usr/bin/npm
BROWSERSHOT_CHROME_PATH=/var/www/.cache/puppeteer/chrome/linux-XXX/chrome-linux64/chrome
BROWSERSHOT_TIMEOUT=180
BROWSERSHOT_NO_SANDBOX=true               # normalmente necesario con www-data sin sandbox de usuario
BROWSERSHOT_PDF_READY_TIMEOUT=20000
```

Notas:

- `MAIL_ENCRYPTION` **no existe** en `config/mail.php` (Laravel 13), así que se ignora. Se
  usa `MAIL_SCHEME`.
- Las variables `EFECTIVIDAD_JOB_TIMEOUT`, `EFECTIVIDAD_QUEUED_DELAY_MINUTES`,
  `EFECTIVIDAD_HEARTBEAT_STALE_MINUTES` y `EFECTIVIDAD_NOTIFICATION_MAX_ATTEMPTS` eran del
  generador V4 en segundo plano. Hoy Efectividad es una **calculadora temporal síncrona**
  (`EfectividadCalculadoraController`) y las rutas de ese generador ya no están registradas.
  Puedes dejar los valores de `.env.example`; no afectan nada.
- Las rutas de Browsershot se leen en `config/pdf.php` (`pdf.browsershot.*`). Si
  `BROWSERSHOT_CHROME_PATH` está vacío, se usa `PUPPETEER_EXECUTABLE_PATH`.

## 5-7. Base de datos vacía, migraciones y seeders

MySQL 8 ya usa InnoDB por defecto. Déjalo fijo en `/etc/mysql/mysql.conf.d/mysqld.cnf`
(`[mysqld]` → `default_storage_engine=InnoDB`) y además pon `DB_ENGINE=InnoDB` en `.env`.

```sql
CREATE DATABASE reportes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'reportes'@'localhost' IDENTIFIED BY '********';
GRANT ALL PRIVILEGES ON reportes.* TO 'reportes'@'localhost';
```

Ejecútalo **antes** de publicar el sitio en Nginx (paso 10) y de arrancar los workers
(paso 11):

```bash
sudo -u www-data php artisan migrate --seed --force   # seeders: SOLO DataSourceSeeder, BranchSeeder, OkrKpiSeeder
```

Verificado de nuevo el 15-oct-2026 en una BD vacía de MySQL 8.4 con `DB_ENGINE=InnoDB`:
corrieron las 105 migraciones, con 69 tablas InnoDB. Los seeders crearon 12 fuentes de datos (incluida `lendus_saldos_cliente_foto`),
13 sucursales oficiales y 15 KPI de OKR. **No se crean usuarios.**

Comprobación:

```bash
php artisan migrate:status | tail -5
mysql -e "SELECT ENGINE, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='reportes' GROUP BY ENGINE;"
```

## 8. Administrador inicial

El registro público está deshabilitado (Fortify). Crea el primer admin desde consola:

No existe un comando de artisan para esto, así que se hace con `tinker`. La contraseña se
genera al azar, de modo que no quede en el historial del shell. Después se fija una propia
con «¿Olvidaste tu contraseña?», que exige SMTP funcionando (paso 14):

```bash
sudo -u www-data php artisan tinker --execute="
\$u = new App\Models\User;
\$u->forceFill([
  'name' => 'Administrador',
  'email' => 'admin@mr-lana.com',
  'password' => Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(40)),
  'role' => 'admin',
  'branch_scope' => 'all',
  'email_verified_at' => now(),
  'access_enabled_at' => now(),
])->save();"
```

`email_verified_at` es obligatorio porque las rutas exigen `verified`. `role = admin` ya da
alcance general y acceso a OKR; `branch_scope` y `access_enabled_at` se ponen solo para que el
registro quede completo. Si todavía no hay SMTP, usa `Hash::make('…')` con una clave temporal,
empieza la línea con un espacio (`HISTCONTROL=ignorespace`) y cámbiala en
*Configuración → Seguridad* al entrar. Los demás usuarios se dan de alta desde **Usuarios**:
reciben una invitación con un enlace de un solo uso.

## 9. Almacenamiento

```bash
# NO ejecutes `php artisan storage:link` (ver abajo).
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2770 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0660 {} \;
sudo chmod 640 .env && sudo chown www-data:www-data .env
```

| Ubicación | Qué guarda | Cómo se entrega |
|---|---|---|
| `storage/app/public/reports/` (disco `public`) | **originales cargados** (NOI, Lendus, cartera, gastos, IMSS) con nombre aleatorio de 40 caracteres | solo `ReportUploadController::download` (permiso `carga.descargar` + alcance general) |
| `storage/app/private/` (disco `local`) | PDF de OKR (cartas, warnings y firmados), evidencias OKR, cargas de colocación OKR, Excel/PDF de Efectividad | controladores OKR con `permission:okr,exportar` |
| `storage/app/private/efectividad-tmp/` | calculadora de Efectividad: contenido **cifrado** con `APP_KEY` (el Excel original se borra al leerlo), TTL 60 min | se purga con «Limpiar», al salir, cada 10 min (`efectividad:limpiar-temporales`) y las descargas usan `deleteFileAfterSend` |
| `storage/app/radiografias/`, `storage/app/audits/` | exportaciones de radiografía/Dashboard y auditorías | rutas con `permission:reportes,exportar` |
| `storage/logs/` | log de Laravel y del worker | nunca por web |

**Por qué no `storage:link`:** ninguna vista ni el frontend usan URLs públicas de
`/storage` (no hay `Storage::url()` ni `asset('storage/…')`). El enlace `public/storage`
solo serviría para exponer los originales del disco `public`. Sin el enlace, y con el bloqueo
de Nginx del paso 10, hay dos barreras. El disco `local` tiene `serve => true`, pero Laravel
solo entrega ese archivo con URL firmada, y la app no genera ninguna.

## 10. Nginx y SSL

```nginx
server {
    listen 80;
    server_name reportes.mr-lana.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name reportes.mr-lana.com;
    root /var/www/reportes/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/reportes.mr-lana.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/reportes.mr-lana.com/privkey.pem;

    client_max_body_size 140M;          # StoreReportUploadRequest permite hasta 128 MB (calculadora: 25 MB por archivo)
    server_tokens off;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    # Originales con información financiera y personal: nunca por URL pública.
    # TODO /storage/ (disco public = originales NOI/Lendus/cartera). Las descargas pasan por
    # controladores con permiso + alcance. (En Apache lo bloquea storage/app/public/.htaccess.)
    location ^~ /storage/ { return 404; }

    # Defensa extra por si alguien cambia `root` al directorio del proyecto por error.
    # (No bloquees por extensión .xlsx/.csv/.pdf: las exportaciones de Laravel terminan así,
    # p. ej. /reportes-mensuales/{id}/radiografia.xlsx o /dashboard/exportar.pdf.)
    location ~ /\.(?!well-known) { return 404; }       # .env, .git, .htaccess…
    location ~ ^/(bootstrap|database|documentos[^/]*|docRadios|docs|tests)/ { return 404; }
    # (/vendor/ NO se bloquea: public/vendor/chartjs es un recurso legítimo.)

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    error_page 404 /index.php;

    # Solo el front controller se ejecuta; cualquier otro .php da 404.
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }
    location ~ \.php$ { return 404; }
}
```

`/storage/{path}` también es una ruta interna de Laravel (disco `local` con `serve`). El bloqueo
de Nginx la corta, y no pasa nada porque la app no la usa.

```bash
sudo certbot --nginx -d reportes.mr-lana.com
sudo nginx -t && sudo systemctl reload nginx
```

PHP-FPM (`/etc/php/8.4/fpm/php.ini`; los valores de CLI van en `/etc/php/8.4/cli/php.ini`):

```ini
upload_max_filesize = 128M
post_max_size = 140M
memory_limit = 512M            ; el Dashboard sube a 512M por petición
max_execution_time = 300       ; igual que fastcgi_read_timeout (calculadora y exportaciones son síncronas)
expose_php = Off
opcache.enable = 1
```

Después: `sudo systemctl reload php8.4-fpm`.

## 11. Worker de colas (Supervisor)

`/etc/supervisor/conf.d/reportes-worker.conf`:

```ini
[program:reportes-worker]
command=php -d memory_limit=1024M -d max_execution_time=0 /var/www/reportes/artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=3600 --memory=1024
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3700
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/reportes/storage/logs/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

Jobs que hoy se encolan, según el código (`app/Jobs`, todos en la cola `default` de la
conexión `database`):

| Job | Quién lo lanza | `$timeout` / `$tries` |
|---|---|---|
| `UpdatePeriodDatabaseJob` | «Actualizar BD» (Histórico General) | 1800 s / 1 |
| `GenerateRadiographyJob` | generar radiografía | 1800 s / 3 |
| `WarmDashboardTrendCacheJob` | al terminar la radiografía | 300 s / 1 |
| `ReprocessReportUploadJob` | reprocesar un archivo | 1800 s / 1 |
| `ReprocessPeriodUploadsJob` | reprocesar un periodo | **3600 s** / 1 |
| correos `Reprocess*Mail` | `Mail::queue()` desde los dos anteriores | — |

El `$timeout` de cada job manda sobre `--timeout`. Por eso `DB_QUEUE_RETRY_AFTER` (3700) y
`stopwaitsecs` deben ser **mayores que 3600**: si no, la cola volvería a entregar un
`ReprocessPeriodUploadsJob` que sigue vivo y lo marcaría fallido. Con `numprocs=1` los jobs
corren uno tras otro.

Ya **no** usan la cola: la calculadora de Efectividad (síncrona y temporal), los PDF de OKR
(se generan en la petición con Browsershot) ni las invitaciones de usuario (`send()`
síncrono). `GenerateEfectividadCobranzaAprobadaJob`, `GenerateEfectividadAprobadaPeriodicaJob`
e `ImportPortfolioSnapshotJob` siguen en el código, pero ninguna ruta registrada los lanza.

Tras cada despliegue: `php artisan queue:restart`.

## 12. Scheduler

```bash
sudo crontab -u www-data -e
* * * * * cd /var/www/reportes && php artisan schedule:run >> /dev/null 2>&1
```

Agenda real (`routes/console.php`, 15-oct-2026): `okr:refresh` (06:00), `okr:close-due`
(06:15), `api:query-logs:prune` (03:00) y `efectividad:limpiar-temporales` (cada 10 min).
Este último borra los espacios cifrados de la calculadora que lleven más de 60 min y los
PDF/PNG de exportaciones interrumpidas. `efectividad:recover-stalled` y
`efectividad:retry-notifications` **ya no están agendados**: eran del generador V4 en segundo
plano. Revisa con `php artisan schedule:list`. La agenda usa `America/Mexico_City`, que está
fija en `config/app.php`; no depende de la zona horaria del VPS.

## 13. Browsershot (PDF de OKR)

```bash
cd /var/www/reportes
sudo -u www-data npx puppeteer browsers install chrome   # imprime la ruta del binario
```

Pon esa ruta en `BROWSERSHOT_CHROME_PATH`, `which node` en `BROWSERSHOT_NODE_BINARY` y
`which npm` en `BROWSERSHOT_NPM_BINARY` (se leen en `config/pdf.php`). Las rutas deben ser
**absolutas**. Si cambias el `.env` después de `config:cache`, vuelve a ejecutar
`php artisan config:cache`. Verificación:

```bash
sudo -u www-data php artisan reports:pdf-health   # Node, Chrome, chart.umd.js, Browsershot real y escritura en storage/app/radiografias
# y en la web: un PDF de OKR, el PDF del Dashboard y el PDF/PNG de la calculadora de Efectividad.
```

Browsershot genera: PDF de OKR (cartas y warnings), PDF del Dashboard y de la radiografía
(con `public/vendor/chartjs/chart.umd.js` incrustado) y PDF/PNG de la calculadora de
Efectividad.

**Fuentes Montserrat:** están en el repositorio
(`resources/fonts/montserrat/montserrat-latin-{400,500,600,700,800}-normal.woff2` y la
licencia `OFL.txt`). `resources/views/reports/partials/montserrat-font-face.blade.php` las
incrusta en base64 dentro del HTML del PDF, así que **no hay que instalarlas en el sistema**
y Chrome no necesita internet. `reportes:verificar-despliegue` comprueba que existan.
`fonts-liberation` (paso 0) solo es el respaldo `sans-serif`.

## 14. SMTP

Con el `.env` del paso 4:

```bash
sudo -u www-data php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Prueba SMTP Reportes', fn (\$m) => \$m->to('tu-correo@ejemplo.mx')->subject('Prueba'));"
```

Correos que envía el sistema: invitación de usuario y restablecimiento de contraseña
(síncronos; si el SMTP falla, la pantalla de Usuarios lo indica y se puede reenviar), aviso de
«Actualizar BD» y de radiografía (desde el worker), y avisos de reprocesamiento (en cola).
Con `MAIL_MAILER=log` no sale ningún correo y los enlaces para fijar contraseña quedarían
escritos en `storage/logs` (`reportes:verificar-despliegue` lo marca como AVISO).

## 15. Caches de producción

```bash
sudo -u www-data php artisan optimize        # = config:cache + event:cache + route:cache + view:cache
```

Equivale a los cuatro comandos por separado (`config:cache`, `route:cache`, `view:cache`,
`event:cache`). Después de cualquier cambio en `.env` o `config/`, ejecuta
`php artisan optimize:clear && php artisan optimize`. Con la configuración en caché, `env()`
fuera de `config/` devuelve `null`.

## 16. Validar la API

```bash
curl -s https://reportes.mr-lana.com/api/v1/health                      # 200 sin token
sudo -u www-data php artisan api:consumers:create "Sistema X" sistema-x --ability=collections.read --ability=periods.read
curl -s -H "Authorization: Bearer <TOKEN>" "https://reportes.mr-lana.com/api/v1/collections/effectiveness?period_id=<ID_SEMANA>"
curl -s -o /dev/null -w "%{http_code}\n" https://reportes.mr-lana.com/storage/reports/x.xlsx   # debe dar 404
```

## 17-20. Carga inicial y validación funcional (en orden)

1. Con el admin: **Periodos**. Crea los meses operativos y sus semanas lunes-domingo.
2. **Histórico General** (mes operativo): NOI, Gastos, Ingresos Cobranza, Saldos por Cliente
   mensual, etc. Para cada archivo, **Analizar** o **Actualizar BD**.
3. **Efectividad de Cobranza** (calculadora temporal, metodología V7): sube las carteras
   «Saldos por Cliente» (domingo base … domingo corte) y el «Ingresos Cobranza». Calcula la
   semana y comprueba que el resultado dice «Metodología V7». Descarga PNG y PDF. Los
   archivos no se guardan: «Limpiar archivos» borra el espacio temporal.
4. Compara una semana real contra la macro V7: misma fila de GRAN TOTAL (sano, atraso,
   vencido, cobranza total y %).
5. **Reportería**: genera la radiografía mensual y revisa indicadores, exportaciones y
   descargas de originales.
6. **OKR**: objetivos, KR, avances, evidencias, vista previa y PDF (Browsershot).
7. Sal del módulo durante una generación y regresa: el estado debe recuperarse.

Checklist final:

- [ ] `php artisan about` sin errores; `APP_DEBUG=false`.
- [ ] `supervisorctl status` muestra `RUNNING`; `storage/logs/worker.log` sin errores.
- [ ] `schedule:run` en cron (`php artisan schedule:list`).
- [ ] `/storage/reports/*` responde 404 y **no existe** `public/storage` (`ls -la public/`).
- [ ] `https://…/.env`, `https://…/.git/config` y `https://…/docRadios/` responden 404.
- [ ] `ls storage/app/private/efectividad-tmp/` queda vacío (salvo `exports/`) 60 min después
      de usar la calculadora.
- [ ] Log en `daily` y `LOG_LEVEL=warning`; `MAIL_MAILER` distinto de `log`.
- [ ] Correo de prueba recibido.
- [ ] PDF de OKR generado.
- [ ] Efectividad V7 de una semana real calculada y revisada contra la macro V7.
- [ ] `php artisan reportes:verificar-despliegue` termina en «Todo en orden».

## Qué NO hacer en esta instalación

- No restaurar un dump de la BD anterior ni copiar `storage/` del servidor viejo.
- No copiar `.env` del servidor viejo (contiene `APP_KEY`, sesiones y tokens anteriores).
- No ejecutar `migrate:fresh` sobre una BD con información ya cargada.
- No apagar ni borrar el servidor anterior hasta validar todos los puntos anteriores.

---

## 21. Actualizar una versión ya instalada (sin detener los demás servicios del VPS)

Solo toca `/var/www/reportes` y su base. No reinicies Nginx ni MySQL completos: basta con
recargar.

```bash
cd /var/www/reportes
# 0) Respaldo ANTES de cualquier cambio (base + storage privado + .env)
mysqldump --single-transaction --routines reportes > ~/respaldos/reportes_$(date +%F_%H%M).sql
tar czf ~/respaldos/storage_$(date +%F_%H%M).tgz storage/app/private storage/app/public storage/app/radiografias .env
chmod 600 ~/respaldos/*        # contienen nóminas, clientes y APP_KEY
git rev-parse HEAD > ~/respaldos/version_anterior.txt

# 1) Código y dependencias
sudo -u www-data php artisan down --retry=60          # opcional: aviso de mantenimiento
sudo -u www-data git pull --ff-only
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data npm ci && sudo -u www-data npm run build

# 2) Base de datos ANTES de servir la versión nueva (todas las migraciones son aditivas)
sudo -u www-data php artisan migrate --force

# 3) Cachés: SIEMPRE limpiar y regenerar (una caché de rutas vieja = 404 en módulos nuevos)
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize               # config, rutas, vistas y eventos

# 4) Que PHP-FPM y los workers lean el código nuevo (OPcache conserva el anterior)
sudo systemctl reload php8.4-fpm
sudo -u www-data php artisan queue:restart

sudo -u www-data php artisan up
sudo -u www-data php artisan reportes:verificar-despliegue   # debe terminar en «Todo en orden»
sudo -u www-data php artisan reports:pdf-health
```

Migraciones de esta versión (15-oct-2026), todas aditivas (solo crean tablas/columnas o
insertan permisos con `insertOrIgnore`; los `drop` existen únicamente en `down()`):
`2026_10_17_000001` atribución de colocación · `2026_10_17_000002` conciliaciones de sucursal ·
`2026_10_17_100000` campos de acceso de usuarios · `2026_10_17_100100` siembra de permisos por
acción · `2026_10_18_000001` evidencia de movimientos laborales · `2026_10_19_000001` un solo
documento OKR vigente · `2026_10_19_120000` alcance por sucursal. (Lista completa:
`php artisan migrate:status`.)

## 22. Verificación posterior al despliegue

1. `php artisan reportes:verificar-despliegue` → rutas críticas, menú lateral, cachés,
   migraciones, build de Vite, permisos de escritura, archivos sensibles, Montserrat y
   configuración. Sale con código 1 si algo crítico falla.
2. `php artisan reports:pdf-health` → Node, Chrome, Browsershot y escritura.
3. En el navegador, con el administrador: Dashboard (PDF y Excel), Usuarios, Roles y permisos,
   Colaboradores, Reportes mensuales (un comparativo), Efectividad (una semana) y OKR (abrir un
   objetivo y su Carta en el visor).
4. `curl -s -o /dev/null -w "%{http_code}\n" https://reportes.mr-lana.com/storage/reports/x.xlsx`
   → debe dar 403 o 404, nunca 200.
5. Con un usuario de alcance restringido (una sola sucursal): otra sucursal por URL directa
   debe responder 403 con mensaje.

## 23. Reversión

Las migraciones son aditivas: la versión anterior funciona con las tablas/columnas nuevas
presentes, así que **no hace falta** `migrate:rollback` (y no debe correrse sobre datos reales).

```bash
cd /var/www/reportes
sudo -u www-data php artisan down --retry=60
sudo -u www-data git checkout $(cat ~/respaldos/version_anterior.txt)
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan optimize
sudo systemctl reload php8.4-fpm && sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan up
```

Solo si hubo daño de datos: restaurar el `mysqldump` del paso 0 en una base NUEVA, revisarla y
después cambiar `DB_DATABASE` (nunca sobrescribir la base viva sin revisar).

## 24. Diagnóstico: «404» en Usuarios / Roles y permisos (u otro módulo nuevo)

Síntoma: el menú lateral muestra el módulo (el frontend ya es nuevo) pero al entrar da 404.
El código sí registra `/usuarios` y `/roles` (lo comprueba `tests/Feature/SidebarRoutesTest.php`);
la causa está en el servidor:

1. **Caché de rutas vieja** (`route:cache` de la versión anterior): `php artisan optimize:clear`
   y luego `php artisan optimize`.
2. **OPcache de PHP-FPM** con el código anterior (`opcache.validate_timestamps=0`):
   `sudo systemctl reload php8.4-fpm`.
3. **Migraciones pendientes** o **build sin compilar**: `php artisan reportes:verificar-despliegue`
   lo indica con la acción exacta.
4. `php artisan route:list --path=usuarios` debe mostrar `usuarios.index`; si no aparece, el
   `git pull` no trajo el código nuevo.

## 25. Base de datos: InnoDB obligatorio

Las protecciones de concurrencia (un solo documento vigente, cargas de colocación, cancelación
de procesos) se apoyan en transacciones y bloqueos de fila. Con MyISAM no existen: el código
añade candados entre procesos e índices únicos, pero la instalación del VPS **debe** usar
`DB_ENGINE=InnoDB` (sección 4) y `default_storage_engine=InnoDB` en MySQL. Comprobación:

```bash
mysql -e "SELECT ENGINE, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='reportes' GROUP BY ENGINE;"
```

## 26. Datos sensibles en el repositorio (requiere autorización)

Inventario completo, procedimiento (`git rm --cached`, `git filter-repo`, force push, clones y
caché de GitHub) y revisión de secretos en **`docs/seguridad-archivos-versionados.md`**.
En resumen: el repositorio es **público** y tiene 57 archivos con datos reales en `main`
(nóminas, clientes, gastos, IMSS, rotación, Efectividad y salidas de auditoría con nombres)
y otros 20 solo en el historial. No se encontraron secretos (`APP_KEY` o contraseñas) en el
código ni en el historial.

En el VPS, clona después de esa limpieza o borra esas carpetas del servidor (ver paso 1).

## 27. Logs y datos personales

- `.env.example` trae `LOG_STACK=single` y `LOG_LEVEL=debug`. En producción usa
  `daily` + `warning` (paso 4) para que el log rote y no guarde detalle innecesario.
- El código no registra contraseñas ni tokens. Pero algunos `Log::warning` sí escriben
  **nombres completos de colaboradores**: `RadiographySnapshotBuilder` (`normalized_name`,
  `matched_row_name`). Las excepciones SQL pueden incluir valores de las filas. Trata
  `storage/logs/` como dato personal: permisos `0660`, sin acceso web y sin copiarlo a
  tickets ni chats.
- Con `MAIL_MAILER=log` (valor de `.env.example`), los correos completos, con el enlace para
  fijar contraseña, quedan escritos en el log. En producción, siempre `smtp`.

## 28. Puntos del código que el despliegue no puede corregir (para desarrollo)

- `VerifyDeploymentCommand::checkConfig()` lee `config('services.browsershot.chrome_path')`,
  pero esa clave no existe: la ruta está en `config('pdf.browsershot.chrome_path')`. Con
  la configuración en caché, siempre muestra «Sin ruta explícita». Solo es un AVISO; la prueba
  real es `reports:pdf-health`.
- `VerifyDeploymentCommand` no avisa si existe el enlace `public/storage`. Revísalo a mano
  (checklist).

# Instalación limpia en el VPS nuevo (Reportería + OKR + Efectividad V4)

> **Solo instrucciones.** Este documento no despliega nada. Instalación **desde cero**: no se
> migra la base anterior, ni usuarios, sesiones, tokens o importaciones viejas. El usuario
> volverá a cargar su información. **No borres la BD ni el servidor anterior** hasta validar
> el nuevo.

Stack: PHP 8.4, Nginx, MySQL 8 (InnoDB), Node.js 24, Composer 2, Supervisor, Laravel
Scheduler (cron), Puppeteer/Chrome (Browsershot), SMTP y Laravel Sanctum (API).

Rutas de ejemplo: proyecto en `/var/www/reportes`, usuario de servicio `www-data`, dominio
`reportes.ejemplo.mx`. Ajústalas a las reales.

---

## 0. Paquetes del sistema (Ubuntu/Debian, referencia)

```bash
sudo apt install nginx mysql-server supervisor git unzip \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-zip php8.4-gd \
  php8.4-curl php8.4-intl php8.4-bcmath php8.4-calendar php8.4-pcntl
# Node.js 24 (NodeSource o nvm) y Composer 2 según su guía oficial.
# Librerías que Chrome headless necesita:
sudo apt install libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libxkbcommon0 libxcomposite1 \
  libxdamage1 libxrandr2 libgbm1 libpango-1.0-0 libasound2 fonts-liberation
```

Extensiones que usa el código: `calendar` (`cal_days_in_month` del motor V4), `zip` y `xml`
(OpenSpout/PhpSpreadsheet), `gd` (PhpSpreadsheet), `pcntl` (timeouts reales de la cola),
`intl`, `bcmath`, `mbstring`, `pdo_mysql`.

## 1. Clonar el repositorio

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/mrlanasistemas-star/Reportes.git reportes
sudo chown -R www-data:www-data /var/www/reportes
cd /var/www/reportes
```

> El historial de git contiene los libros `docs/efectividad/*.xlsm`, con datos reales.
> No hacen falta en producción: bórralos del servidor (`rm docs/efectividad/*.xlsm`) o
> sigue la recomendación del informe de cierre para retirarlos del repositorio.

## 2. Dependencias PHP

```bash
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
```

## 3. Dependencias Node y compilación del frontend

```bash
sudo -u www-data npm ci
sudo -u www-data npm run build      # el plugin Wayfinder llama a `php artisan`: PHP debe estar en PATH
```

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
APP_URL=https://reportes.ejemplo.mx      # los enlaces de los correos usan este dominio

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reportes
DB_USERNAME=reportes
DB_PASSWORD=********
DB_ENGINE=InnoDB                          # fuerza InnoDB (claves foráneas reales)

SESSION_DRIVER=database
CACHE_STORE=database                      # Cache::lock() protege "Generar" contra doble clic
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1900                 # > EFECTIVIDAD_JOB_TIMEOUT y > --timeout del worker
EFECTIVIDAD_JOB_TIMEOUT=900
EFECTIVIDAD_QUEUED_DELAY_MINUTES=5
EFECTIVIDAD_HEARTBEAT_STALE_MINUTES=15
EFECTIVIDAD_NOTIFICATION_MAX_ATTEMPTS=5

MAIL_MAILER=smtp
MAIL_HOST=smtp.proveedor.mx
MAIL_PORT=587
MAIL_USERNAME=********
MAIL_PASSWORD=********
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="reportes@ejemplo.mx"
MAIL_FROM_NAME="${APP_NAME}"

BROWSERSHOT_NODE_BINARY=/usr/bin/node
BROWSERSHOT_CHROME_PATH=/var/www/.cache/puppeteer/chrome/linux-XXX/chrome-linux64/chrome
BROWSERSHOT_TIMEOUT=180
BROWSERSHOT_NO_SANDBOX=true               # normalmente necesario con www-data sin sandbox de usuario
BROWSERSHOT_PDF_READY_TIMEOUT=20000
```

## 5-7. Base de datos vacía, migraciones y seeders

```sql
CREATE DATABASE reportes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'reportes'@'localhost' IDENTIFIED BY '********';
GRANT ALL PRIVILEGES ON reportes.* TO 'reportes'@'localhost';
```

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force     # SOLO: DataSourceSeeder, BranchSeeder, OkrKpiSeeder
```

Verificado el 12-oct-2026 en una BD vacía de MySQL 8.4 con `DB_ENGINE=InnoDB`: corrieron
88 migraciones (las 3 de V4 incluidas) en ~18 s, con 63 tablas InnoDB y 113 claves
foráneas. Los seeders crearon 12 fuentes de datos (incluida `lendus_saldos_cliente_foto`),
13 sucursales oficiales y 15 KPI de OKR. **No se crean usuarios.**

Comprobación:

```bash
php artisan migrate:status | tail -5
mysql -e "SELECT ENGINE, COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='reportes' GROUP BY ENGINE;"
```

## 8. Administrador inicial

El registro público está deshabilitado (Fortify). Crea el primer admin desde consola:

```bash
sudo -u www-data php artisan tinker --execute="
\$u = new App\Models\User;
\$u->forceFill([
  'name' => 'Administrador',
  'email' => 'admin@ejemplo.mx',
  'password' => Illuminate\Support\Facades\Hash::make('CAMBIAR-ESTA-CLAVE'),
  'role' => 'admin',
  'email_verified_at' => now(),
])->save();"
```

(`email_verified_at` es obligatorio: las rutas exigen `verified`). Los demás usuarios se dan
de alta desde el sistema.

## 9. Almacenamiento

```bash
sudo -u www-data php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

- `storage/app/private` (disco `local`): Excel generados de Efectividad, PDF de OKR, etc.
  No es accesible por web.
- `storage/app/public` (disco `public`): **archivos originales cargados**, incluidas las
  fotografías de cartera y los Ingresos. La app los descarga por controladores
  autenticados; nada del frontend los enlaza directamente. **Hay que bloquearlos en Nginx**
  (siguiente paso).

## 10. Nginx y SSL

```nginx
server {
    listen 80;
    server_name reportes.ejemplo.mx;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name reportes.ejemplo.mx;
    root /var/www/reportes/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/reportes.ejemplo.mx/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/reportes.ejemplo.mx/privkey.pem;

    client_max_body_size 140M;          # la validación permite Excel de hasta 128 MB

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    # Originales con información financiera y personal: nunca por URL pública.
    location ^~ /storage/reports/      { deny all; return 404; }
    location ^~ /storage/report_uploads/ { deny all; return 404; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo certbot --nginx -d reportes.ejemplo.mx
# PHP-FPM (php.ini): upload_max_filesize=128M, post_max_size=140M, memory_limit=512M
sudo nginx -t && sudo systemctl reload nginx
```

## 11. Worker de colas (Supervisor)

`/etc/supervisor/conf.d/reportes-worker.conf`:

```ini
[program:reportes-worker]
command=php -d memory_limit=1024M /var/www/reportes/artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=1800 --memory=1024
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=1900
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/reportes/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

La cola procesa: la generación de Efectividad V4 (`GenerateEfectividadCobranzaAprobadaJob`,
9 etapas), la importación de cada fotografía (`ImportPortfolioSnapshotJob`), las
actualizaciones de BD de Reportería y los PDF de OKR. Tras cada despliegue:
`php artisan queue:restart`.

## 12. Scheduler

```bash
sudo crontab -u www-data -e
* * * * * cd /var/www/reportes && php artisan schedule:run >> /dev/null 2>&1
```

Agenda (`routes/console.php`): `okr:refresh` (06:00), `okr:close-due` (06:15),
`api:query-logs:prune` (03:00), `efectividad:recover-stalled --apply` (cada 10 min) y
`efectividad:retry-notifications` (cada 15 min).

## 13. Browsershot (PDF de OKR)

```bash
cd /var/www/reportes
sudo -u www-data npx puppeteer browsers install chrome   # imprime la ruta del binario
```

Pon esa ruta en `BROWSERSHOT_CHROME_PATH` y `which node` en `BROWSERSHOT_NODE_BINARY`. Las
rutas deben ser **absolutas**. Verificación:

```bash
sudo -u www-data php artisan test --filter=BrowsershotPdfRendererTest   # en un entorno con dependencias de desarrollo
# o en producción: genera un PDF de OKR desde la web.
```

## 14. SMTP

Con el `.env` del paso 4:

```bash
sudo -u www-data php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Prueba SMTP Reportes', fn (\$m) => \$m->to('tu-correo@ejemplo.mx')->subject('Prueba'));"
```

Si el SMTP falla, la Efectividad **no** se pierde: el resultado queda disponible y el envío
se reintenta (`efectividad:retry-notifications`).

## 15. Caches de producción

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache
```

## 16. Validar la API

```bash
curl -s https://reportes.ejemplo.mx/api/v1/health                      # 200 sin token
sudo -u www-data php artisan api:consumers:create "Sistema X" sistema-x --ability=collections.read --ability=periods.read
curl -s -H "Authorization: Bearer <TOKEN>" "https://reportes.ejemplo.mx/api/v1/collections/effectiveness?period_id=<ID_SEMANA>"
curl -s -o /dev/null -w "%{http_code}\n" https://reportes.ejemplo.mx/storage/reports/x.xlsx   # debe dar 404
```

## 17-20. Carga inicial y validación funcional (en orden)

1. Con el admin: **Periodos**. Crea los meses operativos y sus semanas lunes-domingo.
2. **Histórico General** (mes operativo): NOI, Gastos, Ingresos Cobranza, Saldos por Cliente
   mensual, etc. Para cada archivo, **Analizar** o **Actualizar BD**.
3. **Efectividad de Cobranza**: elige una semana. En **Disponibilidad de información**
   carga las **8 fotografías** (domingo base … domingo corte). Cada tarjeta debe quedar en
   *Disponible*.
4. **Generar reporte** (Semanal). Revisa las 9 etapas, el resultado, las incidencias, el
   CSV y el Excel. Comprueba que llegan la notificación interna y el correo.
5. **Reportería**: genera la radiografía mensual y revisa indicadores, exportaciones y
   descargas de originales.
6. **OKR**: objetivos, KR, avances, evidencias, vista previa y PDF (Browsershot).
7. Sal del módulo durante una generación y regresa: el estado debe recuperarse.

Checklist final:

- [ ] `php artisan about` sin errores; `APP_DEBUG=false`.
- [ ] `supervisorctl status` muestra `RUNNING`; `storage/logs/worker.log` sin errores.
- [ ] `schedule:run` en cron (`php artisan schedule:list`).
- [ ] `/storage/reports/*` responde 404.
- [ ] Correo de prueba recibido.
- [ ] PDF de OKR generado.
- [ ] Efectividad V4 de una semana real generada y revisada contra el Excel de la macro.

## Qué NO hacer en esta instalación

- No restaurar un dump de la BD anterior ni copiar `storage/` del servidor viejo.
- No copiar `.env` del servidor viejo (contiene `APP_KEY`, sesiones y tokens anteriores).
- No ejecutar `migrate:fresh` sobre una BD con información ya cargada.
- No apagar ni borrar el servidor anterior hasta validar todos los puntos anteriores.

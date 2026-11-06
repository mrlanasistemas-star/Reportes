# Archivos sensibles versionados en Git — inventario y corrección

> **Actualización 06-nov-2026 (Estado de Resultados).** Se detectaron 4 archivos sensibles más
> en `main`. Se retiraron del árbol actual en el commit de seguridad de esta fecha, y hay copia
> verificada por sha256 fuera del repositorio, en el equipo del responsable
> (`Documents/MRLANA_privado/repo-retirado-2026-11-06/`).
>
> | Archivo | Entró en | Contenido |
> |---|---|---|
> | `docs/3EBITDA/09. SEPTIEMBRE 26 MRLANA 6697.xlsx` | `b6f6e6a` (06-nov-2026) | estado de cuenta bancario real (cuenta administrativa) |
> | `docs/3EBITDA/09. SEPTIEMBRE 26 MRLANA 8119.xlsx` | `b6f6e6a` | estado de cuenta bancario real (cuenta de excedentes) |
> | `docs/3EBITDA/MR_LANA_Estado_Resultados_Cordoba_Octubre_2026.xlsx` | `b6f6e6a` | cifras reales de Córdoba y hoja «NOMINA GESTORES» con personas y sueldos |
> | `docs/usuarios/DIRECTORIO_GERENCIAS_MRLANA.xlsx` | `67a392c` (16-oct-2026) | directorio de gerencias con datos personales de contacto |
>
> Las dos infografías de `docs/3EBITDA/` son ejemplos ilustrativos sin datos reales. Se
> conservaron como documentación en `docs/estado-resultados/` con nombres descriptivos.
> Ninguna prueba, comando ni funcionalidad depende de los archivos retirados: las pruebas usan
> fixtures sintéticos de `tests/Support/EstadoResultadosFixtures.php`.
>
> **Siguen en el historial.** Ambos commits solo están en `main`; no hay etiquetas. Para la
> limpieza, agrega estas rutas a la orden `git filter-repo` de la sección 3:
>
> ```bash
>   --path docs/3EBITDA/ --path docs/usuarios/DIRECTORIO_GERENCIAS_MRLANA.xlsx
> ```
>
> y en la verificación posterior añade `|^docs/3EBITDA/|^docs/usuarios/` al `grep`. La
> reescritura y el `push --force` siguen **pendientes de autorización**.

> **Estado actualizado 02-nov-2026.** Los 57 archivos de datos reales que todavía estaban
> rastreados en `main` se retiraron del índice en el commit `2b8889e`; no forman parte de
> nuevos clones de la rama principal. **El historial anterior sigue conteniéndolos y el
> repositorio sigue público**, por lo que la exposición histórica NO está resuelta. La
> limpieza del historial continúa requiriendo el procedimiento controlado de la sección 3.

## 0. Situación

- El remoto `github.com/JesusA2004/Reportes` redirige a
  **`github.com/mrlanasistemas-star/Reportes`, que al 02-nov-2026 sigue PÚBLICO**.
  Los archivos sensibles ya no están en el árbol actual de `main`, pero continúan
  recuperables desde commits anteriores mientras no se reescriba/purgue el historial.
- Los datos entraron a partir del 07-may-2026 (commits `9d7b03f` y `01f0e92`). De las
  ramas y referencias remotas, **solo `refs/heads/main` los contiene**. Las 15 referencias
  `refs/pull/*` y las ramas `add-claude-github-actions-…` y
  `redisenio-historico-general-tailwind` son anteriores y están limpias.
- El `.gitignore` ya excluye esas carpetas, pero eso **no** saca del índice los archivos
  que ya estaban rastreados ni los borra del historial.
- No se encontraron secretos reales en el código ni en el historial (ver sección 4).

## 1. Inventario

### 1.1 Retirados del índice actual de `main` el 02-nov-2026: 57 archivos

| Categoría | Archivos | Contenido |
|---|---|---|
| Nómina NOI (fiscal y no fiscal) | `documentosEjemplo/NOMINA.xls`, `documentosEjemplo/NOMINAF.xls`, `documentosJunio/NOMINAJUNIO.xls`, `documentosJunio/NOMINAFISCALJUNIO.xls`, `documentosMayo/NOMINAMAYO.xls`, `documentosMayo/NOMINAFISCALMAYO.xls` | sueldos por persona |
| Empleados Lendus | `documentos{Ejemplo,Junio,Mayo}/Reporte de empleados.xlsx` (3) | datos personales |
| IMSS | `documentosJunio/IMSS JUNIO 2026.xlsx`, `documentosMayo/CALCULO DE CUOTA SEMANAL DEL IMSS POR SUCURSAL MAYO.xlsx` | cuotas por persona/sucursal |
| Rotación de personal | `documentosEjemplo/INDICE DE ROTACIÓN DE PERSONAL 2026.xlsx`, `documentosMayo/INDICE DE ROTACIÓN DE PERSONAL 2026 actualizado.xlsx`, `documentosJunio/INDICE DE ROTACIÓN DE PERSONAL JUNIO 2026.pdf` | altas/bajas con nombre |
| Clientes / cartera Lendus | `documentos{Ejemplo,Junio,Mayo}/Saldos Por Cliente.xlsx`, `documentosEjemplo/Saldos Por ClienteFiltros.xlsx`, `documentosColumnas/COLUMNAS PARA {MORAS,PRESTAMO ACTIVO,VALOR CARTERA}.xlsx`, `documentosColumnas/colocacion 6 de abril.xlsx` | datos de clientes y saldos |
| Cobranza / ministraciones | `documentos{Ejemplo,Junio,Mayo}/Ingresos cobranza.xlsx`, `documentosColumnas/Ingresos cobranza 2026-05-20 A 2026-05-20.xlsx`, `documentos{Ejemplo,Junio,Mayo}/Ministraciones por periodo.xlsx` | pagos por cliente |
| Gastos ERP / Lendus | `documentos{Ejemplo,Junio,Mayo}/Gastos.xlsx`, `…/Gastos.pdf`, `…/Gastos ERP.xlsx` (9) | gastos con beneficiario |
| Efectividad | `docs/efectividad/MR_LANA_EFECTIVIDAD PRUEBA TULA.xlsm`, `documentosEjemplo/Macro.xlsm` | cartera y cobranza reales |
| Radiografías / estados de cuenta | `docRadios/04_INFORME DE SUCURSALES (RADIOGRAFÍA) ABRIL26.xlsx`, `docRadios/radiografia_M-2026-04_20260615_151655.xlsx`, `docRadios/Estado de Cuenta.pdf` | financiero de la empresa |
| Salidas de auditoría | `storage/audit/*.json`, `storage/audit/*.err.log`, `storage/audit_before/*.json`, `storage/audit_before/*.err.log` (14) | **nombres completos de colaboradores con cifras por persona** (730 filas `scope_type=employee`). No estaban en `.gitignore`; se añadieron el 15-oct-2026 |
| Documento interno (bajo) | `docs/SoliOKR.pdf` | solicitud del módulo OKR; sin datos personales detectados |

Total: 43 archivos de datos reales + 14 salidas de auditoría + 1 documento interno. Pesan
unos 99 MB (el repositorio en GitHub ocupa unos 181 MB).

No son sensibles y deben quedarse: `resources/templates/okr/*.pdf` (formatos en blanco),
`resources/templates/radiografia_template.xlsx`, `docs/imagenesOKR/*.png` (maquetas),
`storage/app/public/.htaccess`, `.env.example` y `.env.testing.mysql.example` (sin
valores).

### 1.2 Solo en el historial (ya no están en `main`, pero se pueden descargar): 20 archivos

- `docs/efectividad/MR_LANA_EFECTIVIDAD.xlsm`,
  `docs/efectividad/CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm`
- `documentosEjemplo/NOMINANUEVA.xls`, `documentosEjemplo/GastosLendus.pdf`,
  `documentosEjemplo/GastosLendus.xlsx`, `documentosEjemplo/GastosRequisiciones.xlsx`,
  `documentosEjemplo/Ministraciones.xlsx`,
  `documentosEjemplo/CALCULO DE CUOTA SEMANAL DEL IMSS POR SUCURSAL ACTUALIZADA.xlsx`,
  `documentosEjemplo/INDICE DE ROTACIÓN DE PERSONAL 2026 actualizado.xlsx`
- 11 archivos de bloqueo de Office `~$*.xlsx` en `documentosEjemplo/`, `documentosJunio/` y
  `documentosColumnas/`. Solo guardan el nombre de usuario de Windows.

(`codex.patch`, que también aparece en el historial, solo contiene código).

### 1.3 Otros datos personales en archivos de código (no se pueden mover con `git rm`)

Unos 60 archivos de `app/` y `tests/` (por ejemplo `app/Services/BranchResolverService.php`,
`app/Console/Commands/FixLendusBranchAssignmentsCommand.php`,
`app/Services/Imports/GastosLendusPdfImportService.php` y varias pruebas de `tests/Feature/Okr/`)
tienen **nombres completos reales** de colaboradores en reglas de excepción, comentarios o
datos de prueba. No llevan sueldos, pero con el repositorio público se pueden leer. Se
recomienda que, cuando el equipo de desarrollo pueda, cambie las pruebas a nombres
ficticios y lleve las excepciones por nombre a datos de la base o de configuración.

## 2. Corrección mínima: dejar de versionarlos — EJECUTADA EN `main`

El commit `2b8889e` retiró esos 57 archivos del árbol versionado actual. El código productivo
no depende de ellos; las pruebas que necesitan muestras locales deben usar archivos ignorados
y se omiten de forma explícita cuando esas muestras no existen. **Esto no limpia el historial.**

```bash
# 0) Lo más urgente: hacer PRIVADO el repositorio en GitHub
#    (Settings → General → Danger Zone → Change visibility), o:
gh repo edit mrlanasistemas-star/Reportes --visibility private --accept-visibility-change-consequences

# 1) Respaldo local de los archivos (fuera del repositorio)
mkdir -p ~/respaldo-datos-reportes
cp -r documentosEjemplo documentosJunio documentosMayo documentosColumnas docRadios \
      storage/audit storage/audit_before ~/respaldo-datos-reportes/
cp "docs/efectividad/MR_LANA_EFECTIVIDAD PRUEBA TULA.xlsm" ~/respaldo-datos-reportes/

# 2) Sacarlos del índice (el .gitignore ya evita que vuelvan a entrar)
git rm -r --cached documentosEjemplo documentosJunio documentosMayo documentosColumnas docRadios \
  storage/audit storage/audit_before "docs/efectividad/MR_LANA_EFECTIVIDAD PRUEBA TULA.xlsm"
git status            # deben aparecer 57 "deleted" y ningún archivo de código
git commit -m "Retirar del repositorio datos reales de nómina, clientes, gastos e IMSS"
git push origin main
```

Así dejan de estar en `main`, pero **siguen en el historial**: cualquiera con acceso al
repositorio (o con un clon anterior) los puede recuperar. Para borrarlos de verdad hay que
hacer el paso 3.

`docs/SoliOKR.pdf` puede quedarse; si el dueño prefiere retirarlo, agréguelo a la misma
orden.

## 3. Limpieza del historial con `git filter-repo` **[AUTORIZACIÓN]**

### Qué implica

- **Reescribe todos los commits** desde el 07-may-2026: cambian todos los hashes de `main`.
  Las referencias como `8510207` que aparecen en documentos y memorias dejarán de existir.
- Requiere **`git push --force`** a `main` (y a cualquier otra rama que contenga esos
  commits; hoy solo `main`). Si `main` tiene protección contra force push en GitHub, hay que
  quitarla un momento.
- **Clones existentes** (equipos de desarrollo, VPS actual, agentes, worktrees como
  `worktree-agent-aed59f969398905f4`): siguen teniendo los datos y, si hacen `push`,
  los vuelven a subir. Todos deben **borrar su clon y volver a clonar** (no basta un
  `git pull`). Los cambios sin subir se pasan como parche (`git format-patch`) antes.
- **Forks**: hoy hay 0. Si alguien hizo un fork o descargó un ZIP mientras el repositorio
  era público, esa copia no se puede borrar desde aquí; se considera filtrada.
- **GitHub guarda caché**: los commits viejos pueden seguir accesibles por su hash durante
  un tiempo. Después del force push hay que pedir a **GitHub Support** que borre las vistas
  en caché y ejecute el recolector de basura del repositorio
  ("Removing sensitive data from a repository" en la documentación de GitHub). Las
  referencias `refs/pull/*` están limpias (son anteriores a los datos), así que no hace
  falta borrar pull requests.
- Como el repositorio fue público, **se debe asumir que los datos ya se filtraron**. La
  limpieza reduce la exposición futura, pero no la anula. El dueño debe valorar si hay que
  avisar a alguien según la política de protección de datos de la empresa (LFPDPPP).

### Procedimiento (en un clon espejo nuevo, nunca en la copia de trabajo)

```bash
pip install git-filter-repo                      # o el paquete del sistema
git clone --mirror https://github.com/mrlanasistemas-star/Reportes.git reportes-limpieza.git
cd reportes-limpieza.git

git filter-repo --invert-paths \
  --path documentosEjemplo/ --path documentosJunio/ --path documentosMayo/ \
  --path documentosColumnas/ --path docRadios/ \
  --path storage/audit/ --path storage/audit_before/ \
  --path "docs/efectividad/MR_LANA_EFECTIVIDAD PRUEBA TULA.xlsm" \
  --path "docs/efectividad/MR_LANA_EFECTIVIDAD.xlsm" \
  --path "docs/efectividad/CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm"

# Comprobar que no queda nada antes de subir:
git log --all --no-renames --diff-filter=A --name-only --format= \
  | grep -E '^(documentos|docRadios|storage/audit)|\.xlsm$' && echo "AUN HAY DATOS" || echo "limpio"

# filter-repo quita el remoto por seguridad; volver a ponerlo y subir:
git remote add origin https://github.com/mrlanasistemas-star/Reportes.git
git push --force origin 'refs/heads/*:refs/heads/*' 'refs/tags/*:refs/tags/*'
```

> No uses `push --mirror`: GitHub rechaza escribir en `refs/pull/*` (son de solo lectura).
> Como se indicó, esas referencias no contienen los datos.

Después:

1. Pedir a GitHub Support la purga de la caché (incluir la lista de rutas de la sección 1).
2. Todos los que tengan un clon: guardar su trabajo pendiente, borrar el clon y volver a
   clonar.
3. En el VPS: no hacer `git pull` sobre el clon viejo; volver a clonar (ver
   `docs/despliegue-vps-limpio.md`).
4. Actualizar las referencias a hashes de commits en `docs/` si se necesitan.

## 4. Secretos y credenciales

Búsqueda en todos los archivos rastreados y en el historial completo de todas las ramas
(`APP_KEY=base64:`, `DB_PASSWORD`, `MAIL_PASSWORD`, `*_TOKEN`, llaves `sk-`/`ghp_`/`AKIA`,
llaves privadas PEM, `Hash::make`/`bcrypt` con texto fijo en seeders y migraciones):

- **No hay `.env` real** en el índice ni en el historial (`.env`, `.env.production`,
  `.env.backup` y `auth.json` nunca se versionaron).
- `.env.example` y `.env.testing.mysql.example`: `APP_KEY` vacío y contraseñas vacías o
  `null`.
- En el historial solo aparecen `DB_PASSWORD=********` y `MAIL_PASSWORD=********`, que son
  textos de ejemplo de este mismo directorio `docs/`.
- Los seeders (`DataSourceSeeder`, `BranchSeeder`, `OkrKpiSeeder`) **no crean usuarios** ni
  contraseñas. Las únicas contraseñas fijas están en `database/factories/UserFactory.php`
  (`'password'`) y en `tests/`: solo se usan en pruebas.
- `resources/views/docs/api.blade.php` usa el texto `pega_aqui_el_token`.
- Los correos de bienvenida (`UserAccessGrantedMail`) entregan una **contraseña temporal** de
  primer acceso (no un enlace). Esa contraseña es de un solo uso práctico: se pide cambiarla en
  Configuración › Seguridad al entrar. En `users` **solo** vive el hash; el valor en claro nunca
  se persiste ni se registra en logs/auditoría (solo viaja en ese correo y, una vez, en pantalla).

**Rotación de secretos: no hace falta**, porque no se filtró ninguno. Si en algún momento se
confirma que un `.env` real se copió a otro lugar público, hay que cambiar `APP_KEY` (esto
cierra todas las sesiones e invalida lo cifrado con `Crypt`), `DB_PASSWORD`,
`MAIL_PASSWORD` y revocar o reemitir los tokens de API (`api:consumers:revoke` /
`api:consumers:create`).

## 5. Prevención

- El `.gitignore` excluye `documentos*/`, `docRadios/`, `docs/ejemplosCorte/`, los `.xlsm`
  de Efectividad, `storage/audit*/`, `*.sql`, `*.sql.gz`, `*.dump` y `~$*`.
- Recomendado: activar en GitHub *Secret scanning* y *Push protection* (gratis en
  repositorios públicos y con GitHub Advanced Security en privados).
- Antes de cada `git add .`, revisar `git status`. Cualquier Excel, PDF o CSV fuera de
  `resources/templates/` debe revisarse antes de subirlo.

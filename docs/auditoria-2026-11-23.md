# Auditoría integral — 23-nov-2026

Revisión a fondo del proyecto en producción (backend, seguridad/API, UI/diseño, rendimiento,
pruebas y DevOps), hecha con cuatro revisiones paralelas de solo lectura y verificación manual
de los hallazgos críticos. Complementa `docs/auditoria-2026-10-12.md`.

> Leyenda: **C** crítico · **A** alto · **M** medio · **B** bajo. «Verificado» = comprobado
> leyendo el código o ejecutando el comando; «a verificar» = depende de producción.

## 0. Estado general

| Área | Nota (1-10) | Resumen |
|---|---|---|
| Lógica de negocio / cálculo | 8 | Reglas muy trabajadas y documentadas; ER con gobierno (aprobación, bloqueos, firmas). Riesgo: reglas duplicadas. |
| Arquitectura backend | 6 | Servicios gigantes (7,831 y 5,264 líneas), resolución de periodos repetida, ciclo de vida de jobs frágil. |
| Seguridad | 5 | Diseño de permisos y API sólido; **historial git público con datos reales** y archivos subidos en disco público. |
| API v1 | 8 | Doble verificación de abilities, alcance por sucursal, auditoría, OpenAPI. Falta formato de error con código y expiración de tokens. |
| UI / diseño | 6 | Buen sistema de tokens que se usa poco; 6 tarjetas KPI distintas; página de Radiografía de 3,569 líneas. |
| Pruebas | 7 | ~1,250 pruebas (OKR, ER, Efectividad, API bien cubiertos); importadores casi sin pruebas; SQL de MySQL casi sin cubrir en CI. |
| Operación / DevOps | 4 | **CI en rojo desde el 16-oct** (nunca llega a correr Pest), dependencias con avisos críticos, sin respaldos automáticos. |

## 1. Lo urgente (hacer esta semana)

1. **C · Repositorio público con datos reales en el historial** (verificado por la revisión de
   seguridad). Aunque hoy los archivos ya no están en el índice (commit 4a6e0b6 y anteriores),
   `git log --all` recupera estados de cuenta (`docs/3EBITDA/*6697.xlsx`), directorio de
   gerencias, nómina, libros de Efectividad y `storage/audit/*.json`. **Acción:** poner el repo en
   privado ya; después `git filter-repo` + force-push coordinado + solicitud de limpieza de caché a
   GitHub; tratar clones/forks como comprometidos; rotar credenciales por precaución.
2. **C · CI en rojo desde el 16-oct-2026** (verificado con `gh run list`). El paso
   `composer audit` falla primero, así que lint, tipos, build y Pest no corren desde hace ~5
   semanas. Además `.github/workflows/tests.yml:55-58` tiene un `run:` de varias líneas sin `|`:
   YAML lo junta en una línea y **nunca migra ni genera Wayfinder** (verificado).
   **Acción:** `run: |`, separar el audit en otro job y volver a exigir verde antes de desplegar.
3. **C · Dependencias vulnerables** (`composer audit --locked --no-dev`: 61 avisos).
   `phpoffice/phpspreadsheet` 1.30.2 tiene 2 críticos (SSRF/RCE en `IOFactory::load`) y el sistema
   abre Excel subidos por usuarios. También `laravel/framework` 13.2, `maatwebsite/excel`,
   `league/commonmark`, y `npm audit` con 8 altos (vite, zod…). **Acción:** actualizar
   phpspreadsheet ≥ 1.30.7, framework ≥ 13.27, excel ≥ 3.1.70, `npm audit fix`; correr
   regresión de importadores.
4. **A · Archivos fuente subidos en el disco `public`** (verificado:
   `app/Services/ReportUploadService.php:26` → `store('reports', 'public')`). Con el enlace
   `public/storage`, cualquiera con la URL descarga nómina/cartera sin sesión ni permiso.
   `report_uploads/` usa `uniqid()` (adivinable por hora). **Acción:** disco privado + servir solo
   por `ReportUploadController::download`; migrar archivos actuales. A verificar en el VPS si existe
   el symlink (`despliegue-vps-limpio.md` y `migracion-vps.md` se contradicen).
5. **A · Respaldos solo manuales** (mysqldump antes de cada despliegue). **Acción:** respaldo diario
   cifrado de BD + `storage/app` fuera del VPS y prueba de restauración mensual.

## 2. Bugs y riesgos de backend

| # | Sev. | Dónde | Problema | Arreglo |
|---|---|---|---|---|
| B1 | C | `RadiographySnapshotBuilder.php:3824-3900` y `:5147-5160` vs `BranchRadiographyCalculator::recoveryExclusionSql()` | **Tres reglas distintas de recuperación**: la oficial cuenta PAGO/DESCUENTO + 30 % CRECE; el detalle excluye por texto (`LIKE '%SEGURO%'`…) y descarta filas sin sucursal; Efectividad usa `transaction != 'CONDONACION'` (también tira NULL). Una transacción nueva descuadra Ingresos/Efectividad contra el KPI. | Una sola regla (`RecoveryRules`) + prueba que exija global = Σ sucursal = detalle = efectividad. |
| B2 | A | `ReportUploadController.php:96`, `:1728-1749` | Por cada periodo carga **todas** las cargas de la historia (`ReportUpload::with('dataSource')->get()`). | Cargar una vez e indexar; «último run» con `MAX(id) GROUP BY`. |
| B3 | A | `MonthlyReportController::persistFilteredRunExport` (`:278-344`) | Un **GET** de exportación filtrada marca el último run como `success` y borra versiones anteriores, aunque el job esté corriendo. | Bitácora de descargas aparte; nunca tocar runs `queued/running` desde un GET. |
| B4 | A | `RadiografiaExportService.php:79-93` | Caché de 12 h ligada solo a `summary.updated_at`: reasignar un gestor de sucursal no la invalida. | Firma de entradas (como `InputSignature` del ER) o `touch()` del resumen al reasignar. |
| B5 | A (a verificar) | `config/queue.php:43`, `.env.example:45`, `composer.json` | `retry_after` 90/1900 vs job de 3600 s: la cola puede re-entregar un job vivo → importaciones dobles. `UpdatePeriodDatabaseJob:51` reclama runs ya `running`. | `retry_after` 3700 en todos lados, `WithoutOverlapping` por periodo, reclamar solo `queued`. |
| B6 | M | Jobs de radiografía/BD/reproceso | Sin `failed()`; `WithoutOverlapping` sin `releaseAfter`: runs atascados en `running`. | `failed()` + `releaseAfter(60)` + comando que marque runs vencidos. |
| B7 | M | `ReportUploadController::updateDatabase` (`:415-455`) | Doble clic = dos cargas; sin candado entre carga de BD y radiografía. | Transacción con `lockForUpdate` + candado por periodo compartido. |
| B8 | M | `PeriodConsolidationService.php:29-96` | N+1 por empleado (find + 3 consultas + create). | `distinct()`, precarga e inserción en bloque. |
| B9 | M | `DatabaseUpdateService.php:198`, `GenerateRadiographyJob.php:196` | `catch (\Throwable)` que solo loguea: el run queda «éxito» con IMSS/rotación/OPEX desactualizados. | Advertencia visible en el run/resumen. |
| B10 | M | `MonthlyReportController.php:780, 983, 1026, 1094…` | Devuelve `$e->getMessage()` al usuario (puede exponer SQL); exports síncronos en la petición. | `TechnicalErrorReference` (como ER) y exports en cola. |
| B11 | M | `RadiographySnapshotBuilder:1658,1709`, `PeriodEmployeeRosterService:686`, etc. | 4-5 copias de «qué period_ids leer» y `Period::all()` repetido (7 veces en el builder). | `PeriodDataScope` único memoizado. |
| B12 | M | `RadiographySnapshotBuilder:31,1641` | Estado mutable: si no se «calienta» antes, devuelve **ceros silenciosos**. | Pasar ids como parámetro o lanzar excepción. |
| B13 | M | `EstadoResultadosController:371` y otros | `memory_limit` 1-2 GB en peticiones web. | Mandar `approve/process` a la cola. |
| B14 | M | `CACHE_STORE=database` | La tabla `cache` crece sin purga (llaves versionadas). | Purga programada o Redis; `queue:prune-failed`. |
| B15 | B | Varios | Lista de sucursales fija en ~10 archivos pese a `BranchRouteCatalog`; columnas de dinero `double` en cartera V4; `JSON_EXTRACT(raw_payload,'$.credit_origin')` sin índice; jobs V4 muertos (`GenerateEfectividadCobranzaAprobadaJob`, `…PeriodicaJob`). | Catálogo único; `decimal(16,2)`; columna materializada; borrar código muerto. |

## 3. Seguridad y API

| # | Sev. | Hallazgo | Arreglo |
|---|---|---|---|
| S1 | C | Historial público con datos reales (ver §1.1). | Privado + `filter-repo`. |
| S2 | A | Archivos subidos en disco `public` (§1.4). | Disco privado. |
| S3 | A | **Inyección de fórmulas** en Excel/CSV: 706 `setCellValue()` en `RadiographyWorkbookBuilder` con texto importado; también `EstadoResultadosExcelExporter`, `DashboardExcelExporter`, `exportSummary` y `ApiCsv::cell()`. Un concepto `=HYPERLINK(...)` se ejecuta al abrir el archivo. | Helper único: texto que empiece con `= + - @ \t \r` se escribe como `TYPE_STRING`. |
| S4 | M | `financial.read` basta para datos por colaborador en `/financial/employees/{id}` y `scope=employee`. | Exigir también `employees.read`. |
| S5 | M | `/people` deja `current_branch_id`/`sucursal` fuera del alcance de un consumidor restringido. | Ocultar si no está permitido. |
| S6 | M | Tokens de API sin expiración; el comando de alta da 6 abilities y modo global por defecto. | `expires_at`, rotación, alta explícita y modo que falle cerrado. |
| S7 | M | Sin protección contra bombas ZIP en cargas grandes (ER y cargas generales; Efectividad sí la tiene). | Reusar la inspección de `EfectividadExcelReader`. |
| S8 | B | Throttle después de `auth` (401 no cuenta); 404 vs 403 permite enumerar IDs; `SESSION_SECURE_COOKIE` ausente en `.env.example`; confirmar que `PruneApiQueryLogsCommand` esté programado. | Ajustes puntuales. |

Bien resuelto: CSRF sin excepciones, `#[Fillable]/#[Hidden]` en `User`, Browsershot solo con HTML
escapado, errores sin traza con `debug=false`, credenciales masivas cifradas, doble verificación
token+consumidor en la API.

Mejoras de diseño de la API: sobre de respuesta único `{data, meta}`; errores con `code` estable y
`errors` por campo; paginación uniforme (tope documentado); `Cache-Control: no-store` en
financieros y `ETag` en catálogos; política de deprecación antes de una v2.

## 4. UI / diseño

**Bugs**

| # | Sev. | Dónde | Problema |
|---|---|---|---|
| U1 | M | `components/radiography/ChartCard.vue:50-61` + `lib/chart-theme.ts` | En modo oscuro las opciones de modo claro **sobrescriben** el tema oscuro (tooltip blanco, etiquetas gris oscuro sobre fondo oscuro). Afecta Dashboard, Comparativo, ER. |
| U2 | M | `components/asignaciones/AssignBranchModal.vue:64-153` | Modal casero sin `role="dialog"`, sin foco atrapado, Escape no cierra, botón X sin `aria-label`, labels sin `for`. |
| U3 | M | `pages/ReportesMensuales/Preview.vue:2352-3475` | 12 pestañas con `v-show`: monta 25 gráficas y todas las tablas al entrar; pestañas sin ARIA. |
| U4 | B | `usuarios/useUserActions.ts`, `RoleManager.vue`, `OkrDocumentsPanel.vue` | SweetAlert con colores hex propios (índigo/rojo) fuera de marca. |
| U5 | B | 109 textos `text-slate-300/400`, 170 textos de 9-10.5 px | Contraste < AA y tamaños bajo el mínimo de 11 px de la propia guía. |
| U6 | B | `OkrGestorTable.vue:79`, `DeleteUser.vue:73`, `dialog/sheet` | Fila clicable sin teclado; textos en inglés («Password», «Close»). |

**Deuda de sistema visual:** ~3,670 clases de color fijas contra ~100 usos de tokens de estado;
6 tarjetas KPI distintas (`KpiCard`, `DashboardKpiCard`, `ComparativeKpiCard`, `OkrStatCard`,
`AppStatCard`, `KpiStrip`); `AppPageHeader` solo en 6 páginas; 306 `<button>` nativos y 126
controles de formulario nativos; 214 `any` (50 en `Preview.vue`).

**Archivos más grandes:** `Preview.vue` 3,569 · `historico-general/index.vue` 1,127 ·
`Periodos/Index.vue` 1,122 · `EstadoResultados/Index.vue` 1,037 · `MovementsTable.vue` 965.

**Dirección propuesta**

- Tokens: mantener verde institucional `#047857` como `primary`; agregar `--chart-1..8`,
  `--positive/--negative` (variación financiera), escala tipográfica cerrada 11/12/14/16/20/24/30,
  radios cerrados (lg controles, 2xl tarjetas, 3xl modales), `tabular-nums` en cifras.
- Plantilla única por página: `AppPageHeader` → barra de filtros fija (periodo/sucursal/gestor) →
  `AppSectionCard`. Selector de periodo global persistido y breadcrumbs en drill-down.
- Dashboard: 2 KPI héroe (EBITDA y margen con variación y sparkline), fila de 6 compactos con un
  solo componente, salud de cartera por antigüedad, ranking de sucursales y tarjeta «Pendientes
  del periodo» que lleve a la acción.
- Partir `Preview.vue` en un componente por pestaña con carga diferida (`defineAsyncComponent` +
  `KeepAlive`) y un composable de alcance.
- Un solo servicio de diálogos (`ConfirmDialog.ts`) para Swal; formularios en shadcn `Dialog`.

## 5. Pruebas y operación

| Módulo | Pruebas aprox. | Comentario |
|---|---|---|
| OKR | ~278 | El mejor cubierto (incluye navegador). |
| Estado de Resultados | ~228 | Bueno; 2 pruebas MySQL fuera de CI. |
| Efectividad | ~177 | Bueno en el motor V12. |
| API v1 | ~126 | Por HTTP (incluye las nuevas de meses naturales). |
| Radiografía / Dashboard / alcance / periodos | ~340 | En SQLite; el SQL real de MySQL solo tiene 1 prueba en CI. |
| **Importadores** | muy pocas | `GastosImportService`, `GastosLendusPdfImportService`, `ImssExcelImportService`, `RotacionExcelImportService`, Lendus ministraciones/directorio y `ColumnMapService` **sin pruebas**: son la entrada de todos los KPIs. |

Otros: confirmar **InnoDB** en producción (en MyISAM las 154 FK y las transacciones no hacen nada);
`LOG_STACK=daily`/`LOG_LEVEL=warning`; docs de Efectividad aún dicen «V4 vigente» (el motor activo
es V12); `smalot/pdfparser: "*"` sin versión fija.

## 6. Lo que cambiaría (hoja de ruta propuesta)

**Semana 1 — contener riesgos:** repo privado + purga del historial; CI en verde; actualizar
phpspreadsheet/framework/npm; archivos subidos a disco privado; helper anti-fórmulas en todos los
exports; respaldo diario automático.

**Semanas 2-3 — una sola verdad:** `RecoveryRules` única con prueba de cuadre; `PeriodDataScope`
único; ciclo de vida común de jobs (`failed()`, candado por periodo, `retry_after` 3700, runs
vencidos); invalidación de caché por firma de entradas; exports pesados en cola.

**Mes 2 — mantenibilidad:** partir `RadiographySnapshotBuilder`, `RadiographyWorkbookBuilder` y
los controladores de 1,400-2,000 líneas por sección; pruebas de importadores con archivos
anonimizados; suite `tests/Integration` en CI contra MySQL con datos sintéticos.

**Mes 2-3 — UI:** corregir tema oscuro de gráficas; una sola tarjeta KPI; `AppPageHeader` en todas
las páginas; dividir `Preview.vue`; barrido de colores a tokens; lint de contraste y tamaño mínimo.

## 7. Aporte de esta sesión (23-nov-2026)

- **Nombre manual de periodos** sin perder información (`periods.custom_name`, pantalla Periodos,
  API con `custom_name`/`system_label`) — ver `docs/api-reporteria.md` §17.4.
- **API de meses naturales** (`/api/v1/calendar-months`): del 1 al último día por la fecha de cada
  movimiento, combinando los periodos de 4 semanas sin duplicar; con cobertura, cuadre y auditoría
  (§17). Verificado con datos reales: abril, mayo y junio 2026 cuadran al centavo (sucursales =
  días = colaboradores = total).
- **Limpieza del repo:** PDFs y Excel fuera del control de versiones e ignorados (`*.pdf`,
  `*.xlsx`, `*.xlsm`, `*.xls`).
- Esta auditoría.

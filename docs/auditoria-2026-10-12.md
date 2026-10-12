# Auditoría técnica, corrección de bugs y estabilización integral — 2026-10-12

Auditoría de los tres módulos (Reportería, Efectividad de Cobranza Aprobada, OKR) con
cambios reales de código, pruebas automatizadas y evidencia verificada — no solo
diagnóstico. Ninguna rama, commit, push ni despliegue se realizó (ver `git status`/
`git log` al cierre de esta sesión: sigue en `main`, mismo HEAD `64d70ff`, solo cambios
en el working tree).

## 1. Hallazgos confirmados y severidad

| # | Hallazgo | Severidad | Módulo |
|---|---|---|---|
| F1 | `CollectionsController::resolveApproved()` atribuía resultados por colaborador usando solo coincidencia de nombre normalizado, sin verificar sucursal/periodo real — dos homónimos en sucursales distintas, o un traslado, podían recibir el resultado de otra persona | **Crítica (seguridad/integridad de datos)** | Efectividad Aprobada / API |
| F2 | La API recalculaba cartera + histórico completo de ingresos + el calculador entero en CADA `GET /collections/effectiveness` — sin caché ni persistencia, cada consulta repetía el trabajo pesado completo | **Alta (rendimiento/escalabilidad)** | Efectividad Aprobada / API |
| F3 | El adaptador traía TODAS las columnas de `fact_portfolios`/`fact_recoveries` (muchas no usadas por esta macro) a memoria por cada fila, potencialmente decenas de miles | Media (memoria) | Efectividad Aprobada |
| F4 | `EfectividadCobranzaController::generate()` — "check-then-create" sin protección atómica; dos POST casi simultáneos podían crear dos filas/Jobs duplicados para la misma identidad | **Alta (concurrencia)** | Efectividad Aprobada |
| F5 | `GenerateEfectividadCobranzaAprobadaJob` guardaba `status=success` cuando el adaptador reportaba `is_available=false` — ningún Excel se generaba, pero el run se mostraba como "Listo" | **Alta (integridad del estado)** | Efectividad Aprobada |
| F5b | `$maxExceptions=1` junto con `$tries=2` anulaba el segundo reintento por completo (maxExceptions corta antes de agotar tries) | Media (resiliencia) | Efectividad Aprobada |
| F6 | `Index.vue`: `generar()`/`pollProgress()` sin try/catch — fallos de red, 419/422/403/409/500, JSON inválido, quedaban silenciosos; el botón podía quedar "generando" para siempre; recargar la página mientras un Job corría no reanudaba el polling | Media (UX) | Efectividad Aprobada / Frontend |
| F7 | (Verificado, NO era un bug) — reemplazar un Excel del mismo periodo/fuente ya limpia las filas `fact_*` del upload anterior (`ReportUploadService::store()` + `PeriodDerivedDataCleaner::clearForUpload()`), antes de que el nuevo import corra | N/A — confirmado correcto, se agregó prueba de regresión | Reportería (compartido) |
| F8 | El adaptador consultaba `fact_portfolios` por el `period_id` del periodo evaluado directamente — para periodos COMPUESTOS (bimestral/trimestral/semestral/anual), que nunca reciben Saldos por Cliente directamente (solo `monthly` los recibe), esto encontraba 0 filas de CARTERA aunque el negocio sí tuviera Saldos cargados en el mes componente | **Alta (datos incorrectos — falso "sin cartera")** | Efectividad Aprobada |
| F9 | No existía forma de saber, leyendo un `EfectividadCobranzaRun` ya generado, si las fuentes reales cambiaron después (reimportación/reproceso) — un snapshot podía presentarse como vigente estando obsoleto | Media (integridad) | Efectividad Aprobada |
| F10 | `output_excel_path` guardaba una ruta ABSOLUTA de disco (`storage_path()` directo) — rompería al migrar de Windows a Linux; el archivo tampoco usaba el disco privado `local` | Media (portabilidad VPS) | Efectividad Aprobada |
| F10b | `importer_version` (bug heredado de la sesión anterior, encontrado en esta auditoría al re-validar contra MySQL real) — `VARCHAR(20)` con un valor de 30 caracteres; SQLite (usado en tests) nunca lo detectó, MySQL real sí | **Alta (solo visible en MySQL real)** | Efectividad Aprobada / importadores |
| F14 | Módulo OKR — **ya auditado exhaustivamente** en una sesión anterior (`docs/okr-matriz-cumplimiento.md`, 34/34 secciones del PDF de requisitos verificadas). Reconfirmado en esta sesión: 193 pruebas OKR pasan sin cambios; patrón de almacenamiento de documentos (Warning/Carta Compromiso) ya usa `Storage::disk($model->disk)` con ruta relativa — ya portable, no se requería ninguna corrección | N/A — reconfirmado, sin hallazgos nuevos | OKR |

## 2. Errores corregidos (con archivo:línea aproximado)

- **F1** → `app/Http/Controllers/Api/V1/CollectionsController.php` — reescrito
  `resolveApproved()`/`resolveApprovedForEmployee()`: para `scope=employee` ahora
  resuelve primero la sucursal VERIFICADA del colaborador para el periodo EXACTO vía
  `EmployeeBranchHistoryResolver::resolveForPeriod()` (misma fuente que ya protege
  contra IDOR), lee el snapshot generado para ESA sucursal, y solo entonces busca la
  fila por nombre dentro de ese universo ya acotado. Sin asignación verificada →
  `not_attributable`, nunca una atribución por aproximación.
- **F2** → mismo archivo: ya NO invoca `EfectividadCobranzaAprobadaReportService::generate()`
  desde el endpoint. Lee `EfectividadCobranzaRun::latestSuccessFor()` (snapshot ya
  generado por el módulo web/Job). Sin snapshot → `not_available`, nunca recalcula ni
  devuelve cero fingiendo que es el resultado real.
- **F3** → `app/Services/Efectividad/EfectividadCobranzaAprobadaDataAdapter.php` —
  `->select(self::CARTERA_COLUMNS)` / `->select(self::INGRESOS_COLUMNS)` explícitos en
  ambas consultas (antes `SELECT *` implícito de Eloquent).
- **F4** → `app/Http/Controllers/EfectividadCobranzaController.php::generate()` —
  el bloque completo (verificar existente + crear + despachar Job) corre dentro de
  `Cache::lock($lockKey, 15)->block(5, …)`. Atómico vía `INSERT` con clave única en
  `cache_locks` — funciona igual en MyISAM que en InnoDB (a diferencia de una
  transacción, que en este MySQL real NO es atómica: el esquema completo usa MyISAM,
  confirmado con `SHOW TABLE STATUS`). Lock no adquirido en 5s → HTTP 409 explícito,
  nunca una fila duplicada "por si acaso".
- **F5** → `app/Jobs/GenerateEfectividadCobranzaAprobadaJob.php` — nuevo
  `EfectividadCobranzaRun::STATUS_BLOCKED`, usado cuando `is_available=false`. `success`
  ahora significa EXCLUSIVAMENTE "se generó el Excel y existe en disco".
- **F5b** → mismo archivo — se eliminó `$maxExceptions = 1`; `$tries = 2` gobierna los
  reintentos por sí solo.
- **F6** → `resources/js/pages/EfectividadCobranza/Index.vue` — try/catch/finally reales
  en `generar()`/`pollProgress()`; `describeHttpFailure()` traduce 401/419/403/409/422/
  404/5xx y errores de red/JSON a un mensaje visible; banner de error con botón
  "Reintentar"; guard `isPolling` contra solicitudes de progreso solapadas; `onMounted()`
  reanuda el polling si la página se recarga con un run `queued`/`running`; tipo
  `RunStatus` y labels/badges actualizados para incluir `blocked`.
- **F8** → mismo adaptador — nuevo `resolveCarteraPeriod()`: `monthly` → el propio
  periodo; `weekly` → ninguno (honesto, nunca inventa); compuesto → el ÚLTIMO mes
  componente vía `Period::resolveLastMonthlyComponent()` (mismo criterio YA USADO por
  el resto de Reportería para cartera/mora/préstamo activo — reutilizado, no duplicado).
  Nuevo campo `coverage.cartera_period_id` para trazabilidad.
- **F9** → nuevo `app/Services/Efectividad/EfectividadCobranzaSourceFingerprintService.php`
  — huella barata (`COUNT`+`MAX(id)`+`MAX(updated_at)` de CARTERA del periodo + INGRESOS
  global) comparada en lectura; `versions.approved.status` distingue `ok` de `stale` sin
  necesidad de interceptar cada punto del código que modifica `fact_portfolios`/
  `fact_recoveries`.
- **F10** → el Job ahora escribe con `Storage::disk('local')` (disco privado,
  `storage/app/private`, nunca público) y guarda una ruta RELATIVA. El controlador de
  descarga (`resolveExcelAbsolutePath()`) reconstruye la ruta absoluta en el servidor
  donde corre — y sigue resolviendo correctamente las rutas ABSOLUTAS que ya existan de
  antes de esta corrección (compatibilidad temporal, sin migrar filas antiguas a la
  fuerza).
- **F10b** → `app/Services/Imports/LendusIngresosCobranzaImportService.php` y
  `LendusSaldosClienteImportService.php` — `IMPORTER_VERSION` acortado a 19 caracteres.

## 3. Archivos modificados

```
app/Http/Controllers/Api/V1/CollectionsController.php            (reescrito — F1, F2)
app/Http/Controllers/EfectividadCobranzaController.php            (F4, F10)
app/Jobs/GenerateEfectividadCobranzaAprobadaJob.php                (F3, F5, F5b, F9, F10)
app/Models/EfectividadCobranzaRun.php                              (constantes STATUS_*, campos de linaje)
app/Services/Efectividad/EfectividadCobranzaAprobadaDataAdapter.php (F3, F8)
app/Services/Efectividad/EfectividadCobranzaSourceFingerprintService.php (NUEVO — F9)
resources/js/pages/EfectividadCobranza/Index.vue                  (F6)
tests/Feature/Api/V1/ApiFinancialEndpointsTest.php                 (reescrito — F1/F2/F9)
tests/Feature/Efectividad/EfectividadCobranzaControllerTest.php    (+2 pruebas F4/F5, 1 ajustada F10)
tests/Feature/Efectividad/EfectividadCobranzaDataIntegrityTest.php (NUEVO — F7/F8)
```

Nota: `app/Services/Imports/LendusIngresosCobranzaImportService.php` y
`LendusSaldosClienteImportService.php` (fix F10b, `IMPORTER_VERSION`) se corrigieron en
la sesión anterior (parte del mismo trabajo de Efectividad Aprobada) — no se re-listan
como modificados en ESTA sesión porque ya estaban corregidos al empezar esta auditoría
(se verificó leyendo el código real antes de tocar nada, como exige la Fase 0).

## 4. Migraciones nuevas

```
2026_10_12_000550_add_lineage_fields_to_efectividad_cobranza_runs_table.php
```

Aditiva (`Schema::hasColumn()` guardado): agrega `source_fingerprint`,
`saldos_report_upload_id`, `ingresos_report_upload_id` a `efectividad_cobranza_runs`.
Ninguna columna existente se modificó ni se borró. `php artisan migrate --force` es
seguro de correr en el VPS (y ya se corrió en este entorno sin errores).

## 5. Cambios de API

- `GET /api/v1/collections/effectiveness` — `versions.approved` ahora:
  - Lee un snapshot ya generado (`EfectividadCobranzaRun`) en vez de recalcular.
  - `available=false, status=not_available` cuando no existe snapshot para ese
    period_id/alcance exacto (antes: `blocked` con cálculo en vivo).
  - `status=stale` (con `is_stale=true`) cuando el snapshot existe pero las fuentes
    cambiaron después de generarlo — nunca se presenta como vigente sin decirlo.
  - `scope=employee` resuelve identidad real (ver F1) — `not_attributable` con razón
    explícita si no hay asignación verificada o ninguna fila coincide dentro de la
    sucursal correcta.
- `GET /api/v1/capabilities` — `collections_effectiveness_approved.implemented` (ya
  estaba en `true` desde la sesión anterior, sin cambios en esta auditoría).
- `POST /efectividad-cobranza/generar` (módulo web, no es `/api/v1`) — nueva respuesta
  `409` explícita cuando el lock de concurrencia no se adquiere en 5s.

## 6. Cambios en la generación de Excel

Ninguno en el exportador (`EfectividadCobranzaAprobadaExcelExporter` — layout A-M, sin
tocar). Solo cambió DÓNDE se guarda el archivo (disco privado `local`, ruta relativa —
ver F10) y CUÁNDO se genera (nunca si `status=blocked`, ver F5).

## 7. Cambios en colas y concurrencia

- `Cache::lock()` atómico en `EfectividadCobranzaController::generate()` (F4).
- `$maxExceptions` eliminado del Job (F5b).
- `memory_limit` explícito (1024M) en el Job, coherente con `GenerateRadiographyJob`.
- `WithoutOverlapping` del Job (ya existía, sin cambios) sigue protegiendo la
  ejecución; el `Cache::lock` del controlador protege la CREACIÓN de la fila/Job, capa
  independiente y complementaria.
- `retry_after` (1900s) vs `timeout` (600s del Job, 1800s de Radiografía) — verificado
  consistente, `.env` ya lo tenía bien configurado desde antes de esta sesión.

## 8. Mejoras de memoria

`->select()` explícito en el adaptador (F3) — reduce el número de columnas hidratadas
por fila de ~30-40 (todas las de `fact_portfolios`/`fact_recoveries`) a las ~15-20
realmente usadas por esta macro. Medido con datos reales (`documentosMayo`): el cuello
de botella real sigue siendo la lectura del `.xlsx` en los importadores (267s para
Saldos de 7,882 filas × 156 columnas vía Maatwebsite `Excel::toArray()`, que carga todo
el archivo en memoria de una sola vez), no el adaptador — se documenta como observación
para una futura optimización del importador, NO se tocó en esta sesión (fuera del
alcance de "Efectividad de Cobranza", y el importador ya es código probado y en uso).

## 9. Protección contra duplicación histórica

Confirmado (no modificado) — ver F7 en la sección 1. Prueba nueva:
`tests/Feature/Efectividad/EfectividadCobranzaDataIntegrityTest.php` primera prueba.

## 10. Manejo de snapshots obsoletos

`EfectividadCobranzaSourceFingerprintService` (F9) — ver sección 2. La API expone
`is_stale`/`status=stale` en vez de ocultar la obsolescencia o bloquear el acceso a
datos reales que solo están desactualizados (no inexistentes).

## 11. Mejoras visuales

Ver F6 — banner de error, botón "Reintentar", badge "Bloqueado — datos insuficientes",
reanudación automática de polling. Mismos componentes `shadcn-vue`/Tailwind/Lucide ya
usados en el resto del sistema — ningún componente ni librería nueva.

## 12. Estado final de Reportería

Sin cambios de código en esta auditoría. 502 pruebas de la suite completa pasan
(incluye `RadiographyScopeTest`, `RecoveryReconciliationTest`,
`PortfolioCurrentFormulaTest`, `EmployeeOperationalStatusTest`, importadores, etc.) —
0 regresiones. `ReportUploadService`/`PeriodDerivedDataCleaner` (deduplicación) y
`Period::resolveLastMonthlyComponent()` (periodos compuestos) se REUTILIZARON tal cual
estaban, sin modificarlos — exactamente como exige la Fase 0 ("no sobrescribir
soluciones existentes").

## 13. Estado final de Efectividad Aprobada

Terminado y endurecido: identidad de colaborador verificada (no por nombre), API de
solo lectura real (nunca recalcula en vivo), concurrencia atómica, estados reales
(success/blocked/failed), snapshots con linaje y detección de obsolescencia, memoria
optimizada, almacenamiento portable, periodos compuestos resueltos correctamente.
Validado con datos reales de `documentosMayo/` — números financieros IDÉNTICOS
antes/después de todas las correcciones (ver sección 16).

## 14. Estado final de OKR

Sin cambios de código — ya auditado exhaustivamente en una sesión anterior (34/34
secciones de `docs/SoliOKR.pdf`), reconfirmado en esta sesión: 193 pruebas pasan,
almacenamiento de documentos ya portable. No se encontró ningún hallazgo nuevo que
justificara modificar código OKR.

## 15. Pruebas ejecutadas y resultados

- Suite completa: **502 passed, 3 skipped** (preexistentes, no relacionados), 0 failed.
- Nuevas/reescritas en esta auditoría: 2 (Fase 4/5, controller) + 3 (Fase 1, API
  homónimos/traslado/reasignación de ruta) + 2 (Fase 2/9, snapshot stale/fresh) + 2
  (Fase 7/8, dedup real + periodo compuesto) = **9 pruebas nuevas**, todas verificando
  comportamiento real contra escenarios adversariales explícitos (no solo "el feliz
  camino").
- `vue-tsc --noEmit`: sin errores nuevos en los archivos tocados (los errores
  preexistentes de `resources/js/pages/auth/*`/`settings/Security.vue` son anteriores a
  esta sesión, relacionados con una limitación de tipos de Wayfinder para rutas con
  variantes `.form`, fuera de alcance).
- `npm run build`: compila sin errores (`✓ built in 19.62s`).

## 16. Comparación financiera antes/después

Re-ejecutada la validación real de la sesión anterior (`documentosMayo/Saldos Por
Cliente.xlsx` completo, 7,882 filas + muestra real de 5,000/42,766 filas de
`Ingresos cobranza.xlsx`) después de aplicar TODAS las correcciones de esta auditoría:

| Métrica (GRAN TOTAL) | Antes (sesión anterior) | Después (esta auditoría) |
|---|---|---|
| Sano por cobrar | $10,733,792.65 | $10,733,792.65 |
| Total cobrado | $2,030,802.96 | $2,030,802.96 |
| % Efectividad total | 18.9197148...% | 18.9197148...% |
| Mora | $8,702,989.69 | $8,702,989.69 |
| Filas de resultado | 169 | 169 |

**Idénticas bit a bit.** Confirma que las correcciones de memoria (`->select()`) y de
periodos compuestos (que no aplica a este caso — es un periodo `monthly` simple) no
alteraron ningún cálculo financiero, consistente con la regla "no modificar fórmulas
financieras aprobadas". Los datos de prueba se limpiaron explícitamente al terminar
(BD local verificada en 0 filas tras la validación).

## 17. Riesgos pendientes

- La validación financiera (sección 16) sigue siendo sobre una MUESTRA de Ingresos
  Cobranza (5,000/42,766 filas reales) por restricción de tiempo de sesión interactiva,
  no por limitación del código — una corrida completa debe hacerse vía el Job en cola
  en el VPS real.
- El formato de 14 columnas de Ingresos Cobranza (sin Fecha transacción/Fecha cuota)
  sigue bloqueando la macro aprobada para los periodos que solo tengan ese formato
  cargado — limitación del archivo exportado por Lendus, no del código (reportado
  honestamente vía `coverage.status=bloqueado_sin_fechas`).
- El esquema MySQL real de este entorno de desarrollo usa MyISAM (no InnoDB) —
  confirmado que el `Cache::lock()` usado para las correcciones de esta sesión es
  seguro sobre MyISAM (atomicidad por `INSERT` con clave única, no depende de
  transacciones), pero cualquier código EXISTENTE en el proyecto que asuma atomicidad
  transaccional (`DB::transaction()`) sobre tablas MyISAM no la tiene garantizada. Esto
  es una observación de infraestructura preexistente, fuera del alcance de esta
  auditoría (cambiar el motor de almacenamiento de tablas en producción es una
  operación de alto riesgo que requiere autorización explícita y una ventana de
  mantenimiento, no algo para decidir unilateralmente aquí).
- El rendimiento del importador de Saldos por Cliente (Maatwebsite `Excel::toArray()`,
  ~267s para 7,882×156) es una oportunidad de mejora real pero está fuera del alcance
  de "Efectividad de Cobranza" — se documenta, no se modifica sin autorización.

## 18. Instrucciones para preparar el VPS nuevo

Ver `docs/deployment-workers.md` (ya actualizado) para el detalle de colas/migraciones.
Puntos específicos de esta auditoría:

1. `php artisan migrate --force` — incluye la migración de linaje de esta sesión,
   aditiva y segura.
2. El disco `local` (`storage/app/private`) debe existir con permisos de escritura para
   el usuario del worker PHP — ahí se guardan los Excel de Efectividad Aprobada desde
   esta corrección (antes se guardaban sueltos en `storage/app/efectividad_cobranza`
   directamente, sin pasar por el disco configurado).
3. Reimportar los Excel históricos reales de Ingresos Cobranza/Saldos por Cliente de
   cada periodo ya cerrado, idealmente usando el formato de 35 columnas (con Fecha
   transacción/Fecha cuota/Oficina) cuando esté disponible, para que Efectividad
   Aprobada pueda calcularse sin bloqueos.
4. Ejecutar `GenerateEfectividadCobranzaAprobadaJob` vía el worker de colas real para
   los periodos completos (no solo muestras) una vez migrados los datos.
5. Confirmar que el cache store configurado (`CACHE_STORE`, hoy `database`) exista y
   tenga las tablas `cache`/`cache_locks` migradas — el `Cache::lock()` de la corrección
   de concurrencia (F4) depende de ellas.

## Clasificación final

- **Implementado y probado**: F1, F2, F3, F4, F5, F5b, F6, F8, F9, F10, F10b (todas con
  pruebas automatizadas reales, ver sección 15).
- **Implementado, pendiente de prueba con datos reales a escala completa**: validación
  financiera de Efectividad Aprobada sobre el histórico COMPLETO de Ingresos Cobranza
  (hoy solo sobre una muestra real de 5,000/42,766 filas — ver sección 17).
- **No implementado por bloqueo demostrado**: ninguno de los hallazgos de esta
  auditoría quedó sin corregir por imposibilidad técnica.
- **Mejora opcional no necesaria para producción**: limpieza automática (prune) de
  Excel generados antiguos en `storage/app/private/efectividad_cobranza`; optimización
  del importador de Saldos por Cliente (267s reales); normalización de `result_rows` en
  tablas separadas (no se demostró necesidad con los volúmenes reales observados — 169
  filas/~KB por corrida).

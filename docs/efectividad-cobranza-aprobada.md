# Efectividad de Cobranza — Aprobada

Estado (cierre 2026-10-11): **TERMINADO end-to-end.** Motor de cálculo (Fase 2, sesión previa),
esquema real, importadores, adaptador, módulo web, exportador Excel, cola, historial y API
quedaron conectados a datos reales en esta sesión. Recorrido real verificado por HTTP:
Subir archivos (ya existente) → Seleccionar periodo/sucursal → Generar → Ver resultados →
Descargar Excel. Ver `tests/Feature/Efectividad/EfectividadCobranzaControllerTest.php`.

> **Actualización 2026-10-12** — auditoría técnica integral (identidad de colaborador real,
> API de solo lectura real sin recálculo en vivo, concurrencia atómica, estados
> success/blocked/failed diferenciados, periodos compuestos, snapshots obsoletos,
> almacenamiento portable). Ver `docs/auditoria-2026-10-12.md` para el detalle completo —
> no se repite aquí para no duplicar fuentes.

## 1. Qué hizo esta sesión (Fase 1-14)

1. **Fase 1** — se inspeccionaron archivos REALES (no solo el `.xlsm` en blanco de la sesión
   anterior): `documentosColumnas/Ingresos cobranza 2026-05-20 A 2026-05-20.xlsx` (14 columnas,
   variante angosta de Lendus, **sin** Fecha transacción/Fecha cuota/Oficina), y
   `documentosMayo/`, `documentosJunio/`, `documentosEjemplo/` (`Ingresos cobranza.xlsx` +
   `Saldos Por Cliente.xlsx`), que resultaron ser la variante **completa** de 35 columnas con
   encabezado real en la fila 4, y SÍ incluyen Fecha transacción/Fecha cuota/Oficina/Fecha
   vencimiento. Conclusión: **Lendus exporta Ingresos Cobranza en al menos dos formatos
   distintos** — el motor y los importadores deben (y ya pueden) aprovechar las columnas que
   cada archivo real traiga, sin inventar ninguna que falte.
2. **Fase 2-4** — migraciones aditivas (`Schema::hasColumn` guardado, nunca destructivas):
   `fact_recoveries` ganó `installment_due_date`, `installment_number`, `contract_due_date`,
   `acreditado`, `office_name_raw`, `importer_version`; `fact_portfolios` ganó `status`,
   `substatus`, `first_payment_date`, `next_payment_date`, `contract_due_date`,
   `installment_amount`, `overdue_installments`, `total_overdue_raw`, `total_pending_raw`,
   `saldo_capital_vencido`, `saldo_interes_vencido`, `saldo_impuesto_vencido`,
   `saldo_cargo_calendario_vencido`, `saldo_impuesto_cargo_calendario_vencido`,
   `importer_version`. Ver matriz completa en la sección 3.
3. **Fase 5** — `ColumnMapService` y ambos importadores Lendus (Ingresos Cobranza / Saldos por
   Cliente) actualizados para poblar las columnas nuevas, SIN tocar ningún alias/columna que
   ya alimentara el histórico (`payment_date` sigue siendo exclusivamente "Fecha transacción";
   `balance`/`capital_due`/etc. no se tocaron). `client_code` (antes código muerto) ahora es un
   fallback real para `contract` cuando el Excel solo trae "Cliente".
4. **Fase 7** — `EfectividadCobranzaAprobadaDataAdapter` + `EfectividadCobranzaAprobadaReportService`:
   conectan `fact_portfolios`/`fact_recoveries` reales al `EfectividadCobranzaAprobadaCalculator`
   YA EXISTENTE (nunca se tocó su lógica ni se creó un segundo motor). Producen un diagnóstico
   de cobertura honesto (`coverage.status`) en vez de números silenciosamente incompletos.
5. **Fase 8-10** — módulo web nuevo e independiente, "Efectividad de Cobranza" en el menú
   lateral (`resources/js/config/mainNav.ts`, visible para admin/gerencial, igual que
   Reportería), pantalla `resources/js/pages/EfectividadCobranza/Index.vue`: filtros
   periodo/sucursal, botón "Generar reporte" con cola real (`GenerateEfectividadCobranzaAprobadaJob`,
   `EfectividadCobranzaRun`), progreso real por polling, tarjetas KPI, tabla A-M, banner de
   cobertura, historial, descarga de Excel real (`EfectividadCobranzaAprobadaExcelExporter`,
   sin cambios — ya existía y ya tenía pruebas).
6. **Fase 11** — `/api/v1/collections/effectiveness` → `versions.approved` ahora calcula en
   vivo contra el adaptador real; `available` nunca es una bandera global, se recalcula por
   period_id/scope exacto. `/api/v1/capabilities` → `collections_effectiveness_approved.implemented`
   pasó a `true` (verdad del sistema: el recurso existe y funciona); `available` por periodo
   sigue siendo un campo aparte, de la respuesta real del endpoint.
7. **Fase 12-13** — pruebas automatizadas (ver sección 4) + validación con datos reales
   (ver sección 5, incluye un bug real encontrado y corregido contra MySQL/MariaDB real que
   las pruebas con SQLite nunca hubieran detectado).

## 2. Reglas de negocio de la macro aprobada (sin cambios desde la sesión anterior)

El motor (`EfectividadCobranzaAprobadaCalculator`) no se tocó en esta sesión — toda la
documentación de reglas de negocio (whitelist de operaciones, fecha de reconocimiento,
denominador de porcentajes, reglas de CARTERA, reasignación de ruta, redondeo, agrupación)
permanece válida. Ver el historial de este archivo o los comentarios línea por línea del
propio calculador para el detalle completo — no se repite aquí para no duplicar una fuente que
ya existe y ya está verificada.

## 3. Matriz Excel fuente → Encabezado → Columna BD → Tipo → Uso → Validación

### 3.1 Ingresos Cobranza (`fact_recoveries`, vía `LendusIngresosCobranzaImportService`)

| Encabezado real (puede faltar según el formato Lendus) | Columna BD | Tipo | Uso | Validado |
|---|---|---|---|---|
| Promotor | `promoter_name` | string | Agrupación del reporte | Sí — ambos formatos reales |
| Contrato | `contract` | string | Identidad/resolución de sucursal | Sí — ambos formatos reales |
| Producto de crédito | `product_name` | string | Regla Savehearts CRECE | Sí |
| Operación | `operation` | string | Whitelist aprobada | Sí |
| Concepto | `concept` | string | Trazabilidad | Sí |
| Transacción | `transaction` | string | Whitelist aprobada | Sí |
| Capital / Interés / Impuesto / Cargos calendario / Cargos vencimiento / Excedente / Total | `capital`/`interest`/`tax`/`charges`/`charges_due`/`excedente`/`total_amount` | decimal | Importe cobrado | Sí |
| Dias vencidos pago | `days_past_due` | integer | Metadato (no usado por la macro aprobada) | Sí |
| Oficina | `office_name_raw` (nueva, antes solo vivía en `raw_payload`) | string | Resolución de ruta/reasignación de promotor | Sí — presente en el formato de 35 columnas; **ausente en el formato de 14 columnas** |
| Fecha transacción | `payment_date` (YA EXISTÍA) | date | `fTrans` — fecha de reconocimiento | Sí — presente en 35 cols; ausente en 14 cols |
| Fecha cuota | `installment_due_date` (NUEVA) | date | `fCuota` — clasificación Sano/Atraso/Vencido | Sí — presente en 35 cols; ausente en 14 cols. **Nunca se reconstruye** desde otra columna. |
| Número de pagos / cuota | `installment_number` (NUEVA) | string | Clave de desduplicación (fallback Sano) | Sí |
| Fecha vencimiento (fila) | `contract_due_date` (NUEVA) | date | Fallback de vencimiento por fila | Sí |
| Acreditado / Nombre Acreditado | `acreditado` (NUEVA) | string | Trazabilidad/clave de desduplicación | Sí (el alias toma la primera columna presente de las dos; ninguna se usa para un cálculo monetario, solo como parte de una llave compuesta) |
| — | `importer_version` (NUEVA) | string(20) | Trazabilidad del importador que generó la fila | Sí |

### 3.2 Saldos por Cliente (`fact_portfolios`, vía `LendusSaldosClienteImportService`)

| Encabezado real | Columna BD | Tipo | Uso | Validado |
|---|---|---|---|---|
| Cliente (fallback de Contrato) | `client_code` (mapeo) → `contract` | string | Identidad cuando no hay columna "Contrato" literal | Sí |
| Nombre promotor / Nombre del cliente / Producto de crédito / Días Vencido | `promoter_name`/`client_name`/`product_name`/`days_past_due` | — | Ya existían | Sí |
| Saldo capital | `balance` (alias `saldo_capital`, **sin cambios**) | decimal | Valor cartera histórico | Sí |
| Saldo capital (mismo valor ya resuelto) | `saldo_capital_vencido` (NUEVA, copiada en el importador, no vía un segundo alias) | decimal | Insumo `cSaldoCapital` de la macro aprobada | Sí — ver nota en la migración: nunca se le quitó el alias a `balance` |
| Saldo interés / Saldo impuesto / Saldo cargos calendario / Saldo impuesto cargos calendario | `saldo_interes_vencido`/`saldo_impuesto_vencido`/`saldo_cargo_calendario_vencido`/`saldo_impuesto_cargo_calendario_vencido` (NUEVAS) | decimal | Insumos Vencido-por-cobrar de la macro aprobada | Sí |
| Capital atrasado / Interés atrasado / etc. | `capital_due`/`interes_atrasado`/etc. (YA EXISTÍAN, **sin cambios**) | decimal | Mora histórica (Atraso-por-cobrar VIGENTE) | Sí — semánticamente distintas de "Saldo X" |
| Estatus | `status` (NUEVA) | string(60) | Exclusión CERRADO/INACTIVO | Sí |
| Substatus | `substatus` (NUEVA) | string(60) | VIGENTE/VENCIDO | Sí |
| Fecha primer pago / Fecha siguiente pago | `first_payment_date`/`next_payment_date` (NUEVAS) | date | Ancla de proyección de cuotas Sano | Sí |
| Fecha vencimiento | `contract_due_date` (NUEVA, distinta de `portfolio_date`) | date | Vencimiento del contrato | Sí |
| Cuota total / Cuota | `installment_amount` (NUEVA) | decimal | Monto de cuota proyectada | Sí |
| Pagos atrasados | `overdue_installments` (NUEVA) | integer | Guard de la regla Atraso-por-cobrar | Sí |
| Total atrasado / Total pendiente | `total_overdue_raw`/`total_pending_raw` (NUEVAS) | decimal | Vencido-por-cobrar + regla "$0.80 de Eudy" | Sí |
| Pagos / Periodicidad | `num_payments`/`periodicity` (YA EXISTÍAN EN BD, **nunca se mapeaban** — bug real corregido) | integer/string | Proyección de cuotas Sano | Sí |
| — | `importer_version` (NUEVA) | string(20) | Trazabilidad | Sí |

Columna ambigua `"."` (índice 43 del archivo de 156 columnas) — **deliberadamente sin mapear**
(regla "rechace encabezados ambiguos"), aunque su patrón de valores se parecía a un contrato.

## 4. Pruebas automatizadas (Fase 12)

- `tests/Feature/EfectividadAprobadaImportersTest.php` (4) — Ingresos Cobranza preserva Fecha
  transacción ≠ Fecha cuota; el formato real de 14 columnas no inventa fechas; Saldos por
  Cliente copia "Saldo capital" a `saldo_capital_vencido` sin romper `balance`/`capital_due`
  históricos; fallback `client_code` → `contract`.
- `tests/Feature/Efectividad/EfectividadCobranzaAprobadaReportServiceTest.php` (3) — adaptador
  real: `bloqueado_sin_fechas` cuando Ingresos pasa whitelist pero no trae ambas fechas; cálculo
  real no vacío cuando sí las trae; `sin_cartera` cuando no hay Saldos para el periodo.
- `tests/Feature/Efectividad/EfectividadCobranzaControllerTest.php` (3) — recorrido HTTP
  completo (abrir módulo → generar vía cola real → ver progreso/resultados reales → descargar
  un XLSX real y no vacío); 403 para colaborador; deduplicación de generaciones concurrentes.
- `tests/Feature/Api/V1/ApiFinancialEndpointsTest.php` — actualizado: `versions.approved`
  bloqueado real por falta de datos de ESE periodo (no una bandera global), y disponible con
  números reales cuando el periodo/sucursal sí tiene datos suficientes; `capabilities.
  collections_effectiveness_approved.implemented = true`.
- Suite completa del proyecto: **493 passed, 3 skipped** (los 3 skipped son preexistentes, no
  relacionados). Ninguna prueba de efectividad histórica, radiografía, API, OKR o importadores
  cambió su resultado.

## 5. Validación con datos reales (Fase 13) — qué se logró y qué queda honestamente pendiente

### 5.1 Validación real ejecutada en esta sesión

Se corrieron los importadores REALES contra los archivos REALES de `documentosMayo/`
(5,000 de las 42,766 filas reales de `Ingresos cobranza.xlsx` — muestra real, no sintética, acotada
solo por tiempo de sesión interactiva; el archivo `Saldos Por Cliente.xlsx` se procesó
COMPLETO: 7,882 filas), dentro de un periodo de prueba, y se generó el reporte con el
adaptador + calculador reales. Resultado real obtenido (GRAN TOTAL):

```
coverage.status: "ok"  (7,882 filas CARTERA; 5,000 Ingresos, 4,786 pasan whitelist y son utilizables)
Sano por cobrar:    $10,733,792.65
Total cobrado:       $2,030,802.96
% Efectividad total: 18.9%
Mora:                $8,702,989.69
169 filas de resultado (promotores + totales por oficina + Gran Total)
```

**El 18.9% NO debe leerse como la efectividad real de mayo 2026** — es consistente con haber
cargado solo ~11.7% de las filas reales de Ingresos (5,000/42,766) contra el 100% de CARTERA;
el `cobrado` está proporcionalmente sub-contado. Esto es evidencia de que el pipeline calcula
**correctamente y de forma proporcional** sobre datos reales, no evidencia del KPI real del
periodo. Una corrida completa (las 42,766 filas) requiere un worker de cola real (Fase 10 ya
lo soporta) — en esta sesión interactiva habría excedido el tiempo disponible. Los datos
quedan limpiados (no se dejó ninguna fila de prueba en la base de datos).

### 5.2 Bug real encontrado y corregido gracias a esta validación

`importer_version` se declaró `VARCHAR(20)` en la migración, pero el valor inicial elegido
(`'2026.10.efectividad-aprobada-1'`, 30 caracteres) la excedía. **Las pruebas automatizadas
(SQLite en memoria) nunca lo detectaron** porque SQLite no valida longitud de `VARCHAR` — el
error (`SQLSTATE[22001]: Data too long for column`) solo apareció al correr contra el MariaDB
real del entorno de desarrollo. Corregido acortando el valor a `'ea_aprobada_2026_10'` (19
caracteres) en ambos importadores. Esta es exactamente la clase de error que la regla de "usar
MySQL real" en las migraciones buscaba prevenir, y confirma que validar contra MariaDB real
—no solo contra SQLite— fue necesario.

### 5.3 Hallazgo adicional (no es un bug, es una observación de infraestructura)

La tabla `periods` (y aparentemente el resto del esquema) usa el motor **MyISAM** en el MariaDB
real de este entorno de desarrollo, no InnoDB — `DB::beginTransaction()/rollBack()` es un
no-op silencioso sobre tablas MyISAM. Esto no es nuevo de esta sesión ni se tocó aquí (está
fuera del alcance de "Efectividad de Cobranza"), pero es relevante dejarlo documentado: código
existente en el proyecto que asuma atomicidad transaccional (`DB::transaction`, etc.) no la
tiene garantizada sobre tablas MyISAM. No se modificó el motor de ninguna tabla en esta sesión
(sería una operación destructiva de alto riesgo fuera de lo solicitado) — se señala para que
quien administre el VPS lo evalúe.

### 5.4 Qué falta ejecutar al migrar al VPS

- Reimportar los Excel históricos reales (Ingresos Cobranza + Saldos por Cliente) de cada
  periodo ya cerrado — las migraciones aditivas de esta sesión son compatibles con filas
  existentes (todas las columnas nuevas son nullable), así que una reimportación simplemente
  llena los campos nuevos sin afectar lo que ya existía.
- Ejecutar `GenerateEfectividadCobranzaAprobadaJob` vía el worker de colas real (ya configurado
  en el proyecto, ver `docs/deployment-workers.md`) para los periodos completos — esta sesión
  solo validó con una muestra por restricciones de tiempo interactivo, no por una limitación
  del código.
- Confirmar con el negocio los números de al menos un periodo real completo contra lo que
  ellos ya calculan manualmente (si lo hacen) — ninguna autoridad externa al código validó los
  montos reales de un periodo completo todavía.

## 6. Bloqueos externos verdaderos que subsisten

- El formato de 14 columnas (`documentosColumnas/Ingresos cobranza 2026-05-20 A 2026-05-20.xlsx`)
  **no permite calcular Efectividad Aprobada** para ningún periodo que solo tenga ese formato
  cargado — le faltan Fecha transacción, Fecha cuota y Oficina, y la macro aprobada nunca las
  reconstruye desde otra columna. Esto no es un defecto de Reportería: es una limitación del
  propio archivo exportado por Lendus para ese caso. El sistema lo reporta honestamente
  (`coverage.status = "bloqueado_sin_fechas"`) en vez de mostrar un 0% engañoso.
- No se validó contra una corrida de EFECTIVIDAD ya calculada por el negocio para un periodo
  real completo (sigue sin existir una muestra de ese tipo, ver sección 5.4).

## 7. Explícitamente NO se hizo

- No se ejecutó ninguna macro VBA.
- No se creó un segundo motor de cálculo — `EfectividadCobranzaAprobadaCalculator` es el mismo
  de la sesión anterior, sin cambios.
- No se modificó la efectividad de cobranza histórica (`RadiographySnapshotBuilder::
  buildEfectividadCobranza()`) ni sus pruebas (`EfectividadCobranzaPctTest` sigue pasando sin
  cambios).
- No se tocó `balance`/`capital_due`/`interes_atrasado`/etc. existentes, ni sus alias en
  `ColumnMapService` — todas las columnas nuevas son adicionales.
- No se conectó con Lendus directamente (API/BD/servidores) — la única fuente operativa sigue
  siendo los Excel que el usuario ya carga manualmente en Reportería.
- No se creó un tercer Excel obligatorio.
- No se dejaron datos de prueba en la base de datos tras la validación de la sección 5.

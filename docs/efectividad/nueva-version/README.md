# Efectividad de Cobranza V4 — motor vigente

**Motor vigente:** `mrlana-v4-2026-10` (`App\Services\Efectividad\V4\EfectividadV4Calculator`),
puerto fiel de `Módulo1.bas` de `MR_LANA_EFECTIVIDAD.xlsm`.

**Solo compatibilidad histórica:**
- `aprobada-2026-10`: `EfectividadCobranzaAprobadaCalculator`, construida a partir de
  `CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm`. Sus ejecuciones se conservan y se
  muestran como **obsoletas**; ni la UI ni la API las presentan como vigentes.
- "Efectividad histórica" de Radiografías (`sections.efectividad_cobranza`, API
  `versions.legacy`): es otro indicador, no se tocó y nunca se mezcla con V4.

> El libro `docs/efectividad/MR_LANA_EFECTIVIDAD.xlsm` contiene información financiera real
> y datos personales. Queda **fuera del control de versiones** (`.gitignore`) y esta
> documentación no reproduce ninguno de sus datos: solo estructuras, reglas y conteos.

Índice:

1. [Inventario del XLSM](#1-inventario-del-xlsm)
2. [Procedimientos VBA](#2-procedimientos-vba-extraídos)
3. [Columnas de cartera (156)](#3-fotografías-de-cartera--156-columnas) → detalle en [`columnas-cartera.md`](columnas-cartera.md)
4. [Mapeo de Ingresos](#4-mapeo-de-ingresos-cobranza)
5. [Reglas de fotografías](#5-reglas-de-fotografías)
6. [Modos de cálculo y fechas](#6-modos-de-cálculo-y-fechas)
7. [Reglas de cálculo y exclusiones](#7-reglas-de-cálculo-y-exclusiones)
8. [Auditoría (`_AUDITORIA_EFECTIVIDAD`)](#8-auditoría-de-incidencias)
9. [Matriz de reglas: calculadora anterior vs. V4](#9-matriz-de-reglas-calculadora-anterior-vs-v4)
10. [Diferencias financieras esperables](#10-diferencias-financieras-esperables)
11. [Paridad y pruebas](#11-paridad-y-pruebas)
12. [Estructura de base de datos](#12-estructura-de-base-de-datos)
13. [Versionado, vigencia y permisos](#13-versionado-vigencia-y-permisos)
14. [Limitaciones conocidas](#14-limitaciones-conocidas)

Manual de uso para el usuario: [`manual-usuario.md`](manual-usuario.md).

---

## 1. Inventario del XLSM

Libro de referencia (12-oct-2026). Corte SEMANAL, domingo base 04/10/2026, corte
11/10/2026, ingresos del 05/10 al 11/10/2026.

| Hoja | Filas de datos | Columnas | Papel en la macro |
|---|---:|---:|---|
| `EFECTIVIDAD` | 31 filas de resultado (15 promotores, 15 totales de oficina y GRAN TOTAL) | A-M + control P-Q | Resultado y control de corte (`Q2` modo, `Q3` domingo base, `Q4` corte) |
| `CARTERA DOMINGO` | 1,010 | 156 | Fotografía del domingo base |
| `CARTERA LUNES` | 1,000 | 156 | Fotografía diaria |
| `CARTERA MARTES` | 956 | 156 | Fotografía diaria |
| `CARTERA MIERCOLES` | 957 | 156 | Fotografía diaria |
| `CARTERA JUEVES` | 960 | 156 | Fotografía diaria |
| `CARTERA VIERNES` | 961 | 156 | Fotografía diaria |
| `CARTERA SABADO` | 961 | 156 | Fotografía diaria |
| `CARTERA DOMINGO CORTE` | 961 | 156 | Fotografía del corte; de ella salen los stocks |
| `INGRESOS` | 2,824 | 35 | Ingresos del corte (mapa de responsables de ruta) |
| `_HIST_INGRESOS` | 7,628 | 35 | Historial acumulado (anticipos a fecha de cuota) |
| `_AUDITORIA_EFECTIVIDAD` | 237 incidencias | 7 | Bitácora; en este ejemplo las 237 son `NO-CARTERA` |

Módulos VBA: `Módulo1` (cálculo, 2,991 líneas) y `Módulo2` (imagen para WhatsApp). Las hojas
`Hoja1..12` y `ThisWorkbook` no contienen código. La copia del código está en
[`vba/`](vba/). Se extrajo de forma estática y **nunca se ejecutó**.

## 2. Procedimientos VBA extraídos

| Procedimiento (`Módulo1`) | Equivalente PHP | Nota |
|---|---|---|
| `Generar_Efectividad_Cobranza` | `EfectividadV4Calculator::calculate()` | Validaciones 901-910 y 940, orden de procesos |
| `MR_AutoconfigurarCorte`, `ValidarFechasIngresos`, `MR_LeerFechaMexicana` | `EfectividadV4DataAdapter::window()` y `availability()` | En Reportes el periodo semanal define modo/fechas; los `MsgBox` se convierten en diagnósticos y en la confirmación de "semana completa" |
| `ActualizarHistorialIngresos` | `EfectividadV4DataAdapter::ingresosFilas()` | Una fecha de transacción proviene de una sola carga (la más reciente) |
| `CrearMapaResponsableRuta` / `ObtenerPromotorRuta` | `crearMapaResponsableRuta()` / `obtenerPromotorRuta()` | Promotor con más movimientos de la ruta en el rango reportado |
| `ComplementarResponsables` | `complementarResponsables()` | Rutas sin cobros: promotor predominante de la foto del corte |
| `ProcesarFoto` | `procesarFoto()` | Estado diario, stocks, cuotas Sano, `FOTO-DUP`, `PROM`, `FECHA`, error 920 |
| `ProgramarCuotas` / `SiguienteFechaCuota` / `DescartarCuotasFuturas` | mismos nombres | Periodicidad DIARIA/QUINCENAL/CATORCENAL/14/MENSUAL/semanal; cambios de firma solo hacia adelante |
| `ProcesarCobros` | `procesarCobros()` | Clasificación Sano/Atraso/Vencido, `NO-CARTERA`, `GRP`, `CUOTA`, `DUP` |
| `GenerarReporte`, `ObtenerGruposOrdenados`, `GrupoTieneDatos`, `EscribirSubtotal`, `EscribirGranTotal` | `generarReporte()` / `fila()` | Orden oficina+promotor normalizados, totales y GRAN TOTAL |
| `GenerarBitacora` | `incidenciasEstructuradas()` + `EfectividadV4ExcelExporter::hojaAuditoria()` | Mismo id, texto y orden |
| `AplicarSemaforoEfectividad`, `AplicarColorMora`, `PrepararHojaEfectividad` | `EfectividadV4ExcelExporter` | Semáforo ≥95% verde, ≥90% amarillo, resto rojo (solo con Sano por cobrar > 0); mora <0 verde, >0 roja |
| `Normalizar`, `Numero`, `Positivo`, `LeerFecha`, `ColumnaEncabezado`, `SumarDic`, `RegistrarGrupo`, `MovimientoExcluido` | helpers homónimos | Misma semántica, incluido `CLng` con redondeo bancario |
| `Módulo2`: `Generar_Imagen_Para_WhatsApp`, `Copiar_Reporte_Como_Imagen` | — | No se porta: es una utilidad de Excel de escritorio |

Precisión: VBA evalúa `a + b + c` en la FPU x87 con precisión extendida y redondea una sola
vez. PHP redondea en cada suma parcial. `sumaVba()` lo reproduce con suma compensada. Sin
ese ajuste, 350.79 + 58.8 + 9.41 daría `419.00000000000006` en lugar de `419`.

## 3. Fotografías de cartera — 156 columnas

Cada fotografía es el reporte **Saldos por Cliente** de Lendus (156 columnas). El mapa
completo, columna por columna, está en [`columnas-cartera.md`](columnas-cartera.md):

- 22 columnas intervienen en el cálculo. Son obligatorias (error 920 si faltan) `Oficina`,
  `Nombre promotor`, `Fecha primer pago`, `Fecha vencimiento`, `Cuota total`,
  `Periodicidad`, `Pagos` y `Substatus`. `Contrato` es obligatoria para reconocer el
  encabezado (error 908).
- 5 se guardan solo para diagnóstico.
- 1 (`Fecha última transacción`) valida la fecha declarada de la fotografía.
- 17 contienen datos personales (nombres, direcciones, teléfonos, avales, RFC) y **no se
  importan**.
- 111 no se usan.

Una columna **ausente** no equivale a una columna con cero; así razona la macro. Por eso se
guarda la lista de encabezados presentes (`portfolio_snapshots.headers`).

## 4. Mapeo de Ingresos Cobranza

Fuente: Excel **Ingresos Cobranza** de Lendus (35 columnas), cargado como siempre en
Histórico General. `LendusIngresosCobranzaImportService` lo guarda en `fact_recoveries`.

| Campo de la macro | Encabezado Lendus | Columna `fact_recoveries` | Obligatoria (error 940) |
|---|---|---|---|
| `oficina` | Oficina | `office_name_raw` | Sí |
| `promotor` | Promotor | `promoter_name` | — (mapa de ruta) |
| `contrato` | Contrato | `contract` | Sí |
| `acreditado` | Acreditado | `acreditado` | — |
| `operacion` | Operación | `operation` | Sí |
| `transaccion` | Transacción | `transaction` | Sí |
| `fecha_transaccion` | Fecha transacción | `payment_date` | Sí |
| `fecha_cuota` | Fecha cuota | `installment_due_date` | Sí |
| `fecha_vencimiento` | Fecha vencimiento | `contract_due_date` | — |
| `capital` / `interes` / `impuesto` | Capital / Interés / Impuesto | `capital` / `interest` / `tax` | Sí |
| `cargos_calendario` | Cargos calendario | `charges` | — |
| `producto` | Producto de crédito | `product_name_raw` (texto original; respaldo: `product_name`) | — |
| `concepto` | Concepto | `concept` | — |
| `numero_cuota` | Número cuota | `installment_number` | — (llave de duplicados) |

No se usan `Cargos vencimiento` (moratorios), `Cargos de inicio`, `Cargos por evento`,
`Pago adelantado`, `Excedente` ni `Total`. Desde la columna `product_name_raw`
(migración `2026_10_13_090100`) se conserva el texto original del producto. Así la exclusión
por REESTRUCT/UNIFICAC/CONDONAC/COBERTURA usa el mismo texto que la macro.

## 5. Reglas de fotografías

- Se cargan en **Efectividad de Cobranza › Disponibilidad de información**, indicando la
  **fecha** de cada archivo (`POST /efectividad-cobranza/fotos`). La fuente
  `lendus_saldos_cliente_foto` **no aparece** en Histórico General y su carga ahí se rechaza:
  ese flujo pide mes, no fecha.
- Una semana SEMANAL necesita **8** fotografías: domingo base (día anterior al lunes),
  lunes a sábado y domingo de corte. La del domingo de corte es la misma que servirá como
  domingo base de la semana siguiente.
- Una fotografía **nunca se inventa ni se sustituye** con la de otro día. Si falta alguna,
  la ejecución queda **bloqueada** y se indica el nombre y la fecha exactos
  (`EC-V4-FOTO-FALTANTE`).
- **Fecha declarada vs. contenido.** Si el archivo trae una `Fecha última transacción`
  posterior a la fecha declarada, se rechaza ("Fecha incompatible"). Si la más reciente es
  más de 2 días anterior, se acepta con un aviso.
- **Misma fecha, mismas sucursales:** la nueva fotografía sustituye a la anterior
  (`status = replaced`, `replaced_by_id`). Las filas de la anterior **se conservan**.
  Con sucursales distintas, ambas quedan activas.
- **Mismo archivo en dos fechas** (mismo SHA-1): bloquea la semana
  (`EC-V4-FOTO-DUPLICADA`).
- Reprocesar el mismo upload reemplaza sus propias filas, sin duplicarlas. Una carga
  fallida no altera la fotografía vigente: la importación corre en una transacción.
- Las fotografías se guardan en `report_uploads` con `snapshot_date`. Un *global scope*
  (`ExcludePortfolioSnapshotUploadsScope`) las oculta de **todo** el flujo mensual:
  Histórico General, reemplazos por periodo, cierre y descargas. Solo
  `ReportUpload::snapshots()` las consulta. Las fotografías no generan filas en
  `fact_portfolios` ni en ninguna otra tabla `fact_*` de Radiografías.

## 6. Modos de cálculo y fechas

| | SEMANAL | DIARIO |
|---|---|---|
| Periodo en Reportes | Periodo `weekly` lunes-domingo | El mismo periodo semanal + fecha de corte (lunes…domingo de esa semana) |
| Domingo base | `start_date − 1 día` | igual |
| Fecha de corte | `end_date` (domingo) | la indicada |
| Fotografías requeridas | 8 | del domingo base al día de corte (2 a 8) |
| Ingresos del rango reportado | lunes a domingo | solo el día de corte |
| Denominador Sano | cuotas programadas de la semana | cuotas programadas de ese día |
| Stocks Vigente con atraso / Vencido | foto del corte | foto del día de corte |

Los periodos mensuales y bimestrales **no** se calculan con V4: la macro no define cómo
agregar semanas y no se inventa una regla (`EC-V4-PERIODO`).

Fechas: `LeerFecha` acepta fechas reales, seriales de Excel mayores a 1 y texto `dd/mm/aaaa`
o `aaaa-mm-dd`. Las fechas imposibles (31/02) se tratan como vacías. La periodicidad mensual
usa `DateAdd("m")`: 31/01/2028 da 29/02/2028 y 31/08 da 30/09 y luego 30/10, sin recuperar
el día 31.

## 7. Reglas de cálculo y exclusiones

Leyenda que imprime la hoja: **"Anticipos a fecha cuota | Reno solo su semana | Vencido/atraso
segun foto diaria | Sin moratorios"**.

- **Whitelist:** solo cuentan `PAGO A CONTRATO / PAGO` y `REFINANCIAMIENTO / DESCUENTO`.
- **Importe** = capital + interés + impuesto + cargos calendario. Los moratorios no cuentan
  (`Cargos vencimiento` no se lee). Un importe menor o igual a 0 se ignora sin incidencia.
- **Anticipos a fecha cuota:** el pago se reconoce el día `max(Fecha transacción, Fecha cuota)`.
- **Reno solo su semana:** un `REFINANCIAMIENTO/DESCUENTO` cuenta solo si la transacción
  **y** la cuota caen dentro de la semana evaluada.
- **Vencido/atraso según foto diaria:** el estado sale de la fotografía del día de
  reconocimiento. Si el contrato no está en ella, se usa la última foto previa, nunca una
  futura. VENCIDO si el `Substatus` lo dice o si el reconocimiento es posterior a la
  `Fecha vencimiento`. ATRASO si ese día tenía `Total atrasado` > 0 (o `Pagos atrasados`
  > 0) o si se pagó después de la fecha de cuota. En otro caso, SANO.
- **Denominadores:** Sano por cobrar = cuotas programadas del rango reportado. Vigente con
  atraso y Vencido por cobrar = stocks de la **foto del corte** (no se suman días). Para
  Vencido: `Total atrasado`; si es 0, la suma de los componentes de saldo (con negativos);
  si sigue en 0, `Total pendiente`.
- **No generan cuotas Sano:** contratos VENCIDOS, CERRADOS o INACTIVOS, cuota menor o igual
  a 0, `Pagos` igual a 0, ni contratos sin `Fecha primer pago` (se usa `Fecha siguiente pago`
  si existe; si no, `FECHA`).
- **Productos excluidos** (cartera y pagos): textos que contengan REESTRUCT, UNIFICAC,
  CONDONAC o COBERTURA en producto o concepto. Un `Origen crédito = REFINANCIAMIENTO` **no**
  excluye.
- **Responsable de ruta:** el promotor con más movimientos de la ruta en el rango reportado
  se queda con **toda** la ruta. Sin cobros, el predominante de la foto del corte.
- **Grupo** = OFICINA + NOMBRE DEL PROMOTOR normalizados. Un mismo nombre en dos oficinas
  forma dos grupos distintos.
- **Porcentajes**: todos sobre el *Sano por cobrar* del grupo. Mora = Sano por cobrar −
  cobranza total.

## 8. Auditoría de incidencias

Bitácora `_AUDITORIA_EFECTIVIDAD`: se persiste en `efectividad_cobranza_run_incidents`, se
consulta paginada en la UI y se exporta en el XLSX y en CSV.

| Código | Cuándo | Tratamiento |
|---|---|---|
| `NO-CARTERA` | Pago de un contrato que no aparece en ninguna fotografía del periodo | Pago excluido |
| `CUOTA` | Pago puntual de un contrato vigente sin cuota programada verificable en la semana | Pago excluido |
| `DUP` | Mismo movimiento (contrato, fechas, operación, importe, número de cuota) repetido | Se cuenta una vez |
| `GRP` | Pago sin oficina ni responsable de ruta | Pago excluido |
| `FOTO-DUP` | Contrato/acreditado repetido en una fotografía | Se usa la primera fila |
| `PROM` | Contrato con oficina pero sin promotor ni responsable de ruta | Sin grupo |
| `FECHA` | Contrato sin fecha de primer pago ni de siguiente pago | Sin cuotas Sano |

Orden e identificador iguales a la macro (`CODIGO|contrato|referencia`). En Reportes, la
referencia es el `id` del movimiento en `fact_recoveries`, no la fila de `_HIST_INGRESOS`.

## 9. Matriz de reglas: calculadora anterior vs. V4

| Tema | Calculadora aprobada anterior (`aprobada-2026-10`) | V4 (`mrlana-v4-2026-10`) |
|---|---|---|
| Cartera | **Una** carga de Saldos por Cliente (`fact_portfolios`) | **8 fotografías diarias** con fecha (`fact_portfolio_snapshot_rows`) |
| Periodo | Rango libre derivado del periodo | Semana operativa lunes-domingo (SEMANAL) o un día (DIARIO) |
| Estado del contrato al cobrar | Sano si pagó a tiempo; vencido si pagó después del vencimiento del contrato | Estado de la **foto del día** de reconocimiento (VENCIDO / atraso del día / a tiempo) |
| Stocks atraso/vencido | De la cartera única | Solo de la foto del corte |
| Contrato fuera de cartera | Reconstruye el Sano con el propio pago | Incidencia `NO-CARTERA`, pago excluido |
| Renovaciones | REFINANCIAMIENTO/DESCUENTO en la whitelist | Solo si transacción **y** cuota caen en la semana |
| Productos REESTRUCT/UNIFICAC/CONDONAC/COBERTURA | No se excluían | Excluidos en cartera y pagos |
| Duplicados | No se detectaban | `DUP`, se cuenta una vez |
| Bitácora de exclusiones | No existía | `_AUDITORIA_EFECTIVIDAD` (7 códigos) |
| Cambios de condiciones a media semana | No aplica (una sola cartera) | Cuotas reprogramadas solo hacia adelante |
| Precisión de sumas | Double de PHP | Emula x87 de VBA (suma compensada) |
| Fecha de reconocimiento | `max(transacción, cuota)` | igual |
| Importe | capital + interés + impuesto + cargos calendario | igual |

## 10. Diferencias financieras esperables

Un mismo periodo calculado con la calculadora anterior y con V4 **no da lo mismo**, y eso es
correcto. V4 excluye productos reestructurados, renovaciones fuera de su semana, duplicados
y pagos sin cartera; además clasifica con el estado diario. Por eso cada ejecución guarda
`engine_version`, y la UI y la API nunca comparan ni mezclan versiones. Para comparar, se
regenera el periodo con V4.

## 11. Paridad y pruebas

Paridad contra el libro (12-oct-2026, re-ejecutada en el cierre):

| Prueba | Resultado | Duración |
|---|---|---|
| Motor puro con las hojas del libro (`EfectividadV4ReferenceParityTest`, 1ª) | **372/372 valores exactos** (31 filas × 12 columnas), mismo orden; **237/237 incidencias** con mismo id, texto y orden | ~3 s |
| XLSX exportado del cálculo anterior, releído celda por celda | 31 filas A-M idénticas bit a bit; 237 ids de incidencia en orden | (incluida arriba) |
| Punta a punta: XLSX con 156 / 35 columnas → importadores reales → BD → adaptador → motor (2ª) | 372/372 exactos; 237 incidencias (código, contrato y orden) | ~93 s en SQLite |
| Las dos pruebas anteriores sobre MySQL 8.4 InnoDB (BD aislada `reportes_v4_verify`) | 372/372 exactos y 237 incidencias en ambas | 20 s + 96 s |

Tolerancias declaradas en `EfectividadV4ParityComparator`: 1e-6 en montos y 1e-12 en
porcentajes. El resultado real es **igualdad exacta**. Las pruebas que usan el libro se
marcan con el grupo `referencia` y se omiten (`skip`) si el archivo no está en la máquina.

Cobertura sintética (sin datos personales), útil porque el libro solo ejercita `NO-CARTERA`:

| Archivo | Cubre |
|---|---|
| `tests/Unit/Efectividad/EfectividadV4CalculatorTest.php` (41) | Todas las reglas de las secciones 6-8: modos, fechas, periodicidades (incluye año bisiesto y cierre de mes), anticipos, renovaciones, estado diario, stocks, exclusiones, los 7 códigos de incidencia, importes 0/negativos, fechas inválidas, homónimos, cambios de firma y validaciones 901-940 |
| `tests/Unit/Efectividad/EfectividadV4ExcelExporterTest.php` (10) | Abre el XLSX: hojas, A-M, oficinas, totales, GRAN TOTAL, control P-Q, formatos, semáforos, mora, auditoría, FUENTES, texto con aspecto de fórmula |
| `tests/Feature/Efectividad/V4/CarteraFotoImportTest.php` (26) | Importador de fotografías (casos 1-20 del requerimiento) y aislamiento del flujo mensual |
| `tests/Feature/Efectividad/V4/EfectividadV4VersioningTest.php` (9) | Vigencia con cargas reales, identidades SEMANAL/DIARIO, Excel perdido, exportador fallido |
| `tests/Feature/Efectividad/V4/EfectividadV4SecurityTest.php` (11) | Permisos por endpoint, IDOR, CSV, API Sanctum, homonimia |
| `tests/Feature/Efectividad/V4/EfectividadV4WebFlowTest.php` (1) | Recorrido web completo por HTTP |

## 12. Estructura de base de datos

Migraciones aditivas (2026-10-13):

- `2026_10_13_090000_create_portfolio_snapshot_tables`
  - `report_uploads.snapshot_date` (date, null, índice).
  - `portfolio_snapshots`: una fila por archivo importado (`report_upload_id`, `period_id`,
    `snapshot_date`, `status` active/replaced, `rows_count`, `branch_ids` JSON, `offices`
    JSON, `headers` JSON, `header_row`, `source_columns`, `file_sha1`, `importer_version`,
    `replaced_by_id`, `imported_at`).
  - `fact_portfolio_snapshot_rows`: todas las filas con contrato, montos en DOUBLE sin
    redondear (índices `contract`, `snapshot_date`, `(snapshot_date, branch_id)`).
  - Fuente `lendus_saldos_cliente_foto` en `data_sources`.
- `2026_10_13_090100_add_engine_v4_fields_to_efectividad`:
  - `efectividad_cobranza_runs`: `engine_version`, `mode`, `base_date`, `cutoff_date`,
    `incidents_count`.
  - `fact_recoveries.product_name_raw`.
  - Tabla `efectividad_cobranza_run_incidents`.
- `2026_10_13_090200_add_foreign_keys_to_efectividad_v4_tables` (cierre): claves foráneas
  con `ON DELETE CASCADE` (filas → fotografía, fotografía → upload, incidencia → ejecución).
  Solo en MySQL/MariaDB InnoDB. No borra datos: si hay huérfanos, omite esa clave y avisa.

## 13. Versionado, vigencia y permisos

- Identidad de una ejecución V4: periodo + sucursal (o general) + `engine_version` + `mode`
  + `cutoff_date`. SEMANAL, DIARIO martes y DIARIO miércoles son tres resultados distintos.
- `source_fingerprint`: SHA-256 de las fotografías activas usadas (id + fecha de
  importación) y de los movimientos de Ingresos que pueden afectar el corte (conteo, id
  máximo y uploads). Si se sustituye una fotografía o se cargan o reprocesan Ingresos, el
  detalle marca `is_stale` (aviso `EC-VIG-FUENTES-CAMBIARON`) y la API responde
  `status=stale`. El resultado no se recalcula en lectura y no se borra.
- Regenerar crea una ejecución nueva; la anterior queda como `obsolete` con
  `superseded_by_id`.
- Permisos (web, sesión):
  - Colaborador: 403 en todo el módulo.
  - Gerencial: genera y abre **solo** las ejecuciones que solicitó o a las que quedó
    suscrito. Un ID ajeno responde 404 en detalle, progreso, XLSX, incidencias y CSV.
  - Admin: todas las ejecuciones.
  - Carga de fotografías: admin y gerencial, igual que Histórico General.
- API: `GET /api/v1/collections/effectiveness` con token Sanctum y ability
  `collections.read`. Solo entrega ejecuciones V4 SEMANAL exitosas (`approved_snapshot_v4`).
  Por colaborador, exige asignación de sucursal verificada en ese periodo y **exactamente
  una** fila con su nombre: dos homónimos en la sucursal dan `not_attributable`.
- El XLSX se guarda en el disco privado `local` (`storage/app/private/efectividad_cobranza`).
  El CSV neutraliza textos que empiezan con `= + - @`. Los mensajes al usuario no incluyen
  trazas ni rutas del servidor.

## 14. Limitaciones conocidas

- El libro de referencia solo ejercita `NO-CARTERA`. Los demás códigos están verificados
  contra el VBA con datos sintéticos, no contra un resultado real de la macro.
- V4 solo atribuye por **nombre de promotor** (igual que la macro). La API por colaborador
  añade identidad verificada de sucursal y unicidad de nombre, pero no puede distinguir dos
  personas con el mismo nombre en una misma oficina.
- Los Excel originales cargados (incluidas las fotografías) viven en el disco `public`,
  convención de todo Reportería. Los nombres de archivo son aleatorios, pero en el VPS se
  deben bloquear `/storage/reports/` y `/storage/report_uploads/` en Nginx (ver `docs/despliegue-vps-limpio.md`).
- Los comandos de auditoría y depuración que consultan `report_uploads` con Query Builder
  (`DB::table`) ven también las fotografías. Ninguno escribe sobre ellas, y las fotografías
  no tienen filas `fact_*`, así que no alteran sus cifras.
- Reprocesar un upload antiguo de una fecha lo vuelve la fotografía vigente de esa fecha:
  gana el último procesado.
- En Windows/WAMP no hay `pcntl`: un Job colgado se detecta por el health check, no por
  timeout.

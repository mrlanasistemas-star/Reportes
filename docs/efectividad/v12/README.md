# Efectividad de Cobranza — metodología V12.9.4

| | |
|---|---|
| Metodología | `V12.9.4` — "APORTE POR BUCKET CONTRA SANO" (`EfectividadV12Calculator::METODOLOGIA`) |
| Versión del motor | `mrlana-v12.9.4-2026-11` (`EfectividadV12Calculator::ENGINE_VERSION`) |
| Implementación | `app/Services/Efectividad/V12/` (fuente de verdad de este documento) |
| Orquestación | `app/Services/Efectividad/Calculadora/EfectividadCalculadoraService.php` (modos, mensual, historial, filtros) |
| Referencia funcional | la hoja de referencia V12.9.4 (archivo de Córdoba en `docs/efectividad/ultimaVersion/`) |
| Motor anterior | V4/V6/V7, documentado en [`../nueva-version/README.md`](../nueva-version/README.md) |
| Manual de usuario | [`../nueva-version/manual-usuario.md`](../nueva-version/manual-usuario.md) |

El motor no usa base de datos: recibe las filas de los Excel de la solicitud y devuelve
el resultado. La leyenda que imprime el reporte (`LEYENDA`) es:
*"PORCENTAJES: Cobrado de cada bucket / Sano por cobrar | D + G + J = Efectividad Total |
Sin doble cobro | TOTAL sin tope | Sin moratorios"*.

Principios de la metodología (encabezado de la hoja de referencia):

- Obligación **por cuota**, atraso histórico del corte inicial, refinanciamientos (cuotas
  del día y atrasadas, nunca futuras) y anticipos por vencimiento.
- **Sano por cobrar** = cuota neta exigible del periodo, sin anticipos anteriores.
- Atraso y Vencido: saldos que migran y deudas históricas, **sin cobrar doble**. Los tres
  *Por cobrar* son vistas de control y **no se suman** entre sí.
- Todos los aportes usan el **Sano por cobrar** como denominador.
- Lo que no puede asociarse a una cuota **no se inventa**. Los ajustes por saldo global no
  prueban la aplicación histórica exacta por cuota: se etiquetan como **estimados** y se
  trazan en amortizaciones y auditoría.
- La conciliación contra la foto de cierre **informa** diferencias; **nunca** altera cifras.

Tolerancias: `V12Vba::MARGEN = 0.005` (importe despreciable / "sin base") y
`V12Vba::CENTAVOS = 0.02` (cuadre de conciliaciones).

---

## 1. Etapas del motor (en orden)

`EfectividadV12Calculator::calculate()` ejecuta, sobre un `V12Corrida` (estado en memoria,
nunca persistido):

| # | Etapa | Clase / método |
|---:|---|---|
| 1 | Validación de parámetros, fotos requeridas, columnas obligatorias y ventana del periodo | `EfectividadV12Calculator::prepararCorrida()` |
| 2 | Gestor efectivo por contrato y día (`OFICINA~PROMOTOR`) | `EfectividadV12Calculator::leerFoto()` |
| 3 | Atraso del corte inicial por contrato | `EfectividadV12Calculator::cargarAtrasos()` |
| 4 | Tabla interna de amortizaciones desde las fotos | `V12Amortizaciones::generar()` |
| 5 | Carga de cuotas (índice por número y por fecha) | `V12Amortizaciones::leer()` |
| 6 | Aplicación de ingresos a cuotas (eventos) | `V12Amortizaciones::aplicarIngresos()` |
| 7 | Neto: anticipos por saldo DX:DZ de la foto base | `V12ConciliadorNeto::fotoBase()` |
| 8 | Neto: atraso individual de una cuota | `V12ConciliadorNeto::atrasoIndividual()` |
| 9 | Neto: anticipo intermedio de una única cuota parcial | `V12ConciliadorNeto::intermedio()` |
| 10 | Neto: diario de refinanciamiento completo vs DX:DZ | `V12ConciliadorNeto::cierresRefinanciamiento()` |
| 11 | Neto: última cuota mayor a la nominal | `V12ConciliadorNeto::ultimaCuota()` |
| 12 | Trazabilidad por cuota (fija el `previo` final; COMPROBADO / INFERIDO) | `V12Auditoria::trazabilidad()` |
| 13 | Buckets S/A/V, meta sana, Regla 24 | `V12Buckets::construir()` |
| 14 | Controles de consistencia | `V12Buckets::validar()` |
| 15 | Filas del reporte (promotor → TOTAL ruta → GRAN TOTAL), Regla 32 | `EfectividadV12Calculator::filasReporte()` / `construirFilas()` / `fila()` |
| 16 | Conciliación por contrato contra la foto de corte | `V12Auditoria::conciliarCartera()` |
| 17 | Estado del reporte PRELIMINAR / CONCILIADO | `V12Auditoria::resumen()` |

Con `conTraza = true` el resultado incluye además `amortizaciones` (traza por cuota),
`conciliacion` (por contrato) y `detalle_cuotas` (clasificación por cuota).

## 2. Entradas

### 2.1 Archivos

| Entrada | Índice / nombre | Uso |
|---|---|---|
| Fotografías de cartera (*Saldos por Cliente* de Lendus) | 0 `CARTERA DOMINGO` (base), 1 `CARTERA LUNES` … 6 `CARTERA SABADO`, 7 `CARTERA DOMINGO CORTE` | Índice = días desde el domingo base. Se exigen todas las del periodo (ver §3). |
| `INGRESOS` (*Ingresos Cobranza*) del corte | — | Validación de encabezados (`Contrato`, `Fecha transacción`, `Fecha cuota`). |
| Historial de ingresos | — | Movimientos del corte **y** de semanas previas, sin duplicar. Lo arma `EfectividadCalculadoraService::historial()`: los archivos se aplican del más antiguo al más reciente y cada uno sustituye sus combinaciones `contrato + fecha de transacción`; gana la carga más reciente. |

Los encabezados se resuelven por nombre (`V12ColumnMap::resolver()`, normalizado con
`V12Vba::norm`), no por posición. Las letras son la posición habitual en Lendus.

### 2.2 Columnas de las fotografías

| Letra | Encabezado | Uso | |
|---|---|---|---|
| — | Contrato, Oficina, Nombre promotor | Llave y gestor efectivo | **Obligatoria** |
| AX | Fecha primer pago | Calendario de cuotas | **Obligatoria** |
| AY | Fecha vencimiento | Fin de contrato (bucket V, cuotas posteriores, saldo total si ya venció) | Opcional |
| AZ | Cuota | Cuota **ordinaria** nominal (BA/BB no se suman) | **Obligatoria** |
| BW | Periodicidad | Diaria (L-V), quincenal (14 días), mensual (meses), semanal (7 días, por omisión) | **Obligatoria** |
| BX | Pagos | Número total de cuotas (1..500) | **Obligatoria** |
| CC / CG / CK | Saldo capital / interés / impuesto | Saldo insoluto ordinario; tope de la última cuota; atraso de contrato ya vencido | Opcional |
| DX:DZ | Capital / Interés / Impuesto pendiente | Saldo ordinario de pagos pendientes **sin atrasos** (validación de anticipos y refinanciamientos) | **Obligatoria** |
| ED | Pagos atrasados | Atraso individual (ED = 1) | Opcional |
| EE:EG | Capital / Interés / Impuesto atrasado | Atraso ordinario del corte inicial y de cada foto | Opcional |
| EN | Número siguiente pago | Cuotas anteriores a EN ya cubiertas (anticipo) | **Obligatoria** |
| — | Estatus | `CERRADO` / `LIQUIDADO` cancela cuotas posteriores; cierre en foto intermedia | Opcional |
| — | Pagos pendientes, Fecha siguiente pago | Condiciones de `fotoBase()` | Opcional |
| — | Cuota total, Total atrasado, Producto de crédito | Solo informativos | Opcional |

Falta de una obligatoria → `EC-V12-320`. Sin las opcionales, la etapa que las necesita se
omite (no se infiere nada).

### 2.3 Columnas de INGRESOS / historial

| Letra | Encabezado | Uso | |
|---|---|---|---|
| — | Contrato | Llave | **Obligatoria** |
| P | Operación | `PAGO A CONTRATO`, `REFINANCIAMIENTO`, `REESTRUCTURA`, `UNIFICACIÓN` | **Obligatoria** |
| Q | Concepto | Exclusiones por texto (SEGURO, COBERTURA, CONDONACIÓN) | Opcional |
| R | Transacción | `PAGO` / `DESCUENTO` | **Obligatoria** |
| S | Fecha transacción | Fecha del movimiento | **Obligatoria** |
| T | Fecha cuota | Cuota a la que se aplica | **Obligatoria** |
| U | Número cuota | Asociación por número; confirma fechas reales; requerida para cierres de refinanciamiento | Opcional (recomendada) |
| V:X | Capital / Interés / Impuesto | Importe = V + W + X (Y:AE, moratorios y cargos, **no** se suman) | **Obligatoria** |
| — | ID cuota, ID movimiento, Producto de crédito | Asociación directa, deduplicación por folio, exclusiones | Opcional |

Falta de una obligatoria → `EC-V12-940` (o `EC-V12-203` en el archivo del corte).

Movimientos reconocidos (`aplicarIngresos()`):

- `PAGO` con operación `PAGO A CONTRATO`, `REESTRUCT…`, `UNIFICAC…` o `REFINANC…`.
- `REFINANCIAMIENTO / DESCUENTO` **solo** sobre cuota del día o atrasada
  (`Fecha cuota ≤ Fecha transacción`). El de cuota futura no cuenta.
- `DESCUENTO` de reestructura o unificación: no cuenta.
- Excluidos siempre: textos con SEGURO, COBERTURA o CONDONAC en operación, transacción,
  producto o concepto. Importe ≤ 0.005: se ignora.
- Asociación: `ID cuota` → `Número cuota` → fecha de cuota (si dos cuotas vencen el mismo
  día la fecha es ambigua). Si la `Fecha cuota` no coincide con el calendario, el cobro no
  se reconoce.

## 3. Modalidades

`prepararCorrida()` exige que el domingo base sea domingo (`EC-V12-212`) y que el corte
caiga entre lunes y el domingo siguiente (`EC-V12-213`).

| Modalidad | Foto base (`fBase`) | Fotos leídas | Ingresos del periodo (`repIni`→`repFin`) | Meta sana |
|---|---|---|---|---|
| **SEMANAL** | Domingo base | 0..7 (las 8) | lunes → domingo de corte (el corte debe ser domingo base + 7, `EC-V12-206`) | Cuotas netas de la semana |
| **DIARIO** | Cierre del **día anterior** al corte (`fotoInicio = corte − domingo − 1`) | Solo la del día anterior y la del día de corte | el día de corte | Cuotas netas que vencen ese día |
| **ACUMULADO** | Domingo base | 0..corte | lunes → día de corte | Cuotas netas lunes → corte |
| **MENSUAL** | — | — | semanas completas del mes | Suma de las semanas |

- **ACUMULADO** es una extensión de la plataforma: la hoja de referencia define solo
  DIARIO y SEMANAL. Aplica exactamente la regla semanal con la ventana recortada al corte.
- **MENSUAL** (`EfectividadCalculadoraService::calcularMensual()`) tampoco existe en la
  hoja de referencia y no tiene paridad. Calcula cada semana **completa** (8 carteras)
  cuyo domingo de corte cae en el mes, con la regla SEMANAL, y suma **importes base** por
  promotor (nunca porcentajes):
  - Sano por cobrar, Sano cobrado, Atraso cobrado y Vencido cobrado: se suman.
  - Atraso y Vencido **por cobrar**: cobrado del bucket en **todas** las semanas +
    pendiente al corte de la **última** semana. Así una deuda no se cuenta una vez por
    semana.
  - Porcentajes y mora se recalculan desde los importes. Estado PRELIMINAR si alguna
    semana lo es; los contadores de auditoría se suman; incidencias sin duplicar por `id`.

Filtros Sucursal → Ruta → Promotor (`filtrar()`) y la agrupación por sucursal
(`conSucursal()`, catálogo `config/efectividad_rutas.php`) reconstruyen subtotales y gran
total con `construirFilas()` desde importes. Con una sola sucursal las filas quedan tal
como las produjo el motor.

## 4. Tabla interna de amortizaciones

`V12Amortizaciones::generar()` reconstruye las cuotas de cada contrato a partir de la
primera foto donde aparece (AX, AY, AZ, BW, BX, EN, CC:CK, EE:EG):

- Fecha de cuota `n` según la periodicidad (`siguienteCuota()`), salvo que un
  `PAGO A CONTRATO / PAGO` identificado por número confirme su fecha real.
- Solo cuotas con fecha ≤ corte y ≤ vencimiento del contrato.
- No se inventan cuotas anteriores al alta visible de un crédito nuevo (contrato que no
  está en la foto base).
- **Cancelación** (`cancela`): primera foto con estatus CERRADO/LIQUIDADO, primer día en que
  el contrato deja de aparecer (esto **no** lo hace liquidado ni cobrado), o fecha del primer
  movimiento de refinanciamiento/unificación/reestructura del periodo. Las cuotas posteriores
  no se programan, salvo que tengan fecha real por un pago ordinario.

Campos por cuota (`V12Corrida::$cuotas`, traza en `V12Auditoria::$traza`):

| Campo | Contenido |
|---|---|
| `contrato`, `numero` | Llave `contrato|numero` |
| `fecha` | Vencimiento de la cuota |
| `matura` / `vencimiento_contrato` | Fin del contrato |
| `monto` / `cuota` | Cuota nominal ordinaria (AZ; la última puede corregirse, §5.5) |
| `previo_reconstruido` | Pagado antes del periodo según las fotos (EN / saldo última cuota) |
| `historico` | Ingresos aplicados a la cuota con fecha ≤ foto base |
| `estimado` | Importe inferido por conciliación (§8) |
| `previo` | Pagado antes del periodo final = `min(cuota, max(reconstruido, histórico))` + ajustes |
| `neto` | `cuota − previo` (0 en cuotas históricas; acotado en cuota futura cancelada) |
| `movimientos` | Cobros del periodo aplicados a la cuota |
| `saldo` | `neto − movimientos` |
| `cancelacion`, `fecha_real` | Cambio contractual y si la fecha la confirmó un pago |
| `evidencia`, `observacion` | Origen del neto (§8) |
| `calidad` | `COMPROBADO` o `INFERIDO` |
| gestor / clasificación | En `detalle_cuotas`: `meta`, `gestor_meta`, `cobros[]` (fecha, bucket, gestor, importe), `pendiente` (bucket, gestor, importe), `transferencias[]` |

## 5. Sano por cobrar y anticipos

### 5.1 Definición exacta

Por cuota con `fBase < fecha ≤ repFin` y `repIni ≤ fecha ≤ repFin`:

```
previo = min(cuota, max(previo reconstruido y conciliado, Σ ingresos de la cuota con fecha transacción ≤ fBase))
neto   = max(0, cuota − previo)
si la cuota es futura cancelada (cancela < fecha):  neto = min(neto, abonos ordinarios reales entre repIni y cancela)
Sano por cobrar (gestor) = Σ neto, asignado al gestor responsable en la fecha de la cuota
```

Los cobros del propio periodo **nunca** reducen la meta. Una cuota sin gestor ese día
(`SIN-GESTOR`) no tiene responsable.

### 5.2 Anticipos — fuentes (se toma el **máximo**, nunca la suma)

1. **EN** (número siguiente pago de la foto base): cuotas `n < EN` con fecha posterior a la
   base se dan por cubiertas (`origen = NUMERO SIGUIENTE PAGO`).
2. **Última cuota**: nunca se exige más que el saldo ordinario futuro de la foto base
   `(CC+CG+CK) − (EE:EG)` (`SALDO ULTIMA CUOTA`).
3. **Historial**: pagos aplicados a la cuota con fecha de transacción anterior o igual a la
   foto base.
4. **Saldo DX:DZ** de la foto base: solo mediante las conciliaciones del §5.3, que quedan
   etiquetadas como inferencia.

Si solo se carga el INGRESOS de la semana, los anticipos pagados en semanas previas solo
se detectan por EN / DX:DZ; por eso la interfaz muestra un aviso cuando no hay ingresos
anteriores al periodo (`historial_previo = false`).

### 5.3 Conciliación del neto (`V12ConciliadorNeto`)

Cada etapa solo puede **reducir** la meta con evidencia; nunca crea cobros, nunca usa la
cobranza del periodo y nunca reparte una diferencia global entre cuotas a ciegas.

| Método | Condición | Efecto |
|---|---|---|
| `fotoBase()` | Todas las cuotas pendientes EN..BX vencen dentro de la ventana, `Pagos pendientes = BX − EN + 1`, y la proyección neta supera DX:DZ | El déficit se asigna en orden de cuota hasta el margen de cada una (`SALDO GLOBAL / CUOTA ESTIMADA`). Si no alcanza: `NETO-NO-CONCILIA`, sin ajuste. |
| `atrasoIndividual()` | Base sin atraso; la primera foto posterior al vencimiento de **una** cuota muestra ED = 1, EN = n+1, misma BX y AZ | Exigible = abonos hasta esa foto + atraso de la foto; la diferencia, respaldada por la bolsa DX:DZ, reduce la meta (`ATRASO INDIVIDUAL + SALDO BASE`). |
| `intermedio()` | Contrato sin atraso en ninguna foto, EN avanza, misma cuota y plazo, **una única** cuota parcial | El faltante de esa cuota se descuenta si DX:DZ lo respalda (`ULTIMA FOTO ANTES DE DESAPARECER` si el contrato desaparece). Varias parciales: `MULTI-ANTICIPO`; bolsa insuficiente: `NETO-INSUFICIENTE`. |
| `cierresRefinanciamiento()` | §6 | Neto real de la cuota exigible (`REFINANCIAMIENTO / DIARIO CUADRADO`). |
| `ultimaCuota()` | Última cuota con deuda inicial mayor a la nominal (≤ 2 %) | Se corrige el monto solo si los atrasos de la foto final cuadran con base − cobrado (`ULTIMA CUOTA / SALDO Y CIERRE`, comprobado). |

## 6. Refinanciamientos

- La cuota **futura** cancelada por refinanciamiento **no** se vuelve exigible: no es meta
  ni cobranza. Solo conserva meta por el abono ordinario **real** aplicado antes del cierre
  (`CUOTA FUTURA CANCELADA / ABONO REAL`); el remanente extinguido no se exige.
- Ese abono previo se conserva como meta y como cobro.
- El descuento de refinanciamiento sobre una cuota futura y los descuentos de
  reestructura/unificación **nunca** son cobranza. Un descuento estimado (inferencia) solo
  reduce meta; jamás se registra como pago.
- **Diario completo** (`cierresRefinanciamiento()`): si todas las cuotas EN..BX tienen
  movimientos (ordinarios + descuento de refi) identificados por número y fecha hasta la
  fecha del refinanciamiento, y su suma coincide con DX:DZ de la foto base (± 0.02), el
  contrato queda en `cierresRefi`. En la cuota ya exigible la meta se reduce al **neto real
  liquidado**. Las cuotas futuras solo validan el total. Este cuadre **solo cierra la
  auditoría** (`CIERRE REFI CONTABILIZADO`): no crea cobros nuevos.

## 7. Buckets, transferencias y porcentajes

### 7.1 Clasificación (`V12Buckets::tipoBucket`)

Cada cobro se reconoce el día `max(Fecha transacción, Fecha cuota)`:

- **V** (Vencida) si ese día es posterior al vencimiento del contrato;
- **A** (Vigente con atraso) si es posterior a la fecha de la cuota;
- **S** (Sano) en otro caso. **La cuota que vence hoy sigue sana** hasta terminar el día.

Los cobros de la cuota se aplican en orden de reconocimiento hasta agotar su neto: lo que
excede el neto no cuenta (sin doble cobro).

### 7.2 Por cobrar de cada bucket

- **Sano por cobrar** = meta neta (§5.1). No se mueve por saldos que migran.
- **Atraso / Vencido por cobrar** (vistas de control) = cobrado del bucket en el periodo +
  saldo pendiente al corte de las cuotas del periodo que migraron a A/V + deuda histórica
  del corte inicial no cobrada (A, o V si el contrato ya venció al corte). La deuda
  histórica existe **una sola vez**; una migración de S a A/V no genera meta nueva.
- Cuotas históricas (fecha ≤ foto base): sus cobros se aplican contra el atraso real del
  corte inicial del contrato (`atrasoBase`, EE:EG o saldo total si el contrato ya venció).

### 7.3 Transferencias de gestor (Regla 24)

Si el responsable de un contrato cambia dentro del periodo después de la fecha de la
cuota, **solo la meta pendiente** (neto − cobros anteriores al cambio) pasa al nuevo gestor.
Los cobros previos conservan su gestor y su clasificación. El gestor de cada día se busca
hasta 14 días atrás (`V12Corrida::responsable()`).

### 7.4 Columnas y porcentajes (`EfectividadV12Calculator::fila()`)

| Columna | Fórmula |
|---|---|
| % Sano | Sano cobrado / Sano por cobrar |
| % Atraso | Atraso cobrado / **Sano por cobrar** |
| % Vencido | Vencido cobrado / **Sano por cobrar** |
| Cobranza total | Sano + Atraso + Vencido cobrado |
| % Efectividad | Cobranza total / Sano por cobrar = suma de los tres aportes, **sin tope** de 100 % |
| Mora | Sano por cobrar − Cobranza total (**puede ser negativa**) |

- **Sin base**: si Sano por cobrar ≤ 0.005 todos los porcentajes son `null` (celda vacía),
  nunca 0 % ni división por cero.
- **Regla 32**: no se muestran gestores sin meta **y** sin cobranza; un gestor con
  recuperación y meta cero **sí** aparece y suma al consolidado, con porcentaje vacío.
- Totales por ruta, sucursal y GRAN TOTAL se obtienen sumando importes y recalculando
  porcentajes.

### 7.5 Controles (`V12Buckets::validar()`)

Un fallo es error de cálculo y detiene el reporte:

| Código | Control |
|---|---|
| `EC-V12-291` | Sano cobrado > Sano por cobrar |
| `EC-V12-292` | Atraso cobrado > Atraso por cobrar |
| `EC-V12-293` | Vencido cobrado > Vencido por cobrar |
| `EC-V12-294` | Meta interna ≠ Sano por cobrar |

Otros códigos de error: `EC-V12-202` modalidad inválida, `-203`/`-940` encabezados de
ingresos, `-204` falta fotografía, `-205` sin cuotas, `-206` corte semanal no es domingo
base + 7, `-211` domingo base ilegible, `-212` base no es domingo, `-213` corte fuera de
rango, `-320` columnas obligatorias de cartera.

## 8. Inferencias, evidencia e incidencias

### 8.1 Evidencia por cuota (`V12Auditoria::trazabilidad()`)

| Evidencia | Calidad |
|---|---|
| `SALDO GLOBAL / CUOTA ESTIMADA` | INFERIDO |
| `ATRASO INDIVIDUAL + SALDO BASE` | INFERIDO |
| `ULTIMA FOTO ANTES DE DESAPARECER` | INFERIDO |
| `REFINANCIAMIENTO / DIARIO CUADRADO` | INFERIDO |
| `ULTIMA CUOTA / SALDO Y CIERRE` | COMPROBADO |
| `INGRESO HISTORICO POR CUOTA` | COMPROBADO |
| `SIGUIENTE PAGO / SALDO BASE` | COMPROBADO |
| `CUOTA CONTRACTUAL SIN ANTICIPO` | COMPROBADO |
| `HISTORICA / SALDO BASE GLOBAL` (neto 0; se controla por contrato) | COMPROBADO |
| `CUOTA FUTURA CANCELADA / ABONO REAL` | COMPROBADO |
| `SIN DETALLE EN MEMORIA` | COMPROBADO (revisar) |

Una cuota es INFERIDA cuando el **total** está comprobado (saldo, foto o diario) pero su
atribución a esa cuota no. Las inferencias quedan en `estimadas` y vuelven el reporte
PRELIMINAR.

### 8.2 Incidencias de auditoría (no alteran cifras)

| Código | Origen | Significado |
|---|---|---|
| `SIN-GESTOR` | `leerFoto()` | Contrato sin ruta/promotor en una foto |
| `AMORT-FECHA-DUPLICADA` | `V12Amortizaciones::leer()` | Dos cuotas vencen el mismo día |
| `COBRO-SIN-CUOTA` | `aplicarIngresos()` | Cobro del periodo sin amortización comprobable |
| `CUOTA-AMBIGUA` | `aplicarIngresos()` | Cobro sin número de cuota sobre fecha ambigua |
| `FECHA-APLICACION` | `aplicarIngresos()` | Fecha cuota del cobro ≠ calendario |
| `FOLIO-DUPLICADO` | `aplicarIngresos()` | Mismo folio aplicado dos veces; se excluye la repetición |
| `NETO-NO-CONCILIA` | `fotoBase()` | Saldo e ingresos no ubican los anticipos; meta sin ajuste |
| `MULTI-ANTICIPO` | `intermedio()` | Dos o más cuotas parciales; no se reparte |
| `NETO-INSUFICIENTE` | `intermedio()` | El saldo base no respalda el faltante |
| `META-INFERIDA` | `trazabilidad()` | Cuota del periodo con meta inferida |
| `DIFERENCIA-CIERRE` | `conciliarCartera()` | `POR CONCILIAR` o `CIERRE REFI CON DIFERENCIA` |
| `DESAPARICION-SIN-SOPORTE` | `conciliarCartera()` | Contrato salió de cartera sin evidencia |

## 9. Conciliación con la foto de corte

`V12Auditoria::conciliarCartera()`, por contrato:

```
saldo teórico = atraso base + exigible neto − cobranza elegible − (exigible que vence hoy − cobrado hoy)
diferencia    = saldo teórico − atraso de la foto de corte
```

| Estatus | Condición |
|---|---|
| `COINCIDE` | En foto de corte y \|diferencia\| ≤ 0.02 |
| `POR CONCILIAR` | En foto de corte y diferencia > 0.02 |
| `SIN FOTO FINAL - CUADRADO` | No está en la foto de corte y cuadra |
| `CIERRE EN FOTO INTERMEDIA` | Última foto con estatus CERRADO y saldo ≤ 0.02 |
| `DESAPARICION SIN SOPORTE` | Desaparece sin cuadre ni cierre visible (no se supone pago ni liquidación) |
| `CIERRE REFI CONTABILIZADO` | Diario de refinanciamiento cuadrado (§6) y sin diferencia |
| `CIERRE REFI CON DIFERENCIA` | Diario cuadrado, pero queda diferencia de cartera |

**Estado del reporte** (`resumen()`): `PRELIMINAR` si hay contratos con diferencia
(`POR CONCILIAR` / `CIERRE REFI CON DIFERENCIA`), desapariciones sin soporte o cuotas
inferidas; en otro caso `CONCILIADO`. El resumen expone `contratos_ajustados`,
`cuotas_inferidas`, `importe_inferido`, `contratos_con_diferencia`, `diferencia_sin_asignar`,
`desapariciones_sin_soporte`, `saldo_sin_soporte`, `cierres_refi_comprobados` y
`transferencias_gestor`. PRELIMINAR significa *revisar evidencias antes de comisionar*.

## 10. Diferencias con el motor anterior (V4 / V6 / V7)

El motor V4 (`app/Services/Efectividad/V4`, `mrlana-v4-2026-10`) se conserva como
referencia histórica y sigue en uso en los flujos heredados (ejecuciones en segundo plano
de `EfectividadCobranzaController`, API `CollectionsController`, PDF de ejecuciones). La
calculadora efímera usa solo V12.9.4.

| Tema | V4 / V7 | V12.9.4 |
|---|---|---|
| Unidad de cálculo | Movimientos y cuotas programadas | Tabla de amortizaciones por cuota con neto y evidencia |
| Sano por cobrar | Cuotas programadas del rango (anticipo reconocido en la fecha de cuota) | Neto exigible: cuota − anticipos/pagos previos (EN, historial, DX:DZ) |
| Atraso / Vencido por cobrar | Stocks de la foto de corte | Cobrado del bucket + pendiente migrado + deuda histórica una sola vez |
| Clasificación | Estado de la foto del día (Substatus, V7: pago puntual = sano) | Fechas: cuota vs reconocimiento vs vencimiento del contrato |
| Importe | Capital + interés + impuesto + cargos calendario | Capital + interés + impuesto (V:X) |
| Responsable | Promotor predominante se queda con toda la ruta | Gestor por contrato y día; Regla 24 para cambios |
| Refinanciamiento | `DESCUENTO` solo si transacción y cuota caen en la semana | Cuotas del día/atrasadas sí; futuras nunca; diario completo vs DX:DZ |
| DIARIO | Fotos del domingo base al corte | Solo la foto del día anterior y la del corte |
| ACUMULADO / MENSUAL | No existen (`EC-V4-PERIODO`) | Extensiones documentadas (§3) |
| Sin base | — | Porcentaje vacío (`null`), Regla 32 |
| Auditoría | Incidencias de exclusión (`NO-CARTERA`, `CUOTA`, `DUP`…) | Trazabilidad por cuota, conciliación por contrato, PRELIMINAR / CONCILIADO |

## 11. Paridad con la hoja de referencia

Libro de Córdoba, semana del **26/10/2026 al 01/11/2026**, modalidad SEMANAL, con el
historial de ingresos que trae el libro (tolerancia 1e-6):

| Comparación | Resultado |
|---|---|
| Hoja de efectividad: 29 filas × 12 valores | **348 / 348** iguales |
| Traza de amortizaciones | **8,613 / 8,613** filas |
| Conciliación por contrato | **1,010 / 1,010** contratos |
| Resumen de auditoría | idéntico |

Resumen de auditoría:

| Concepto | Valor |
|---|---:|
| Sano por cobrar | 767,657.47 |
| Cobranza total | 716,622.29 |
| Efectividad | 93.3518 % |
| Mora | 51,035.18 |
| Contratos ajustados | 135 |
| Cuotas inferidas | 135 por 9,155.07 |
| Contratos con diferencia | 1 (−0.16) |
| Desapariciones sin soporte | 36 por 97,918.83 |
| Cierres de refinanciamiento comprobados | 52 |
| Estado | PRELIMINAR |

Ejemplo, ruta **20 DE NOVIEMBRE**:

| | Por cobrar | Cobrado | % Aporte |
|---|---:|---:|---:|
| Sano | 50,785.48 | 45,776.48 | 90.1369 % |
| Vigente con atraso | — | 2,193.00 | 4.3182 % |
| Vencida | — | 352.50 | 0.6941 % |
| **Total** | | | **95.1492 %** |
| Mora | | | 2,463.50 |

Ruta **CALZADAS**: 105.2227 % (supera 100 %; no hay tope).

**Sensibilidad al historial**: con solo el INGRESOS de esa semana (sin semanas previas),
el Sano por cobrar total sube a **767,745.47 (+88.00)**: son anticipos que únicamente
evidencian ingresos anteriores. Por eso la interfaz advierte cuando no se cargaron ingresos
anteriores al periodo.

> El libro de referencia contiene información financiera real y datos personales. Esta
> documentación solo reproduce estructuras, reglas, conteos y totales de control.

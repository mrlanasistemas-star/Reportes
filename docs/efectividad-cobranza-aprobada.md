# Efectividad de Cobranza — Aprobada (Fase 2)

Estado (actualizado 2026-10-11): **lógica extraída, documentada e implementada; no conectada
a datos reales todavía.** El bloqueo original (archivo `.xlsm` no disponible) se resolvió —
el archivo se localizó en `docs/efectividad/CALCULADORA DE EFECTIVIDAD DE COBRANZA
APROBADO.xlsm` y en el Downloads del usuario. Se extrajo su VBA real con `oletools.olevba`
(lectura de macro, **nunca se ejecutó** en el servidor) y se verificó línea por línea contra
el dump. Apareció un bloqueo NUEVO y distinto: el archivo es una plantilla en blanco (ver
sección 6) — no hay un resultado ya calculado contra el cual validar numéricamente un port.

## 1. Qué se hizo

1. **VBA extraído y verificado** — `Módulo1.bas`, ~3,788 líneas, función principal
   `Generar_Efectividad_Cobranza` y 20+ funciones auxiliares. Cada regla de negocio
   documentada abajo se verificó leyendo el código fuente real citado por número de línea,
   no se infirió ni se adivinó.
2. **Motor de cálculo implementado**: `App\Services\Efectividad\EfectividadCobranzaAprobadaCalculator`
   — puerto fiel a PHP, independiente de Eloquent/DB (recibe arrays con los nombres de campo
   documentados, para poder probarse con datos sintéticos y conectarse a datos reales después
   sin tocar la lógica ya verificada).
3. **17 pruebas unitarias** (`tests/Unit/Efectividad/EfectividadCobranzaAprobadaCalculatorTest.php`)
   — cada una fija un comportamiento específico verificado contra el VBA real (whitelist,
   clasificación Sano/Atraso/Vencido, fallback de vencimiento, fórmula de porcentaje,
   contratos VIGENTE-con-atraso que SÍ siguen proyectando Sano, la regla "$0.80 de Eudy",
   reasignación de ruta, reconstrucción de Sano cuando el contrato salió de CARTERA, etc.)
   — todas PASS.
4. **Exportador Excel** (`EfectividadCobranzaAprobadaExcelExporter`) — reproduce el layout
   real de la hoja EFECTIVIDAD (columnas A-M, encabezados fusionados, semáforo de color) —
   probado generando un XLSX real y leyéndolo de vuelta.
5. **API** (`/api/v1/collections/effectiveness`) actualizada: `versions.approved.available`
   sigue en `false`, pero con la razón específica y real (no conectado a datos, no validado
   numéricamente) — nunca se expone un valor inventado.

## 2. Reglas de negocio verificadas (resumen — ver el motor para el detalle línea por línea)

### 2.1 Universo de operaciones — el hallazgo más importante

Solo cuentan como cobranza real las filas de INGRESOS cuya combinación EXACTA de
(Operación, Transacción) sea:

- `PAGO A CONTRATO` + `PAGO`, o
- `REFINANCIAMIENTO` + `DESCUENTO`

**"Transacción = DESCUENTO" NO es un criterio de inclusión por sí mismo.** Verificado contra
las 4,806 filas reales de `_HIST_INGRESOS` del archivo aprobado:

| Operación | Transacción | Filas | ¿Cuenta? |
|---|---|---|---|
| PAGO A CONTRATO | PAGO | 3,215 | Sí |
| REFINANCIAMIENTO | DESCUENTO | 572 | Sí |
| CASTIGO DE CARTERA | CONDONACION | 842 | No |
| SEGUROS | DESCUENTO | 174 | No (aunque también sea "DESCUENTO") |
| COMISIÓN POR APERTURA | DESCUENTO | 3 | No (aunque también sea "DESCUENTO") |

### 2.2 Condonaciones y otros descuentos

Excluidos por completo por el whitelist anterior — nunca se restan de cartera ni se reportan
aparte, simplemente no participan.

### 2.3 Anticipos, parciales, finiquitos

El VBA **nunca lee la columna "Estatus pago"** ni usa las palabras ANTICIPO/PARCIAL/FINIQUITO
como texto. El manejo es estructural:

- `_HIST_INGRESOS` acumula TODO el histórico (el periodo actual se reemplaza, nunca se
  duplica) — `ProcesarIngresos` corre sobre el histórico completo, no solo el periodo actual.
- **Fecha de reconocimiento** `fRecon = MAX(fTrans, fCuota)`. Un pago cuya cuota cae en el
  periodo actual se reconoce en ESE periodo aunque se haya pagado antes (anticipo).
- Un pago parcial simplemente suma su importe tal cual; dos abonos a la misma cuota se suman.
- Si el contrato ya salió de CARTERA (se liquidó), su cuota programada se reconstruye desde
  el propio pago (nunca se pierde el denominador — ver 2.6).

### 2.4 Fechas de clasificación (Sano/Atraso/Vencido del lado cobrado)

- `fTrans` = Fecha transacción, `fCuota` = Fecha cuota (de ESA cuota, no de vencimiento).
- `fTrans <= fCuota` → **Sano**.
- `fTrans > fCuota` (pagó tarde): se compara contra el vencimiento del CONTRATO completo
  (`fVenc`, de la propia fila si existe, si no **fallback al vencimiento guardado desde
  CARTERA** para ese contrato — incluso si el contrato en CARTERA está cerrado/inactivo):
  - `fTrans > fVenc` → **Vencido**.
  - si no → **Atraso**.

Esto confirma la estructura de 3 casos del antecedente histórico, pero precisa que la fuente
real del vencimiento puede venir de CARTERA, no solo de la fila de INGRESOS.

### 2.5 Denominador exacto de "efectividad" — el segundo hallazgo más importante

```
pctSano    = sanoCob   / sanoPC    (0 si sanoPC = 0)
pctAtraso  = atrasoCob / sanoPC    ← divide por SANO, no por atrasoPC
pctVencido = vencCob   / sanoPC    ← divide por SANO, no por vencPC
pctTotal   = totalCob  / sanoPC
mora       = sanoPC - totalCob
```

El denominador de TODO porcentaje es siempre el "por cobrar Sano" proyectado — nunca el
por-cobrar de cada bucket ni la suma de los tres. Semáforo de "% Cobrado Total": ≥95% verde,
≥90% amarillo, <90% rojo.

### 2.6 CARTERA — por-cobrar

- Contratos con Estatus que contiene CERRADO/INACTIVO, o Substatus con INACTIVO → excluidos
  del por-cobrar (pero su vencimiento SÍ se guarda para clasificar pagos históricos).
- Substatus contiene "VIGENTE" → aporta a **Atraso-por-cobrar** ("Total atrasado", solo si
  "Pagos atrasados" no existe o es >0, y siempre que sea >0.01).
- Substatus contiene "VENCIDO" → aporta a **Vencido-por-cobrar** (suma de saldos positivos de
  capital+interés+impuesto+cargo calendario+impuesto cargo calendario; fallback a "Total
  pendiente" solo si ninguna de esas 5 columnas existe; **si "Total pendiente" ≤ 0.01, el
  vencido-por-cobrar se omite entero** — regla documentada en el VBA como "ESTO ELIMINA LOS
  $0.80 DE EUDY").
- **Un contrato VIGENTE-con-atraso NO es mutuamente exclusivo con Sano**: salvo que el
  Substatus sea VENCIDO, el contrato SIEMPRE continúa a la proyección de Sano (cuota por
  cuota desde Fecha primer/siguiente pago, topada por vencimiento y número de pagos,
  contando solo cuotas dentro del periodo). Solo VENCIDO excluye la proyección de Sano.
- Ningún saldo negativo contribuye nunca (`Positivo()` = max(valor, 0)).

### 2.7 Reasignación de ruta ("VERSIÓN CORREGIDA - CONSOLIDACIÓN POR RUTA")

Cuando un promotor deja una ruta y otro lo sustituye, CARTERA puede seguir mostrando el
nombre del promotor anterior. El VBA resuelve esto contando, por Oficina, qué promotor tiene
más movimientos válidos (whitelist) en INGRESOS del periodo ACTUAL, y usa a ESE promotor para
TODA la oficina (tanto en CARTERA como en INGRESOS histórico) — ignorando el nombre residual
de la fila. Empate → gana el primero encontrado (nunca se reemplaza en un empate exacto).

### 2.8 Redondeo

Ninguno — todos los cálculos de negocio se mantienen en punto flotante sin redondear
intermedios; el redondeo solo aparece en el formato de presentación de la celda de Excel.

### 2.9 Agrupación y reporte final

Oficina → Promotor (nunca por producto/cliente/contrato). Un grupo con los 6 acumuladores en
cero (tolerancia 0.01) se omite — nunca se muestra una fila íntegramente en cero. Totales por
oficina y Gran Total son sumas aritméticas de lo ya calculado por grupo — recalculan sus
columnas de porcentaje de la misma forma (dividiendo entre el Sano-por-cobrar acumulado de
ese nivel), nunca una fórmula ni un promedio distinto.

## 3. Bloqueo actual — por qué `versions.approved` sigue en `available:false`

### 3.1 No existe un resultado ya calculado para validar contra él

Se inspeccionó el `.xlsm` real con `openpyxl` (solo lectura de valores, nunca se ejecutó la
macro): las hojas `CARTERA`, `INGRESOS` y `EFECTIVIDAD` están **completamente vacías**
(dimensiones de plantilla, 0 filas con datos). Solo `_HIST_INGRESOS` tiene datos reales
(4,806 filas) — y es un *insumo*, no un resultado. El archivo entregado es una calculadora en
blanco, no un caso ya resuelto. Sin uno de estos dos insumos no es posible declarar el motor
"validado contra el resultado aprobado real" (solo "consistente con esta especificación"):

1. Una copia de este `.xlsm` con la hoja EFECTIVIDAD ya poblada de una corrida real, o
2. Las hojas CARTERA e INGRESOS con datos reales de un periodo específico, para correr la
   lógica aquí documentada y contrastar manualmente contra lo que el negocio confirme.

### 3.2 Esquema actual no captura todas las columnas de CARTERA que el VBA necesita

`fact_portfolios` (tabla real de "Saldos por Cliente") tiene: `balance`, `past_due_balance`,
`capital_activo`, `capital_due`, `interes_atrasado`, `impuesto_atrasado`,
`saldo_interes_moratorio`, `saldo_impuesto_interes_moratorio`, `cargos_calendario_atrasado`,
`impuesto_cargos_calendario_atrasado`, `days_past_due`, `portfolio_date`.

El VBA necesita además: **Estatus, Substatus, Fecha primer pago, Fecha vencimiento (del
contrato), Cuota total, Fecha siguiente pago, Pagos atrasados** — ninguna existe hoy como
columna tipada. No se adivinó su nombre real en el archivo "Saldos por Cliente" de Lendus (no
se tiene una muestra de ese archivo con esas columnas reales para confirmar nombres exactos de
encabezado) — extenderlo ahora sería inventar un mapeo. `fact_recoveries` (INGRESOS) también
necesita una columna `fecha_cuota` nueva (distinta de `payment_date`), igualmente no agregada
todavía por la misma razón.

## 4. Qué falta para destrabar por completo (checklist)

- [ ] Conseguir una muestra real del archivo "Saldos por Cliente" de Lendus para confirmar
      los nombres exactos de columna (Estatus/Substatus/Fecha primer pago/etc.).
- [ ] Migración aditiva: agregar esas columnas a `fact_portfolios`, y `fecha_cuota`/
      `numero_cuota` a `fact_recoveries`.
- [ ] Actualizar los importadores de Lendus (Ingresos Cobranza / Saldos por Cliente) para
      poblar las columnas nuevas.
- [ ] Servicio de adaptación: `fact_portfolios`/`fact_recoveries` reales → los arrays que
      `EfectividadCobranzaAprobadaCalculator::calculate()` ya acepta (la lógica de cálculo NO
      se toca, solo se le da la fuente real).
- [ ] Obtener (1) una corrida real de EFECTIVIDAD ya calculada, o (2) CARTERA/INGRESOS reales
      de un periodo conocido, para validar numéricamente antes de marcar
      `versions.approved.available = true` en la API.

## 5. Explícitamente NO se hizo

- No se ejecutó la macro VBA en ningún momento (solo lectura estática con `oletools`).
- No se inventó ningún nombre de columna de CARTERA real — los documentados aquí son
  exactamente los que el propio VBA busca por encabezado (`ColumnaEncabezado`), citados con
  número de línea, nunca adivinados por el nombre del archivo histórico `_HIST_INGRESOS`.
- No se modificó la efectividad de cobranza histórica existente
  (`RadiographySnapshotBuilder::buildEfectividadCobranza()`), ni sus pruebas
  (`tests/Feature/EfectividadCobranzaPctTest.php` sigue pasando sin cambios).
- No se marcó `available:true` en la API sin una validación numérica real.

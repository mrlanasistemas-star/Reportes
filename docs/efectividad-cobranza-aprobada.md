# Efectividad de Cobranza — Aprobada (Fase 2) — BLOQUEADA

Estado: **bloqueada para la lógica de negocio real**. No se implementó el módulo de cálculo
ni el exportador porque no existe evidencia suficiente para reproducir la calculadora
aprobada sin inventar reglas. Lo que sigue documenta exactamente qué falta, qué se sabe con
certeza, y qué se dejó preparado sin adivinar nada.

## 1. Bloqueo #1 — falta el archivo fuente con el VBA

Se buscó en todo el repositorio de trabajo:

```
find . -iname "*CALCULADORA*"   → sin resultados
find . -iname "*.xlsm"          → sin resultados
```

El archivo `CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm` (con el proyecto VBA en
`xl/vbaProject.bin` descrito en la solicitud) **no está presente** en este entorno de
trabajo. Sin él no es posible extraer el código VBA real que define:

- Universo de contratos/operaciones incluidos y excluidos.
- Tratamiento de descuentos, refinanciamientos, condonaciones, anticipos/parciales/finiquitos.
- Qué fechas usa para clasificar (transacción/cuota/vencimiento).
- El denominador exacto de "efectividad".
- Redondeo, agrupación y forma final del Excel.

Las reglas descritas como "antecedentes" en la solicitud (sana/atrasada/vencida por
comparación de fechas, etc.) son **antecedentes históricos explícitamente no vinculantes**
— la instrucción es clara: "No las programes ciegamente" y "la versión aprobada prevalece
ante cualquier discrepancia". Sin el VBA real, programar esas reglas como si fueran la
versión aprobada sería inventar la especificación, algo que las reglas de trabajo prohíben
expresamente.

## 2. Bloqueo #2 — no hay datos Lendus reales en este entorno para auditar el esquema

Fase 2.5 pide revisar qué columnas de los dos archivos Lendus actuales (Ingresos Cobranza,
Saldos por Cliente) se están descartando, para decidir si el esquema actual (`fact_recoveries`,
`fact_portfolios`) ya captura lo necesario o si falta extender algo.

Se verificó el storage de archivos originales (`storage/app/public/reports/`) — está
**vacío**: no hay ningún archivo Lendus real subido en este entorno de trabajo. Sin un
archivo real de `_HIST_INGRESOS` (o equivalente) no se puede comparar columna por columna
contra `fact_recoveries`/`fact_portfolios` con evidencia real — solo se puede documentar lo
que el código ya declara.

## 3. Lo que SÍ se sabe con certeza (auditado en código, no en datos)

### 3.1 Esquema actual de `fact_recoveries` (ingresos de cobranza)

Columnas reales (`app/Models/Recovery.php` + migraciones): `period_id`, `report_upload_id`,
`branch_id`, `contract`, `client_name`, `normalized_client_name`, `promoter_name`,
`product_name`, `operation`, `concept`, `transaction`, `capital`, `interest`, `tax`,
`charges`, `charges_due`, `excedente`, `total_amount`, `days_past_due`, `payment_date`,
`is_savehearts`, `savehearts_crece_share`, `raw_payload` (json).

**No existen** columnas típicas dedicadas a `fecha_cuota` / `fecha_vencimiento` /
`estatus_pago` tal como se nombran en la hoja `_HIST_INGRESOS` de la solicitud — solo
`days_past_due` (DPD) y `payment_date`. `raw_payload` puede conservar columnas originales
del Excel que no se promovieron a columna tipada, pero **no se puede confirmar qué
contiene** sin un archivo real que lo haya poblado.

### 3.2 Esquema actual de `fact_portfolios` (saldos/cartera)

Columnas reales: `period_id`, `report_upload_id`, `branch_id`, `client_name`, `contract`,
`promoter_name`, `product_name`, `balance`, `past_due_balance`, `capital_activo`,
`capital_due`, `interes_atrasado`, `impuesto_atrasado`, `saldo_interes_moratorio`,
`saldo_impuesto_interes_moratorio`, `cargos_calendario_atrasado`,
`impuesto_cargos_calendario_atrasado`, `days_past_due`, `portfolio_date`, `raw_payload`.

### 3.3 La efectividad de cobranza YA EXISTENTE (debe permanecer intacta)

`RadiographySnapshotBuilder::buildEfectividadCobranza()` — clasifica recuperaciones en
Vigente/Atrasado/Vencido por DPD (con fallback a `fact_portfolios.days_past_due` del cierre),
excluye condonaciones/seguros/cobertura, y calcula:

```
efectividad_pct = (atrasado.total + vencido.total) / cartera_mora_mes_anterior × 100
```

Esta regla **no se tocó** y debe seguir devolviendo exactamente los mismos resultados (ver
`tests/Feature/EfectividadCobranzaPctTest.php`, que sigue pasando sin cambios). La API nueva
(`/api/v1/collections/effectiveness.versions.legacy`) la expone marcada explícitamente como
`legacy_vigente_atrasado_vencido` — nunca como la "Aprobada".

## 4. Qué se dejó preparado (sin inventar lógica)

- `/api/v1/collections/effectiveness` ya responde con la forma final esperada: `versions.legacy`
  (dato real) + `versions.approved` (`available: false`, `status: "blocked"`, con la razón
  explícita de este bloqueo). Cuando el módulo se implemente, solo hay que rellenar
  `versions.approved` sin tocar el contrato.
- Este documento sirve de checklist: en cuanto se obtenga el `.xlsm` real (o, alternativamente,
  un archivo real de `_HIST_INGRESOS` con el que correlacionar resultados conocidos de la
  calculadora), continuar con:
  1. Extraer el VBA (`xl/vbaProject.bin`) con una herramienta de análisis de documentos
     Office — nunca ejecutar el macro en el servidor.
  2. Documentar los 15 puntos de la sección 2.4 de la solicitud original contra el código
     VBA real.
  3. Decidir con evidencia si `raw_payload` ya basta o si se necesita extender
     `fact_recoveries`/`fact_portfolios` con columnas tipadas nuevas (migración aditiva).
  4. Solo entonces construir el servicio de cálculo, el exportador XLSX y las pruebas
     comparando contra el Excel aprobado real.

## 5. Explícitamente NO se hizo

- No se escribió ningún servicio de cálculo de "efectividad aprobada".
- No se extendió el esquema de `fact_recoveries`/`fact_portfolios` (no hay evidencia real
  que lo justifique todavía).
- No se creó pantalla, job, ni exportador para este módulo — construirlos sin la lógica real
  sería entregar una "implementación incompleta presentada como terminada", prohibido
  explícitamente en las reglas de trabajo.
- No se modificó en absoluto la efectividad de cobranza histórica existente.

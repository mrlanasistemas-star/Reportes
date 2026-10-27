# API general de reportería (`/api/v1`)

Capa REST de solo lectura, servidor-a-servidor, para que otros sistemas (empezando por
Auditoría Operativa MR Lana) consuman los indicadores que Reportería ya calcula — **nunca
para que recalculen nada por su cuenta**.

Implementada: 2026-10-11. Ver también `docs/api-openapi.yaml`,
`docs/integracion-auditoria.md`, `docs/integracion-futura-people.md`.

## 1. Principio arquitectónico

La API **no recalcula** EBITDA, OPEX, cartera, mora, colocación, recuperación, etc. Todo
valor financiero pasa por `App\Services\Api\FinancialMetricResolver`, que a su vez delega
en `RadiografiaExportService::buildSnapshot()` — la MISMA fuente que ya usan Web, Excel, PDF
y el módulo OKR (ver `docs/OKR.md`). Esto garantiza paridad por construcción, nunca por
coincidencia.

Si un periodo no tiene radiografía generada, o un indicador no es atribuible a un alcance
(p. ej. rotación de personal a nivel individual), la API **nunca inventa un cero**: devuelve
`value: null` con un `status` explícito (`not_available` o `not_attributable`).

## 2. Autenticación

Tokens personales de [Laravel Sanctum](https://laravel.com/docs/sanctum), emitidos a un
`App\Models\ApiConsumer` (un **sistema**, nunca un usuario humano — separado deliberadamente
de `users`).

```
Authorization: Bearer <token>
```

### Crear un consumidor (única forma hoy — no hay pantalla de administración)

```bash
php artisan api:consumers:create "Auditoría Operativa" "auditoria-operativa" \
    --ability=branches.read --ability=employees.read --ability=financial.read \
    --ability=periods.read --ability=collections.read --ability=okr.read
```

El token se imprime **una sola vez** en consola — igual que GitHub/Stripe, nunca se
recupera en texto plano después.

Para restringir el consumidor a sucursales específicas (bloquea `scope=general` y cualquier
otra sucursal):

```bash
php artisan api:consumers:create "Auditoría Córdoba" "auditoria-cordoba" --branch=5 --branch=9
```

### Revocar

```bash
php artisan api:consumers:revoke auditoria-operativa --deactivate
```

## 3. Abilities (permisos por recurso)

Cada token tiene una lista de *abilities* (scopes Sanctum). Un endpoint exige una ability
específica vía middleware `ensure.ability:<ability>`:

| Ability | Recursos |
|---|---|
| `branches.read` | `/branches` |
| `branches.read.global` | override: ver el catálogo COMPLETO de sucursales aunque el consumidor esté restringido (Fase 2.4) |
| `employees.read` | `/employees`, `/employees/{id}` |
| `periods.read` | `/periods`, `/periods/{id}` |
| `financial.read` | `/financial/*` |
| `employees.read` **Y** `financial.read` (ambas) | `/employees/{id}/financial-summary` — corrección Fase 2.1: tener acceso al catálogo de empleados nunca basta por sí solo para leer su ficha financiera |
| `collections.read` | `/collections/effectiveness` |
| `collections.export` | `/collections/effectiveness/export` — ability **propia** de exportación: leer (`collections.read`) nunca basta para descargar el archivo. No se concede por defecto. |
| `okr.read` | `/okr/*` |
| `employees.read` **Y** `financial.read` | `/periods/{id}/employees`, `/employees/{id}/periods` (colaboradores con KPIs por periodo — ver §14) |
| `financial.read` | `/periods/{id}/branches`; `/periods/{id}/export` y `/periods/export` (el bloque de colaboradores del export exige además `employees.read`) |

`/health` no requiere autenticación ni ability (no expone datos sensibles).
`/capabilities` solo requiere estar autenticado — informa qué puede hacer ESE token.

**Fase 2.9 — el consumidor es un techo real, no solo el token**: `ensure.ability` verifica
que la ability esté TANTO en el token (`tokenCan()`) COMO en `ApiConsumer.abilities`. Un token
nunca puede tener más privilegios que su consumidor, aunque alguna vez se emitiera por error
con una ability de más.

## 4. Restricción por sucursal

**Corrección Fase 2.5**: `ApiConsumer.branch_access_mode` es un estado EXPLÍCITO de 3 valores
— antes, `allowed_branch_ids = null` y `= []` eran indistinguibles (ambos daban acceso
global, un bug real). Ahora:

- `global` → sin restricción, puede pedir `scope=general` o cualquier sucursal/colaborador.
- `restricted` → solo las sucursales en `allowed_branch_ids`. Si ese array queda vacío por
  error de datos, se trata como `none` — **nunca** como global.
- `none` → sin acceso a ninguna sucursal (catálogos vacíos, 403 en todo lo que pida una
  sucursal/colaborador/scope=general).

**Corrección Fase 2.2/2.3 — IDOR y sucursal histórica**: un consumidor restringido NUNCA se
autoriza por la asignación "más reciente" de un colaborador. Se verifica su sucursal
EXACTA en el `period_id` solicitado (`App\Services\Api\EmployeeBranchHistoryResolver`). Si
el colaborador perteneció a Tula en junio y hoy está en Cuernavaca, un consumidor restringido
a Tula puede leer sus datos de junio (403 para octubre); uno restringido a Cuernavaca puede
leer octubre (403 para junio). Si la sucursal de ese periodo es desconocida (sin asignación
registrada), se niega el acceso por defecto — nunca se concede por una coincidencia insegura.

**Corrección OKR `summary` (2026-10-15) — alcance EFECTIVO, no histórico**: `GET /okr/summary`
y `GET /okr/employees/{id}` autorizan y cuentan cada objetivo por su **sucursal efectiva**
(`branch_id` del objetivo, o la de su objetivo padre si es individual), **nunca** por la
asignación histórica del colaborador. Antes, `summary` admitía un objetivo cuando el
colaborador había estado *alguna vez* asignado a una sucursal autorizada, filtrando objetivos
cuya sucursal efectiva pertenece a otra sucursal (p. ej. tras una transferencia). Se eliminó
ese criterio: un consumidor restringido solo cuenta/ve objetivos cuya sucursal efectiva está
en su `allowed_branch_ids`.

## 5. Rate limiting

Limiter `api-v1`: 60 peticiones/minuto por consumidor (no por IP — varios consumidores
pueden compartir salida NAT). Las exportaciones usan además `api-exports`: 20/minuto por
consumidor (archivos pesados). Ambos definidos en `App\Providers\AppServiceProvider`.

## 6. Auditoría de consultas

Cada petición autenticada queda registrada en `api_query_logs` (endpoint, método, status,
scope, branch_id/employee_id/period_id, IP, fecha). **Nunca** guarda el payload de
respuesta ni valores financieros — solo metadata de QUIÉN pidió QUÉ.

## 7. Catálogo de endpoints

| Método | Endpoint | Ability |
|---|---|---|
| GET | `/api/v1/health` | — |
| GET | `/api/v1/capabilities` | auth |
| GET | `/api/v1/branches` | `branches.read` |
| GET | `/api/v1/employees` | `employees.read` |
| GET | `/api/v1/employees/{id}` | `employees.read` |
| GET | `/api/v1/employees/{id}/financial-summary?period_id=` | `employees.read` + `financial.read` |
| GET | `/api/v1/periods` | `periods.read` |
| GET | `/api/v1/periods/{id}` | `periods.read` |
| GET | `/api/v1/financial/summary?period_id=&scope=` | `financial.read` |
| GET | `/api/v1/financial/branches/{id}?period_id=` | `financial.read` |
| GET | `/api/v1/financial/employees/{id}?period_id=` | `financial.read` |
| GET | `/api/v1/financial/metrics` | `financial.read` |
| GET | `/api/v1/financial/metric-values?period_id=&scope=&metrics=a,b,c` | `financial.read` |
| GET | `/api/v1/collections/effectiveness?period_id=&scope=` | `collections.read` |
| GET | `/api/v1/collections/effectiveness/export?period_id=&scope=` | `collections.export` |
| GET | `/api/v1/okr/summary` | `okr.read` |
| GET | `/api/v1/okr/employees/{id}` | `okr.read` |
| GET | `/api/v1/periods/{id}/employees` | `employees.read` + `financial.read` |
| GET | `/api/v1/employees/{id}/periods?from_period_id=&to_period_id=` | `employees.read` + `financial.read` |
| GET | `/api/v1/periods/{id}/branches` | `financial.read` |
| GET | `/api/v1/periods/{id}/export` | `financial.read` (+ `employees.read` para colaboradores), `throttle:api-exports` |
| GET | `/api/v1/periods/export?from_period_id=&to_period_id=` | ídem |

Parámetros comunes: `scope` = `general`\|`branch`\|`employee` (default `general`), `branch_id`,
`employee_id`, `period_id` (obligatorio en todos los endpoints financieros — la API nunca
adivina "el periodo más reciente").

### 7.1 Exportación (`GET /collections/effectiveness/export`)

Descarga el **Excel de Efectividad de Cobranza V4 ya generado** desde el módulo web — el mismo
archivo que produce la web, la API **no recalcula** en el GET. `scope` = `general` o `branch`
(no `employee`; `period_id` obligatorio y semanal). Requisitos y garantías:

- Ability **propia** `collections.export` (además de Sanctum) — leer no basta.
- Alcance por sucursal del consumidor (`ApiScopeResolver`): no puedes exportar una sucursal
  fuera de tu alcance (403).
- Solo ejecuciones del **motor y la metodología vigentes** (`V7`): un snapshot V6 o de la
  calculadora anterior responde `404` pidiendo regenerar — nunca se exporta como vigente.
- Límite más estricto: `throttle:api-exports` (20/min por consumidor) **además** de `api-v1`.
- El archivo vive en el disco **privado** `local` (nunca público); la descarga queda
  **auditada** por `log.api-query`.
- Si el cálculo existe pero falta el archivo en disco → `409` (regenerar desde la web).

Respuestas: `200` (descarga `.xlsx`, `Content-Disposition: attachment`), `404` (sin ejecución
vigente), `409` (archivo ausente), `422` (`scope=employee` o periodo no semanal), `403`
(sin ability o fuera de alcance). Ejemplo:

```bash
curl -L -H "Authorization: Bearer <token>" \
  "https://<host>/api/v1/collections/effectiveness/export?period_id=42&scope=branch&branch_id=7" \
  -o efectividad-cobranza.xlsx
```

## 8. Catálogo de métricas (`GET /financial/metrics`)

| Code | Label | Unit | Scopes | Fuente |
|---|---|---|---|---|
| `ebitda` | EBITDA | MXN | general, branch, employee | `summary.ebitda_final` |
| `ebitda_margin` | Margen EBITDA | % | general, branch, employee | `summary.margen_ebitda` |
| `opex` | OPEX | MXN | general, branch, employee | `summary.opex_total` |
| `placement` | Colocación | MXN | general, branch, employee | `summary.placement_total` |
| `recovery` | Recuperación | MXN | general, branch, employee | `summary.recovery_total` |
| `mora` | Mora | % | general, branch, employee | `summary.mora_index` |
| `portfolio_total` | Cartera total | MXN | general, branch, employee | `summary.portfolio_total` |
| `portfolio_overdue` | Cartera vencida | MXN | general, branch, employee | `summary.overdue_portfolio` |
| `portfolio_current` | Cartera vigente | MXN | general, branch, employee | derivado: `portfolio_total - portfolio_overdue` |
| `turnover` | Rotación de personal | % | general, branch (nunca employee) | `sections.rotation` |
| `collections_effectiveness_legacy` | Efectividad de cobranza (histórica) | % | general, branch, employee | `sections.efectividad_cobranza` — **NO** la calculadora "Aprobada" (ver abajo) |

Cada valor de `/financial/*` tiene esta forma (Fase 1.5):

```json
{
  "metric": "ebitda",
  "period_id": 42,
  "period_type": "monthly",
  "period_label": "Abril 2026",
  "start_date": "2026-04-01",
  "end_date": "2026-04-30",
  "calculated_at": "11/10/2026 01:30",
  "source": "reporteria.radiography_snapshot",
  "status": "ok",
  "scope": { "type": "branch", "branch_id": 5, "employee_id": null },
  "value": 123456.78,
  "unit": "MXN",
  "definition_version": "v1"
}
```

`status` ∈ `ok` \| `not_available` (sin radiografía generada para ese periodo/alcance) \|
`not_attributable` (el indicador no aplica a ese alcance, p. ej. rotación por colaborador).

## 9. Efectividad de cobranza — dos versiones, nunca mezcladas

`GET /collections/effectiveness` siempre devuelve **ambas** versiones explícitamente:

- `versions.legacy` — la que YA EXISTE en Reportería hoy (vigente/atrasado/vencido +
  `efectividad_pct`). Nunca se modificó para construir esta API.
- `versions.approved` — **Efectividad de Cobranza V4** (actualizado 12-oct-2026, motor
  `mrlana-v4-2026-10`, `version: approved_snapshot_v4`). Lee, sin recalcular, la última
  ejecución V4 **SEMANAL** exitosa del `period_id` (semanal) y el alcance exactos, generada
  desde el módulo web. Estados: `ok`, `stale` (cambiaron fotografías o Ingresos después de
  generarla; nunca se presenta como `ok`), `not_available`, `not_attributable` y
  `not_supported_period` (meses/bimestres). Una ejecución de la calculadora anterior
  (`aprobada-2026-10`) **nunca** se entrega como vigente; solo se menciona en `reason`. Por
  colaborador: asignación de sucursal verificada en ese periodo **y exactamente una** fila de
  promotor con su nombre; dos homónimos en la sucursal dan `not_attributable`. Requiere la
  ability `collections.read`. Detalle en `docs/efectividad/nueva-version/README.md`.

### 9.1 Cuatro versiones distintas — no confundirlas

El resultado de Efectividad lleva **cuatro** identificadores de versión, cada uno con un
propósito distinto. La API los expone por separado y **nunca** presenta uno como otro:

| Versión | Dónde | Valor actual | Qué significa |
|---|---|---|---|
| **Contrato API** | `api_version` | `v1` | Forma del endpoint/JSON. |
| **Catálogo de métricas** | `definition_version` | `FinancialMetricRegistry::DEFINITION_VERSION` | Definiciones/códigos de métricas. |
| **Motor técnico** | `engine_version` | `mrlana-v4-2026-10` | La macro/implementación que calculó (`EfectividadV4Calculator::ENGINE_VERSION`). |
| **Metodología financiera** | `methodology` | `V7` | La regla financiera vigente (`EfectividadV4Calculator::METODOLOGIA`), **independiente** del motor. |

**Por qué importa `methodology`.** La metodología evolucionó **V6 → V7** *dentro de la misma
clase de motor* sin cambiar `engine_version` (V7: el pago puntual de la cuota vigente cuenta
como SANO aunque el contrato tenga atrasos anteriores). Antes, una ejecución calculada con V6
era **indistinguible** de una V7 a nivel de base de datos/API y podía servirse como vigente.

Corrección: cada ejecución ahora **sella** su metodología (columna `metodologia` de
`efectividad_cobranza_runs`). La identidad vigente (`scopeForV4Identity`) exige
`engine_version` **y** `methodology` actuales. En consecuencia:

- La API **nunca** entrega un resultado V6 como si fuera V7.
- Una ejecución del motor vigente pero con metodología anterior (o sin sello, anterior a
  esta columna) devuelve `status: not_available` con un `reason` que pide **regenerarla**
  desde el módulo web. **No se borra** ningún histórico: el run anterior sigue en la base,
  simplemente no se sirve como vigente.
- Los campos `engine_version` y `methodology` viajan en `versions.approved` (en `ok`,
  `stale` y `not_available`) y en `capabilities.resources.collections_effectiveness_approved`.
- **No** se modificó la fórmula V7 para resolver esto: es solo identificación de versión.

## 10. Errores

| Código | Caso |
|---|---|
| 401 | Sin token / token inválido |
| 403 | Token sin la ability requerida, consumidor inactivo, o fuera de su alcance de sucursal |
| 404 | Recurso (period/employee/branch) no encontrado |
| 422 | Parámetros inválidos o faltantes (`scope`, `period_id`, `metrics` desconocidas, `per_page` fuera de 1-100, IDs no numéricos en query — ver Fase 2.7, `App\Services\Api\ApiValidation`) |
| 429 | Rate limit excedido |

Todas las respuestas de error son JSON: `{"message": "..."}`.

## 11-bis. Atribución a sucursal (ADITIVO, retro-compatible)

Un `branch` es una **sucursal/ruta operativa** de Reportería (una fila por oficina/ruta);
NO es el catálogo institucional de las **11 sucursales de OKR**. La atribución de un branch a
una de esas 11 se materializó en `branches.sucursal_id` (fuente única: el catálogo oficial de
`BranchRouteCatalog`, nunca por parecido en tiempo de ejecución — ver `SucursalSeeder`). Esta
ronda **expone** esa atribución en la API, solo **agregando** campos: ninguna clave existente
se quitó ni se renombró.

| Endpoint | Campos nuevos (aditivos) |
|---|---|
| `GET /branches` (cada item) | `sucursal: {id, codigo, nombre}\|null`, `region: string\|null`, `active: bool` |
| `GET /employees` (cada item) y `GET /employees/{id}` | `branch: {id, code, name}\|null`, `sucursal: {id, codigo, nombre}\|null`, `region: string\|null` (además `is_active` presente también en el listado) |
| `GET /employees/{id}/financial-summary` | `sucursal_id: int\|null`, `sucursal_status: 'known'\|'unknown'` |

Reglas:

- **`sucursal`/`sucursal_id` nunca se infiere del nombre del colaborador**: sale de la
  relación real del branch (`branches.sucursal_id`). Un branch que no corresponde a ninguna de
  las 11 (San Juan del Río, Tenango del Valle, Aguascalientes, Corporativo, agrupaciones de
  matriz/región, etc.) → `sucursal: null` / `sucursal_id: null` (honesto, nunca fabricado).
- **`active`** es el estado operativo DERIVADO (`Branch::derivedActive()`): `false` si el branch
  está inactivo o si su ruta está marcada inactiva en el catálogo oficial.
- **`financial-summary` es HISTÓRICO**: `branch_id` es la ruta/branch registrada para ese
  `period_id` (foto del periodo, Fase 2.3), y `sucursal_id` es la sucursal de ESE branch. Un
  cambio de ruta dentro de la misma sucursal conserva el mismo `sucursal_id`.
  `sucursal_status` = `known` solo cuando el branch del periodo mapea a una de las 11;
  `unknown` cuando el branch es `null` o no mapea.

**Cómo detecta Auditoría este contrato** (sin romper consumidores viejos): `GET /capabilities`
trae un bloque aditivo `features` con `route_branch_mapping: true` y `sucursal_attribution: true`
(equivalente: `resources.branches.sucursal_attribution === true`). `api_version` sigue en `v1`
y `definition_version` no se movió (las definiciones de métricas no cambiaron). Si `features`
falta (contrato anterior), Auditoría debe degradar tratando la sucursal como desconocida.

## 11. Identidad externa de colaboradores (`employee_external_identities`)

Tabla aditiva nueva, nunca modifica `employees`. Permite vincular un `Employee` con un
identificador de sistema externo (Auditoría, y a futuro MR Lana People) de forma explícita
y verificada (`verified_at`, `verification_method`) — **nunca** por coincidencia automática
de nombre. Ver `App\Models\EmployeeExternalIdentity` y
`docs/integracion-futura-people.md`.

## 12. Lo que esta fase NO incluye (deliberadamente)

- Pantalla de administración de `ApiConsumer`/tokens (solo comandos artisan por ahora).
- Endpoints de detalle de clientes/créditos (fuera de alcance explícito, Fase 1.2).
- Escritura (la API es de solo lectura — verificado explícitamente: 0 rutas con método
  distinto de GET/HEAD en todo `/api/v1`, ver `ApiFinalSecurityChecklistTest`).
- Recalcular la Efectividad V4 desde la API (solo se lee lo generado en el módulo web).

## 13. Correcciones de seguridad y rendimiento (2026-10-11)

Ver `docs/seguridad-fase2-correcciones.md` (9 hallazgos de una auditoría externa, todos
corregidos y probados) y `docs/rendimiento-fase3-mediciones.md` (mediciones reales, no
estimadas, de las optimizaciones de esta misma fecha).

## 14. Exportación de KPIs por periodo — colaboradores, sucursales, corporativo, consolidado (2026-10-27)

Objetivo: que un sistema externo obtenga **todo** lo que el reporte mensual calcula para un
periodo (o un rango) sin recalcular nada: cada colaborador con su sucursal y **ruta** de ese
periodo y sus KPIs (colocación, recuperación, cartera/mora, nómina, comisiones, OPEX, EBITDA),
cada sucursal con todos sus KPIs, el bloque **CORPORATIVO** y el **CONSOLIDADO**.

### 14.1 Endpoints

| Endpoint | Qué devuelve | Paginación / formato |
|---|---|---|
| `GET /periods/{id}/employees` | Colaboradores del periodo | `page`, `per_page` (1-100); filtros `branch_id`, `sucursal_id`; `format=csv` (todas las filas) |
| `GET /employees/{id}/periods` | Un colaborador en varios periodos (con efectividad de cobranza) | rango (máx. 24); `include_effectiveness=0` la omite; `format=csv` |
| `GET /periods/{id}/branches` | Sucursales + `corporate` + `consolidated` + `reconciliation` | `format=csv` (una fila por bloque) |
| `GET /periods/{id}/export` | Todo lo anterior en una llamada | `include=branches,employees` (default ambos) |
| `GET /periods/export` | Igual, para un rango (máx. 12 periodos) | `include=`; `format=csv&dataset=employees` o `dataset=branches` |

**Rango de periodos** (`/employees/{id}/periods`, `/periods/export`): `period_ids=19,20,21` **o**
`from_period_id=19&to_period_id=21` (todos los periodos del MISMO tipo que `from` cuyo inicio cae
entre ambos). Nunca se adivina "el último periodo": sin parámetros → 422.

**CSV**: UTF-8 con BOM, columnas con la ruta del campo (`kpis.ebitda.ebitda`,
`sucursal.codigo`…); las listas anidadas (rutas, desgloses) van como JSON en una celda; se
omiten `opex.items` y `placement.by_product` (siguen en JSON).

### 14.2 De dónde sale cada número (paridad con el reporte)

| Bloque | Fuente (la MISMA que el reporte) |
|---|---|
| Colaboradores | `RadiographySnapshotBuilder::buildAllEmployeeGestorRows()` (= `buildEmployeesGestores()`, con la fusión de identidad del reporte) + OPEX vía `OpexClassificationService` + EBITDA vía `computeEmployeeFinancialMetrics()` — exactamente el "Excel de colaboradores" (`EmployeesHistoricoExportService`). Servicio: `App\Services\Api\EmployeePeriodKpiService`. |
| Sucursales | `snapshot.branch_radiography.branches` (BranchRadiographyCalculator) con `nominaTotalFor()`, `gastosTotalesFor()`, `ingresoEbitdaBaseFor()`, `ebitdaFinalFor()`, `margenEbitdaFor()`. Servicio: `App\Services\Api\PeriodBranchKpiService`. |
| Corporativo | `snapshot.branch_radiography.unassigned` (hoja "SIN ASIGNAR": nómina de colaboradores sin sucursal operativa) + `sections.corporate_funding` (excedentes enviados a corporativo). |
| Consolidado | `snapshot.summary` (cifras de portada del reporte) + `branch_radiography.global`. |

Verificado contra la BD real (solo lectura, Junio 2026, periodo 21): 136 colaboradores, **0
diferencias** contra el Excel de colaboradores en recuperación, colocación, cartera, vencida,
OPEX, Nómina y Capital Humano, percepciones, deducciones, EBITDA y margen; EBITDA consolidado =
Σ sucursales + aporte corporativo (diferencia 0.00).

**Caché**: colaboradores 12 h por periodo + versión de la radiografía vigente
(`PeriodSummary.id/updated_at`); sucursales reutilizan la caché de 12 h del snapshot general del
reporte. Regenerar la radiografía invalida ambas. Sin N+1: todo se carga en lote (número de
consultas constante sin importar cuántos colaboradores haya — cubierto por prueba).

**Sin radiografía vigente** → `status: not_available`, `data: []` / bloques `null`. Nunca ceros.

### 14.3 Fila de colaborador (`/periods/{id}/employees`)

```json
{
  "employee": {
    "id": 412, "employee_ids": [412, 977], "full_name": "NOMBRE APELLIDO APELLIDO",
    "report_name": "NOMBRE APELLIDO APELLIDO", "employee_code": "0153", "employee_codes": ["0153"],
    "promoter_code": "P-118", "position": "GESTOR", "is_active": true
  },
  "branch_id": 131, "branch": { "id": 131, "code": "TULACEN", "name": "TULA CENTRO" },
  "branch_status": "known", "branch_source": "assignment", "assigned_branch_id": 131,
  "sucursal": { "id": 11, "codigo": "tula", "nombre": "TULA" }, "region": "Q3",
  "report_branch_name": "TULA",
  "routes": [
    { "route": "TULA CENTRO", "catalog_route": "TULA CENTRO", "catalog_branch": "TULA", "active": true,
      "sources": ["assignment", "portfolio"], "contracts": 214, "balance": 812345.10 }
  ],
  "employment": {
    "operational_status": "ACTIVO", "roster_status": "activo_confirmado", "roster_status_label": "Activo confirmado",
    "movement_type": "activo", "is_active_for_period": true, "requires_review": false, "roster_available": true
  },
  "kpis": {
    "placement": { "amount": 210000.00, "operations": 21, "by_product": [{ "product": "CRECE", "operations": 21, "amount": 210000.00 }] },
    "recovery": { "total": 265000.00, "components": { "capital": 201000.00, "interest": 48000.00, "tax": 7680.00, "late_charges": 2100.00, "additional_charges": 0, "surplus": 0, "initial_charges": 0, "opening_fee": 4200.00, "crece_insurance_recognized": 900.00, "other": 1120.00 } },
    "portfolio": { "total": 812345.10, "overdue": 96000.00, "current": 716345.10, "mora_pct": 11.82, "buckets": { "al_corriente": { "contracts": 190, "amount": 700000.00 }, "mora_1_30": { "contracts": 12, "amount": 40000.00 } } },
    "payroll": { "human_capital_total": 14250.00, "payments": 13000.00, "bonuses": 1800.00, "discounts": 550.00,
                 "noi_perceptions": 14800.00, "noi_deductions": 550.00, "noi_net_paid": 14250.00,
                 "concepts": { "sueldo": 9000.00, "comisiones": 4000.00, "bonos": 1800.00, "bonos_aceleradores": 0, "vacaciones": 0, "prima_vacacional": 0, "otras_percepciones": 0 },
                 "deductions_detail": [{ "concept": "D001 ISR", "amount": 550.00 }] },
    "commissions": 4000.00,
    "opex": { "automatic_total": 1350.00, "opex_pure": 1350.00, "employee_payroll_expenses": 0, "manual": 0, "total": 1350.00,
              "items": [{ "category": "Recargas Telefónicas", "concept": "RECARGAS TELEFONICAS", "amount": 350.00, "type": "opex" }] },
    "ebitda": { "income_base": 63980.00, "total_expenses": 15600.00, "ebitda": 48380.00, "margin_pct": 75.62, "category": "JUNIOR" },
    "collections_effectiveness": null
  },
  "availability": { "collections_effectiveness": "Solo en GET /api/v1/employees/{id}/periods ...", "roster": null, "manual_expense_adjustment": "..." }
}
```

(Valores ilustrativos.) Respuesta envolvente: `{ period, status, message, source,
definition_version, calculated_at, radiography_version, data: [...], meta: { current_page,
per_page, total, last_page, unidentified_count } }`. En `/employees/{id}/periods` cada elemento de
`data` es `{ period, status, calculated_at, radiography_version, ...fila }` con `status` ∈ `ok` \|
`not_available` \| `not_found` (sin actividad ese periodo) \| `forbidden`, y
`kpis.collections_effectiveness` = `{ status, effectiveness_pct, recovered_from_overdue,
previous_period_overdue_portfolio, previous_period_label, composition{current, late, overdue} }`.

| Campo | Significado / fuente |
|---|---|
| `employee.id` | Id canónico = el menor de los `employee_ids` fusionados (misma regla que `CanonicalEmployeeResolver`). Cualquiera de los `employee_ids` sirve para `/employees/{id}/periods`. |
| `employee.employee_code` | Clave NOI del registro canónico (`employees.employee_code`). `promoter_code` = código de promotor Lendus de la fila. |
| `branch` / `branch_status` | Asignación registrada en `employee_branch_assignments` para ESE periodo (o un periodo componente) — misma regla que `EmployeeBranchHistoryResolver`. Si no hay (`unknown`), se usa el branch con el mismo nombre que la sucursal de la fila del reporte (`branch_source: report_row`). La **autorización** usa solo `assigned_branch_id`. |
| `sucursal` | Sucursal institucional (una de las 11) desde `branches.sucursal_id`; nunca por nombre. `null` si el branch no está mapeado. **Nota:** en la BD real la tabla `sucursales` está vacía y ningún branch tiene `sucursal_id` hasta correr `SucursalSeeder` → hoy `sucursal` sale `null` (el `branch` sí viene). |
| `routes[]` | Ruta(s) del periodo: el branch asignado si es una ruta del catálogo oficial (`sources: assignment`) y las rutas de la cartera Lendus del periodo (`fact_portfolios.route_name`) del gestor por nombre canónico o código de promotor (`sources: portfolio`, con contratos y saldo). `catalog_*` = normalización contra `BranchRouteCatalog` (null si la ruta es desconocida — nunca se inventa). |
| `employment.operational_status` | ACTIVO/BAJA con la misma regla que el Excel (ingreso real u OPEX automático > 0). |
| `employment.roster_*` | Roster laboral del periodo (`period_employee_rosters`, `RosterMovementStatus`): alta/activo/baja confirmada, posible baja, etc. `roster_available=false` si no hay fila (solo se materializa en periodos mensuales con NOI). |
| `kpis.placement` | Colocación del gestor (fact_placements, por promotor) y desglose por producto. |
| `kpis.recovery` | Recuperación con las MISMAS exclusiones que el reporte (seguros, unificación, condonación) y sus componentes. |
| `kpis.portfolio` | Cartera y cartera vencida al cierre (DPD > 0), mora % y buckets. |
| `kpis.payroll.human_capital_total` | "Nómina / Capital Humano" del colaborador = neto (pagos + bonos − descuentos), el valor que entra al EBITDA del colaborador en el reporte. `concepts` = percepciones NOI clasificadas con `classifyPercepcionConcept()`; `noi_*` = percepciones/deducciones/neto NOI (informativo). |
| `kpis.commissions` | Percepciones NOI de comisiones (P002/P119). |
| `kpis.opex` | OPEX automático atribuido (`eligible_for_attribution`: OPEX puro + gastos de nómina-empleado como finiquito/médicos/moto) con su detalle. `manual` siempre 0: el ajuste manual del reporte es efímero. |
| `kpis.ebitda` | `income_base` (ingreso base EBITDA del gestor) − (`opex.total` + `payroll.human_capital_total`); margen y categoría. |

### 14.4 KPIs por colaborador que quedan `null` o no se exponen (y por qué)

| KPI | Dónde | Motivo |
|---|---|---|
| `collections_effectiveness` | `/periods/{id}/employees` y export | Cálculo pesado por colaborador (lee el DPD de toda la cartera). Disponible en `/employees/{id}/periods` (misma función que la vista del reporte) y la versión aprobada V4 semanal en `/collections/effectiveness?scope=employee`. |
| Unificación/condonación excluida, fondeo, excedentes, seguros puente | no se exponen por colaborador | El reporte no los atribuye a un solo colaborador (`summaryFromRow()` los deja `null`). |
| Rotación | no por colaborador | Solo existe a nivel sucursal/general. |
| Filas sin identidad | `employees.unidentified` del export (solo alcance global) | Gestores que solo aparecen en Lendus sin registro `Employee`: el Excel de colaboradores tampoco los incluye. Se listan aparte con colocación/recuperación/cartera para que las sumas cuadren. |

### 14.5 Sucursales, corporativo y consolidado (`/periods/{id}/branches`)

Forma: `{ period, status, message, source, definition_version, calculated_at, data: [sucursal…],
corporate, consolidated, reconciliation }`.

```json
{
  "name": "TULA", "branch_id": 99, "branch": { "id": 99, "code": "TULA", "name": "TULA" },
  "sucursal": { "id": 11, "codigo": "tula", "nombre": "TULA" }, "region": "Q3",
  "routes": [{ "route": "TULA CENTRO", "active": true }, { "route": "TEPEJI", "active": true }],
  "kpis": {
    "placement": { "amount": 1200000.00, "credits": 140 },
    "recovery": { "total": 1500000.00, "gross": 1530000.00, "components": { "capital": 1150000.00, "interest": 260000.00, "tax": 41600.00, "late_charges": 9000.00, "additional_charges": 3000.00, "surplus": 1500.00, "initial_charges": 0, "opening_fee": 22000.00, "crece_insurance_recognized": 9000.00, "other": 3900.00 },
                  "excluded": { "insurance_gross": 25000.00, "savehearts_gross": 20000.00, "comadres_gross": 5000.00, "crece_gross": 30000.00, "crece_not_recognized": 21000.00, "unification": 0, "condonation": 0 } },
    "portfolio": { "total": 3801969.92, "contracts": 1100, "overdue": 410000.00, "current": 3391969.92, "mora_pct": 10.78, "buckets": { "mora_0_30": { "contracts": 60, "amount": 150000.00 } } },
    "expenses": { "opex_total": 85199.47, "erp": 50000.00, "lendus": 35199.47, "erp_loaded": 52000.00, "erp_excluded_insurance": 2000.00, "lendus_loaded": 90000.00, "lendus_excluded_funding": 30000.00, "lendus_excluded_surplus": 20000.00, "lendus_excluded_payroll": 4000.00, "lendus_reclassified_payroll": 500.00, "lendus_excluded_policies": 300.00, "by_concept": { "Gasolina": 20000.00 } },
    "payroll": { "human_capital_total": 192254.41, "breakdown": { "Sueldo": 120000.00, "Comisiones": 40000.00, "IMSS patronal": 20000.00 }, "imss_employer": 20000.00, "employee_expenses": 2254.41, "deductions_informative": { "ISR": 9000.00 }, "noi_perceptions": 170000.00, "noi_deductions": 9000.00, "noi_net_paid": 161000.00 },
    "commissions": 40000.00,
    "funding": { "interbranch_loans": 30000.00, "surplus_sent_to_corporate": 20000.00 },
    "insurance": { "lendus_bridge": 300.00, "crece_policies_30": 9000.00, "crece_comadres_informative": 0 },
    "ebitda": { "income_base": 346100.00, "total_expenses": 277453.88, "ebitda": 227397.86, "margin_pct": 65.7, "category": "SENIOR" },
    "turnover_pct": 3.97, "collections_effectiveness": null
  }
}
```

(Valores ilustrativos salvo EBITDA/cartera/nómina/OPEX de TULA en Junio 2026, verificados.)

| Campo de sucursal | Fuente en `branch_radiography.branches[i]` |
|---|---|
| `placement.amount` / `credits` | `colocacion` / `creditos_colocados` |
| `recovery.*` | `recuperacion_total`, `recuperacion_bruta`; componentes `capital_recuperado`, `interes_recuperado`, `impuesto_recuperado`, `charges`, `cargos_adicionales`, `excedente_recuperado`, `cargos_inicio`, `comision_apertura`, `seguro_crece_reconocido`, `otros_recuperacion`; exclusiones `seguro_*_bruto`, `unificacion_excluida`, `condonacion_excluida` |
| `portfolio.*` | `valor_cartera`, `contratos`; vencida = Σ `mora_0_30 … mora_120_plus` (= `cartera_vencida`); mora % = vencida ÷ cartera — igual que `summaryFromRow()` |
| `expenses.*` | `gastos_operativos` (OPEX = ERP + Lendus), `gastos_erp_total`, `gastos_lendus_total`, cargados/excluidos (seguros, fondeo, excedentes, nómina, pólizas), `gastos_detalle` |
| `payroll.human_capital_total` / `breakdown` | `nominaTotalFor()` / `nominaBreakdownFor()` (percepciones NOI + IMSS patronal + gastos de empleados). `noi_*` = percepciones/deducciones NOI de los colaboradores asignados al branch en el periodo (misma regla que `computeNoiPercepcionesDeduccionesForBranch()`). |
| `commissions` | `comisiones` |
| `funding` | `prestamos_fondea` (fondeo entre sucursales), `excedentes` (envío a corporativo) |
| `insurance` | `seguros_lendus_puente`, `polizas_crece_30`, `seguro_crece_comadres_informativo` |
| `ebitda` | `ingresoEbitdaBaseFor()`, `gastosTotalesFor()`, `ebitdaFinalFor()`, `margenEbitdaFor()`, `RadiographyStyleHelper::ebitdaCategory()` |
| `turnover_pct` | `sections.rotation.por_sucursal[].indice_rotacion` |
| `collections_effectiveness` | `null` por sucursal (requiere el snapshot proyectado a esa sucursal): usar `/financial/branches/{id}` métrica `collections_effectiveness_legacy`. |

**`corporate`** (CORPORATIVO / SIN SUCURSAL): `payroll` = Nómina y Capital Humano de los
colaboradores sin sucursal operativa (Corporativo y sin asignar) con desglose y lista de
empleados (`name, employee_id, source, salary_p001, commissions_p002, bonuses` — hoja "SIN
ASIGNAR"); `expenses` (los gastos ERP/Lendus de Corporativo están excluidos del OPEX del reporte
por regla; se listan como referencia); `imss_excluded_informative` (IMSS de unidades no
operativas, excluido del consolidado); `surplus_received` (excedentes enviados por las
sucursales, `sections.corporate_funding`); `ebitda_contribution` = −Nómina corporativa (lo que
resta al consolidado). Corporativo no coloca, no cobra ni tiene cartera: esos KPIs son
`not_attributable` (nunca 0).

**`consolidated`** (CONSOLIDADO): mismas claves que una sucursal, con las cifras de portada del
reporte (`summary`: colocación, recuperación, cartera "Saldos por cliente" sin filtro, mora %,
OPEX, ERP/Lendus, fondeo, excedentes, seguros puente, Nómina y Capital Humano, percepciones/
deducciones NOI, número de colaboradores, ingreso base, gastos totales, EBITDA, margen,
categoría) + `turnover_pct` general, `collections_effectiveness` histórica,
`cash.opening_balance/closing_balance`.

**`reconciliation`**: `ebitda.difference` debe ser 0 (EBITDA consolidado = Σ sucursales + aporte
corporativo). `portfolio.difference` puede no ser 0: la cartera consolidada incluye todas las
rutas excepto Aguascalientes; la de sucursal solo las sucursales operativas.

### 14.6 Alcance por sucursal

- Colaboradores: un consumidor restringido solo recibe filas cuya **asignación del periodo**
  está en su `allowed_branch_ids` (nunca por nombre ni por la asignación más reciente); las
  filas sin identidad y `unidentified_count` solo se entregan a consumidores globales.
- `/employees/{id}/periods`: cada periodo fuera de alcance aparece como `status: forbidden`; 403
  si ninguno está permitido.
- Sucursales: solo las autorizadas; `corporate`/`consolidated` → `{status: not_authorized}` y
  `reconciliation: null` para consumidores restringidos.

### 14.7 Ejemplos

```bash
# Colaboradores de Junio 2026, página 1 (100 por página)
curl -H "Authorization: Bearer <token>" "https://<host>/api/v1/periods/21/employees?per_page=100"
# Mismo listado completo en CSV
curl -H "Authorization: Bearer <token>" "https://<host>/api/v1/periods/21/employees?format=csv" -o colaboradores-junio.csv
# Un colaborador de abril a junio
curl -H "Authorization: Bearer <token>" "https://<host>/api/v1/employees/412/periods?from_period_id=19&to_period_id=21"
# Todo el periodo (sucursales + corporativo + consolidado + colaboradores)
curl -H "Authorization: Bearer <token>" "https://<host>/api/v1/periods/21/export"
# Rango, solo sucursales, CSV
curl -H "Authorization: Bearer <token>" "https://<host>/api/v1/periods/export?from_period_id=19&to_period_id=21&format=csv&dataset=branches" -o sucursales.csv
```

`GET /capabilities` anuncia este contrato con `features.period_kpi_export: true` y
`features.csv_format: true`, y los recursos `period_employees_kpis`, `employee_periods_kpis`,
`period_branches_kpis`, `period_export` (cada uno con `required_abilities` y `endpoints`).

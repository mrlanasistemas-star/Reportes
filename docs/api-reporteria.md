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

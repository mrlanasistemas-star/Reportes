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
| `employees.read` | `/employees`, `/employees/{id}`, `/employees/{id}/financial-summary` |
| `periods.read` | `/periods`, `/periods/{id}` |
| `financial.read` | `/financial/*` |
| `collections.read` | `/collections/effectiveness` |
| `okr.read` | `/okr/*` |

`/health` no requiere autenticación ni ability (no expone datos sensibles).
`/capabilities` solo requiere estar autenticado — informa qué puede hacer ESE token.

## 4. Restricción por sucursal

`ApiConsumer.allowed_branch_ids` (nullable):

- `null` → sin restricción, puede pedir `scope=general` o cualquier sucursal/colaborador.
- `[5, 9]` → **nunca** puede pedir `scope=general` (403), y solo puede pedir la sucursal 5 o
  9, o colaboradores cuya asignación más reciente apunte a esas sucursales (403 en caso
  contrario).

## 5. Rate limiting

Limiter `api-v1`: 60 peticiones/minuto por consumidor (no por IP — varios consumidores
pueden compartir salida NAT). Definido en `App\Providers\AppServiceProvider`.

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
| GET | `/api/v1/employees/{id}/financial-summary?period_id=` | `employees.read` |
| GET | `/api/v1/periods` | `periods.read` |
| GET | `/api/v1/periods/{id}` | `periods.read` |
| GET | `/api/v1/financial/summary?period_id=&scope=` | `financial.read` |
| GET | `/api/v1/financial/branches/{id}?period_id=` | `financial.read` |
| GET | `/api/v1/financial/employees/{id}?period_id=` | `financial.read` |
| GET | `/api/v1/financial/metrics` | `financial.read` |
| GET | `/api/v1/financial/metric-values?period_id=&scope=&metrics=a,b,c` | `financial.read` |
| GET | `/api/v1/collections/effectiveness?period_id=&scope=` | `collections.read` |
| GET | `/api/v1/okr/summary` | `okr.read` |
| GET | `/api/v1/okr/employees/{id}` | `okr.read` |

Parámetros comunes: `scope` = `general`\|`branch`\|`employee` (default `general`), `branch_id`,
`employee_id`, `period_id` (obligatorio en todos los endpoints financieros — la API nunca
adivina "el periodo más reciente").

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
- `versions.approved` — la calculadora **"Aprobada"** del cliente (Fase 2). **Bloqueada**:
  `available: false`, `status: "blocked"`. Falta el `.xlsm` fuente para extraer su lógica
  VBA real — ver `docs/efectividad-cobranza-aprobada.md`. Se activará aquí únicamente
  cuando ese módulo quede implementado y validado contra el Excel aprobado; hasta entonces
  la API nunca inventa ese valor.

## 10. Errores

| Código | Caso |
|---|---|
| 401 | Sin token / token inválido |
| 403 | Token sin la ability requerida, consumidor inactivo, o fuera de su alcance de sucursal |
| 404 | Recurso (period/employee/branch) no encontrado |
| 422 | Parámetros inválidos o faltantes (`scope`, `period_id`, `metrics` desconocidas) |
| 429 | Rate limit excedido |

Todas las respuestas de error son JSON: `{"message": "..."}`.

## 11. Identidad externa de colaboradores (`employee_external_identities`)

Tabla aditiva nueva, nunca modifica `employees`. Permite vincular un `Employee` con un
identificador de sistema externo (Auditoría, y a futuro MR Lana People) de forma explícita
y verificada (`verified_at`, `verification_method`) — **nunca** por coincidencia automática
de nombre. Ver `App\Models\EmployeeExternalIdentity` y
`docs/integracion-futura-people.md`.

## 12. Lo que esta fase NO incluye (deliberadamente)

- Pantalla de administración de `ApiConsumer`/tokens (solo comandos artisan por ahora).
- Endpoints de detalle de clientes/créditos (fuera de alcance explícito, Fase 1.2).
- Escritura (la API es de solo lectura).
- `collections.effectiveness.approved` con datos reales (bloqueado por falta de fuente VBA).

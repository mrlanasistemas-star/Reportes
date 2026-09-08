# Módulo OKR — documentación técnica

Módulo OKR (Objectives & Key Results) integrado **nativamente** al sistema de
Reportería (Laravel 13 + Inertia/Vue 3). No es una app aparte: reutiliza
usuarios, sucursales, colaboradores/gestores, periodos y — sobre todo — **los
cálculos financieros ya existentes** (EBITDA, OPEX, colocación, recuperación,
mora, cartera). OKR nunca recalcula esas fórmulas: siempre pregunta.

## 1. Arquitectura

```
LENDUS / archivos operativos
        ↓
RadiographySnapshotBuilder / BranchRadiographyCalculator  (YA EXISTENTE)
        ↓
RadiografiaExportService::buildSnapshot($period, $config)['summary']  ← FUENTE ÚNICA
        ↓
OkrKpiValueResolver  (nunca query financiera propia)
        ↓
OkrKpi (catálogo)  →  OkrKeyResult  →  OkrObjective
        ↓
OkrProgressCalculator / OkrTrajectoryService / OkrProjectionService / OkrHealthService
        ↓
OkrSnapshotService (snapshot semanal idempotente)  →  OkrProgressSnapshot (histórico)
        ↓
OkrClosingService (cierre + clasificación final)
        ↓
Dashboard / Wizard / Seguimiento / Histórico (Vue + Inertia)
```

**Principio fundamental**: OKR es **consumidor** de Reportería, nunca escribe
en `fact_expenses`, `fact_noi_movements`, `period_summaries` ni ninguna tabla
financiera. Todas las tablas nuevas llevan el prefijo `okr_`.

## 2. Tablas

| Tabla | Propósito |
|---|---|
| `okr_kpis` | Catálogo de KPI (code, unit, type, direction, automation, provider_key) |
| `okr_objectives` | Objective (branch o employee, `parent_id` para sucursal→gestor) |
| `okr_key_results` | KR por Objective (baseline/target/weight + cache de evaluación) |
| `okr_progress_snapshots` | Histórico semanal por KR (idempotente, UNIQUE key_result+week) |
| `okr_check_ins` | Check-in semanal (UNIQUE objective+week+user) |
| `okr_corrective_actions` | Acciones correctivas de un check-in |
| `okr_evidences` | Archivos de evidencia (Storage::disk('public'), igual que ReportUploadController) |
| `okr_audit_logs` | Bitácora — toda modificación de meta/peso/plazo/KPI queda registrada |
| `okr_alerts` | Alertas internas, deduplicadas |
| `okr_settings` | Umbrales de semáforo / rangos de cierre (key/value JSON) |

## 3. KPI providers — de dónde viene cada dato

`OkrKpiProviderRegistry` es un array PHP fijo (nunca editable como código
desde la UI) que mapea `provider_key` → clave de
`RadiografiaExportService::buildSnapshot($period, $config)['summary']`. Esa
función es la MISMA que usa Web (`scopedData()`), Excel y PDF — por eso hay
paridad **por construcción**, no por coincidencia.

| KPI | provider_key | Clave en `summary` | Alcances |
|---|---|---|---|
| EBITDA | `reporteria.ebitda` | `ebitda_final` | general, branch, employee |
| Margen EBITDA | `reporteria.ebitda_margin` | `margen_ebitda` | general, branch, employee |
| OPEX | `reporteria.opex` | `opex_total` | general, branch, employee |
| Colocación | `reporteria.placement` | `placement_total` | general, branch, employee |
| Recuperación | `reporteria.recovery` | `recovery_total` | general, branch, employee |
| Valor Cartera | `reporteria.portfolio` | `portfolio_total` | general, branch, employee |
| Cartera Vencida | `reporteria.overdue_portfolio` | `overdue_portfolio` | general, branch, employee |
| Mora | `reporteria.mora` | `mora_index` | general, branch, employee |
| Rotación de Personal | `reporteria.turnover` | especial — `sections.rotation` | general, branch (nunca employee) |

`OkrKpiValueResolver::getValue($kpi, $scopeType, $branchId, $employeeId, $period)`
llama `buildSnapshot()` con `{scope, branch_id|employee_id}` y lee
`summary[$key]`. Si el periodo no tiene radiografía generada, o el alcance no
tiene datos, devuelve `null` — **nunca inventa un valor**.

### KPI sin fuente automática (quedan `automation='manual'`)

Se auditó el repo buscando un agregado ya calculado por Reportería para estos
KPI y **no se encontró** uno (ni en `summary`, ni como sección con un total
escalar limpio) — documentado explícitamente, no se inventó ningún cálculo:

- **Efectividad de recuperación** — existe `sections.efectividad_cobranza`
  pero es un desglose por estatus de crédito, no un único % agregado
  reconciliado con el KPI de recuperación (ver comentario en
  `RadiographySnapshotBuilder::applyEmployeeScope()`: "puede no coincidir
  centavo a centavo").
- **Número de clientes / Clientes nuevos / Renovaciones** — no existe un
  conteo de clientes agregado en `summary`; la información de clientes vive
  desagregada en `lendus_saldos_cliente`/cartera, sin una definición ya
  acordada de "cliente nuevo" vs "renovación" a nivel snapshot.
- **Préstamo Activo** — `sections.active_loans` es una tabla de préstamos
  individuales, no un total ya agregado por sucursal/gestor.
- **Ticket Promedio** — no existe como campo calculado en ningún lado del
  pipeline actual.

Si en el futuro Reportería agrega un total limpio para alguno de estos, basta
con: (1) agregar la clave a `SUMMARY_KEY_PROVIDERS` en
`OkrKpiProviderRegistry`, (2) cambiar el KPI a `automation='automatic'` con su
`provider_key` en el catálogo. Nunca se necesita tocar `OkrKpiValueResolver`.

## 4. Fórmulas (fuente única — nunca duplicadas en Vue/Excel/API)

**Progreso de un KR** (`OkrProgressCalculator::rawProgress()`):

```
Incrementar: raw = (current - baseline) / (target - baseline)
Disminuir:   raw = (baseline - current) / (baseline - target)
target == baseline → 100% si ya se alcanzó/superó, 0% si no (nunca división por cero)
```

**Cumplimiento del Objective** (`OkrProgressCalculator::objectiveCompliance()`):

```
Σ (min(raw_progress_KR, tope) × peso_KR)
```

Tope configurable (`OkrProgressCalculator::DEFAULT_WEIGHTED_CAP = 150%`) —
un KR sobre-cumplido no puede "tapar" el incumplimiento de otros.

**Trayectoria esperada** (`OkrTrajectoryService`):

```
expectedValue(t) = baseline + (target - baseline) × elapsedFraction
```

La dirección queda implícita en el signo de `(target - baseline)` — nunca una
rama especial para incrementar/disminuir.

**Proyección de cierre** (`OkrProjectionService`):

- `cumulative`: ritmo observado (`actual / semanas transcurridas`) extrapolado
  a las semanas totales.
- `balance`/`percentage`: tendencia (pendiente promedio entre snapshots
  consecutivos); con menos de 2 puntos, fallback conservador al valor actual.

**Semáforo** (`OkrHealthService`, umbrales en `okr_settings` key
`okr.health_thresholds`, default `{ahead: 5, on_track: -5, risk: -15}` pp de
desviación):

```
ahead     → desviación >= +5 pp
on_track  → -5 pp <= desviación < +5 pp
risk      → -15 pp <= desviación < -5 pp
off_track → desviación < -15 pp
```

## 5. Ciclo de vida vs semáforo (nunca mezclados)

- `lifecycle_status`: `draft` → `active` → `closed` / `cancelled`.
- `health_status`: `ahead` / `on_track` / `risk` / `off_track` (mientras `active`).
- `final_status` (solo al cerrar): `completed` / `partially_completed` /
  `not_completed`, según `okr_settings` key `okr.final_status_ranges`
  (default `{completed: 90, partially_completed: 50}` % de cumplimiento).

## 6. Línea base — congelamiento

Al activar (`ObjectiveController::activate()`), para cada KR sin
`baseline_value` capturado manualmente, se pide el valor real a
`OkrKpiValueResolver` (si el KPI es automático) y se guarda junto con
`baseline_source`, `baseline_period_date` y `baseline_locked_at`. Después de
eso, **nunca se recalcula automáticamente** — solo cambia vía
`ObjectiveController::updateGoal()`, que exige motivo y genera
`OkrAuditLog`.

## 7. Snapshots y recálculo

`OkrSnapshotService::evaluateKeyResult()` es idempotente: `updateOrCreate`
sobre `(okr_key_result_id, week_number)` — nunca duplica. Se dispara:

- Al activar el Objective.
- Manualmente vía el botón "Recalcular" (`POST /okr/{objective}/refresh`).
- Por scheduler (`php artisan okr:refresh`, diario 06:00 — ver
  `routes/console.php`).
- Al cerrar (`php artisan okr:close-due`, diario 06:15).

Ambos comandos son idempotentes y seguros de correr manualmente si el cron no
corrió a tiempo.

## 8. Permisos — hallazgo de auditoría

**Este repo no tiene ningún sistema de roles/permisos** (no hay
spatie/laravel-permission, ni Policies, ni Gates, ni columna de rol en
`users`, ni vínculo User↔Employee). Toda la app exige solo `auth`+`verified`.
`OkrServiceProvider` define Gates NOMBRADOS (`okr.view`, `okr.create`, ...)
que hoy conceden acceso a cualquier usuario autenticado — mismo criterio que
el resto de Reportería. Activar restricciones reales por rol en el futuro es
un cambio de una línea por permiso en `OkrServiceProvider::boot()`, sin tocar
controladores. Restringir "un gestor solo ve su propio OKR" requeriría un
vínculo User↔Employee que no existe hoy — deliberadamente no se inventó
(fuera de alcance).

## 9. Cómo agregar un KPI nuevo

1. Si Reportería ya expone el dato en `summary`: agregar
   `'reporteria.mi_kpi' => 'mi_clave_en_summary'` a
   `OkrKpiProviderRegistry::SUMMARY_KEY_PROVIDERS`.
2. Si el dato vive en otra parte del snapshot (como rotación): agregar un
   método `resolveEspecial()` a `OkrKpiValueResolver`, registrar la clave en
   `SPECIAL_PROVIDERS`.
3. Agregar la fila al catálogo — vía seeder (`OkrKpiSeeder`) o la pantalla
   `/okr/kpis` (el `provider_key` solo puede ser uno ya registrado, nunca
   texto libre).

## 10. Limitaciones conocidas / alcance de esta fase

- OKR corporativo/regional/gerente: solo `parent_id`/`scope_type` preparados,
  sin desarrollar (explícitamente fuera de esta fase).
- Restricción de acceso por identidad real de gestor: no implementable sin
  vínculo User↔Employee (ver sección 8).
- KPI manual/sin fuente: capturados vía check-in, nunca vía Reportería (ver
  sección 3).
- Exportación de OKR a Excel/PDF: no se construyó (no formaba parte del
  alcance explícito de esta fase — "no inventes 20 exports").

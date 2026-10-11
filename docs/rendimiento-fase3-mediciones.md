# Rendimiento de la API — mediciones Fase 3 (2026-10-11)

Solo se reportan números medidos realmente (con pruebas que fallarían si la optimización se
revirtiera) — ninguna afirmación de "mejoró el rendimiento" sin evidencia.

## 3.1 — buildSnapshot() por petición

**Antes**: cada métrica resuelta por `FinancialMetricResolver::resolve()` llamaba
`RadiografiaExportService::buildSnapshot()` de forma independiente. Un endpoint como
`/financial/summary` resuelve 11 métricas (`GENERAL_METRICS`) → hasta 11 llamadas a
`buildSnapshot()` para el MISMO periodo+alcance.

**Después**: memoización por instancia (vive solo la duración de la inyección en el
controlador, nunca cruza peticiones). Medido con un doble de prueba (`Mockery`) que falla si
`buildSnapshot()` se llama más de una vez:

```
tests/Feature/Api/V1/FinancialMetricResolverMemoizationTest.php
  ✓ builds the radiography snapshot only once per request even when resolving 9 metrics
  ✓ builds a SEPARATE snapshot per distinct scope (branch A vs branch B) — nunca comparte memoización entre alcances
```

**Resultado medido**: 9 resoluciones de métrica → **1** llamada real a `buildSnapshot()` (antes: 9).

## 3.2 — N+1 en `/employees`

**Antes**: `EmployeeController::index()` llamaba `resolveBranchId($employee->id)` dentro del
`map()` de la página — una consulta `EmployeeBranchAssignment` POR empleado listado.

**Después**: `batchResolveCurrentBranchIds()` — una sola consulta `whereIn(employee_id, ...)`
para toda la página.

**Resultado medido** (`tests/Feature/Api/V1/EmployeeIndexQueryCountTest.php`, 40 empleados en
una página): **< 10 queries totales** (antes habría sido ~41: 1 de listado + 40 de asignación).
Medido con `DB::enableQueryLog()`/`DB::getQueryLog()` real, no estimado.

## 3.3 — Auditoría de consultas (`LogApiQuery`)

- `last_used_at` ya NO se reescribe en cada petición — solo si pasaron más de 60s desde la
  última escritura (`tests/Feature/Api/V1/ApiQueryLogPerformanceTest.php`, verificado que el
  valor se mantiene estable entre dos peticiones consecutivas).
- Toda la auditoría corre en `try/catch` — una falla de escritura del log nunca puede romper
  la respuesta ya enviada al cliente.
- Retención configurable (`API_QUERY_LOG_RETENTION_DAYS`, default 90) vía
  `api:query-logs:prune`, agendado diario 03:00, borrado por lotes de 1000 IDs (nunca un DELETE
  masivo que bloquee la tabla). Probado con datos reales de 200 días vs 5 días de antigüedad.

## 3.4 — Índices e historia

Se revisaron los índices de `fact_recoveries`/`fact_portfolios`/`fact_placements`/
`fact_expenses`/`employee_branch_assignments` — todos los patrones de consulta que esta fase
introdujo (`period_id`+`employee_id`, `branch_id`) ya están cubiertos por índices existentes
(compuesto `period_id`+`branch_id`, único `period_id`+`employee_id`, índice implícito de FK en
`branch_id`). **No se modificó** el motor de cálculo de radiografías
(`BranchRadiographyCalculator`/`RadiographySnapshotBuilder`) — es código extenso, ya probado
históricamente, y no se encontró un defecto de rendimiento concreto ahí que justificara el
riesgo de tocarlo bajo el alcance de este encargo (seguridad/rendimiento de la API, no
reescritura del motor financiero).

## Qué NO se midió (honestidad explícita)

- Tiempo de respuesta en milisegundos (no se instrumentó un profiler real contra un volumen de
  producción).
- Consumo de memoria bajo carga.
- Tiempo de exportación con periodos grandes/reales.

Estas tres mediciones requieren un entorno con datos de producción representativos, que no
está disponible desde este entorno de desarrollo — no se inventan cifras donde no se midió.

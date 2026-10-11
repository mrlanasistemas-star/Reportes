# Seguridad de la API — correcciones Fase 2 (2026-10-11)

Auditoría externa identificó 9 puntos sobre `/api/v1`. Estado de cada uno, con el archivo que
lo corrige y la prueba que lo prueba.

| # | Hallazgo | Corrección | Archivo(s) | Prueba |
|---|---|---|---|---|
| 2.1 | `financial-summary` solo exigía `employees.read` | Middleware `ensure.ability` ahora acepta y exige VARIAS abilities (`employees.read,financial.read`) | `routes/api.php`, `EnsureApiAbility.php` | `ApiDualAbilityAndValidationTest` (4 casos) |
| 2.2 | IDOR: `scope=employee`/`employee_id` no verificaba sucursal autorizada | `ApiScopeResolver` centraliza la verificación para TODO endpoint que reciba un `employee_id`, vía `EmployeeBranchHistoryResolver` | `ApiScopeResolver.php`, `EmployeeBranchHistoryResolver.php` | `ApiEmployeeBranchHistoryTest` (escenario IDOR explícito) |
| 2.3 | Sucursal histórica vs actual (ej. Tula→Cuernavaca) | Resolución por el `period_id` EXACTO de la consulta — nunca "última asignación conocida" | `EmployeeBranchHistoryResolver::resolveForPeriod()` | `ApiEmployeeBranchHistoryTest` (9 casos, escenario Tula/junio vs Cuernavaca/octubre) |
| 2.4 | `/branches` ignoraba `allowed_branch_ids` | Filtra por sucursales autorizadas salvo ability independiente `branches.read.global` | `BranchController.php` | `ApiOkrAndBranchCatalogAuthorizationTest` (3 casos) |
| 2.5 | `allowed_branch_ids = null` y `= []` eran indistinguibles (ambos = acceso global) | Estado explícito `branch_access_mode` ∈ {global, restricted, none} — `[]` nunca amplía privilegios | Migración `add_branch_access_mode_to_api_consumers_table`, `ApiConsumer.php` | `ApiConsumerCommandsTest` (3 casos de los 3 estados) |
| 2.6 | OKR no filtraba por sucursal del objetivo, solo por sucursal "actual" del colaborador | Filtrado POR OBJETIVO (su propia `branch_id` o la del padre), 403 solo si ningún objetivo es visible | `OkrController::employeeSummary()` | `ApiOkrAndBranchCatalogAuthorizationTest` (3 casos) |
| 2.7 | `(int)` silencioso convertía `"abc"` en `0` | `ApiValidation` centraliza `nullableId/requiredId/perPage/enumValue`, 422 explícito; rutas con constraint `[0-9]+` | `ApiValidation.php`, `routes/api.php` | `ApiDualAbilityAndValidationTest` (7 casos) |
| 2.8 | `/capabilities` no distinguía "implementado" de "permitido para este consumidor" | `implemented`/`permitted`/`blocked_reason` separados por recurso | `CapabilitiesController.php` | `ApiFinancialEndpointsTest::exposes capabilities...` |
| 2.9 | Nada impedía que un token tuviera más abilities que su consumidor | `EnsureApiAbility` verifica TOKEN y CONSUMIDOR (techo real); `LogApiQuery` nunca guarda tokens/valores | `EnsureApiAbility.php` | `ApiAuthenticationTest::rejects a token with an ability the consumer itself was never granted` |

## Casos de seguridad verificados end-to-end (Fase 6)

Ver `tests/Feature/Api/V1/ApiFinalSecurityChecklistTest.php`:

- Token revocado → 401 (verificado contra el mecanismo real de Sanctum, `PersonalAccessToken::findToken`).
- Superficie completa de `/api/v1` es de SOLO LECTURA — 0 rutas con método distinto de GET/HEAD.
- Un token de `ApiConsumer` nunca autentica contra el guard `web` (aislamiento de guards real,
  no solo "no hay botón para eso en la UI").

## Qué NO cambió

- Ningún cálculo financiero de Reportería (EBITDA/OPEX/cartera/etc.) — la API sigue sin
  recalcular nada, solo lee `RadiografiaExportService::buildSnapshot()`.
- La efectividad de cobranza histórica (`RadiographySnapshotBuilder::buildEfectividadCobranza()`)
  no se tocó.
- Ningún consumidor existente perdió o ganó privilegios como efecto secundario de la migración
  `branch_access_mode` (backfill preserva exactamente el comportamiento previo).

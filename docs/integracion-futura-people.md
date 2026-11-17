# Integración futura: MR Lana People (sistema maestro de colaboradores)

Este documento describe cómo se preparó Reportería para una futura migración de identidad
de colaboradores hacia un sistema maestro ("MR Lana People"), **sin activar nada hoy**.

## 1. Qué NO se hizo (a propósito)

- No se migraron identidades de `employees` a ningún sistema externo.
- No se reemplazó la tabla `employees` ni su forma de administrarse.
- No se fusionaron `users` y `employees` en una sola entidad (siguen siendo dos tablas,
  vinculadas opcionalmente 1:1 vía `users.employee_id`, ver `docs/OKR.md`).
- No hay sincronización automática/destructiva de ningún tipo.

## 2. Qué SÍ se preparó

`employee_external_identities` (migración `2026_10_11_011802_...`, modelo
`App\Models\EmployeeExternalIdentity`):

| Campo | Propósito |
|---|---|
| `employee_id` | FK a `employees` (cascade on delete) |
| `system_code` | p. ej. `mr_lana_people`, `auditoria-operativa` |
| `external_id` | ID del colaborador en el sistema externo |
| `external_type` | default `employee` — deja espacio a otros tipos a futuro |
| `verified_at` / `verification_method` | el vínculo es EXPLÍCITO, nunca automático por nombre |
| `active` | permite desactivar una correspondencia sin borrar el historial |

Restricción de unicidad: una identidad externa activa (`system_code` + `external_type` +
`external_id`) solo puede apuntar a un `employee_id` a la vez — evita vincular por error la
misma persona externa a dos colaboradores distintos.

## 3. Campos que People debería proveer a futuro

Cuando exista el sistema maestro, el objetivo es que pueda entregar (vía una futura
integración, no vía escritura directa a las tablas de Reportería):

- Identidad maestra / número de empleado
- Empresa, sucursal, departamento, puesto
- Fecha de ingreso / baja, historial laboral
- Estado del colaborador (activo/baja)

Reportería seguiría siendo dueña de sus propios cálculos financieros; People sería la
fuente de verdad de identidad/estado laboral, correlacionada vía
`employee_external_identities.system_code = 'mr_lana_people'`.

## 4. Cómo se activaría (futuro, no implementado)

1. Poblar `employee_external_identities` con el mapeo verificado
   (`system_code='mr_lana_people'`) — proceso de correspondencia explícito, revisado por un
   humano o por un matching con umbral de confianza que SIEMPRE pueda revisarse/revertirse,
   nunca automático y silencioso.
2. Un nuevo endpoint de la API (`/api/v1/employees?external_system=mr_lana_people`) podría
   resolver IDs externos a `employee_id` internos sin tocar `employees` directamente.
3. Solo entonces se evaluaría si altas/bajas/cambios de puesto deben "espejearse" desde
   People — y seguiría siendo una decisión explícita de negocio, no un efecto automático de
   esta tabla.

## 5. Estado actual

Actualizado 2026-11-17: **MR. LANA PEOPLE ya existe** y consume la API v1 de solo lectura
para su módulo «Seguimiento» (consumidor `mr-lana-people`, ver `docs/api-reporteria.md` §15:
`/periods`, `/periods/{id}/branches`, `/periods/{id}/employees` y
`/periods/{id}/weekly-activity`).

- La correspondencia colaborador ↔ `employee.id` y sucursal ↔ `branch.id` vive **en PEOPLE**
  (tabla de mapeo auditable, confirmada por una persona). Reportería no escribe ni recibe
  identidades de PEOPLE; `employee_external_identities` sigue sin poblarse.
- Llaves: `employee_code` (clave NOI) **no es único** en Reportería (p. ej. el código `7`
  pertenece a dos personas distintas), por lo que nunca se vincula solo por código; se sugiere
  por código + nombre y se confirma a mano. Un `branch` de Reportería es una ruta/oficina
  operativa; una sucursal de PEOPLE puede agrupar varias.
- Lo descrito en §3-§4 (People como fuente maestra de identidad/estado laboral) sigue pendiente
  y es una decisión de negocio aparte.

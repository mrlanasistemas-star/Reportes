# Integración con Auditoría Operativa MR Lana

Guía mínima para que el sistema de Auditoría consuma `/api/v1` sin duplicar cálculos
financieros ni tocar la base de datos de Reportería directamente.

## 1. Alta de credenciales

Pedir a Sistemas (Reportería) que ejecute:

```bash
php artisan api:consumers:create "Auditoría Operativa" "auditoria-operativa" \
    --ability=branches.read --ability=employees.read --ability=periods.read \
    --ability=financial.read --ability=collections.read --ability=okr.read
```

El token se entrega una sola vez, por un canal seguro (nunca por correo plano). Si
Auditoría solo debe ver ciertas sucursales, agregar `--branch=<id>` por cada una — esto
bloquea `scope=general` para ese token.

## 2. Flujo recomendado

1. `GET /api/v1/periods?type=monthly` — resolver el `period_id` que se necesita (Auditoría
   nunca debe asumir IDs; los periodos no son correlativos 1:1 con meses calendario).
2. `GET /api/v1/branches` y `/employees` — catálogos para mapear sus propios IDs internos.
3. `GET /api/v1/financial/summary?period_id=X&scope=branch&branch_id=Y` — indicadores por
   sucursal, o `scope=employee&employee_id=Z` por colaborador.
4. Revisar siempre `status` antes de usar `value` — `not_available`/`not_attributable`
   significan "no hay dato real", nunca "es cero".

## 3. Lo que Auditoría NO debe hacer

- No debe replicar la base de datos financiera completa (`fact_*`, `period_*_summaries`) —
  pedir solo lo que necesite vía API, cacheando localmente con `calculated_at`/
  `definition_version` para saber cuándo refrescar.
- No debe inferir el valor individual de un colaborador dividiendo el total de la sucursal
  — si la API devuelve `not_attributable`, ese dato simplemente no existe a ese nivel hoy.
- No debe tratar `collections_effectiveness_legacy` como si fuera la calculadora
  "Aprobada" — son versiones distintas, ver `docs/efectividad-cobranza-aprobada.md`.

## 4. Correspondencia de identidades

Si Auditoría tiene su propio ID de colaborador/sucursal, usar
`App\Models\EmployeeExternalIdentity` (tabla `employee_external_identities`) para crear la
correspondencia explícita — nunca inferirla por coincidencia de nombre. Hoy no hay un
endpoint público para escribir esta tabla (es de solo lectura desde la API); el vínculo se
crea manualmente desde Reportería hasta que se defina un flujo de alta compartido.

## 5. Límites y buenas prácticas

- Rate limit: 60 req/min por consumidor. Cachear catálogos (`/branches`, `/financial/metrics`)
  — cambian con poca frecuencia.
- Todas las peticiones quedan auditadas en `api_query_logs` del lado de Reportería.
- HTTPS obligatorio en producción — el token viaja en el header `Authorization`.

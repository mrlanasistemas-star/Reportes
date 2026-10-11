# Migración a un nuevo VPS — notas relevantes a este trabajo

No se ejecutó ninguna migración de infraestructura real desde esta sesión (sin acceso al
VPS). Este documento son las notas que un futuro traslado debe considerar, en relación
específica con lo construido en Fases 1-4.

## 1. Principio: nunca perder archivos originales ya cargados

La Fase 3 (descarga de archivos originales) depende de que `storage/app/public/reports/`
(disco `public`) se copie íntegro al nuevo VPS — son los bytes físicos de cada
`ReportUpload.stored_path`. Si se pierde ese directorio, la descarga devuelve el 404
controlado (`ReportUploadController::download()`), nunca un error opaco, pero el archivo
original ya no se podría recuperar. Checklist de migración:

- [ ] Copiar `storage/app/public/` completo (incluye `reports/`, `radiografias/`,
      `okr-warnings/`, `okr-commitment-letters/`, etc.) — no solo la base de datos.
- [ ] Confirmar `php artisan storage:link` corrió en el nuevo VPS (el disco `public` se
      sirve vía el symlink `public/storage`).
- [ ] Verificar que los `stored_path` en la tabla `report_uploads` siguen siendo rutas
      RELATIVAS (lo son — ver `ReportUploadService::store()`, usa `$file->store('reports',
      'public')`) — por eso la migración de servidor no requiere reescribir la BD.

## 2. Reprocesamiento desde archivos originales conservados

Ya existe `ReprocessReportUploadJob`/`ReprocessPeriodUploadsJob` (anteriores a esta sesión)
para reprocesar desde los archivos ya guardados sin pedir que el usuario los vuelva a
subir. La Fase 3 de este trabajo no cambió ese mecanismo — solo agregó la capacidad de
DESCARGAR el original, no de reprocesarlo distinto.

## 3. API nueva (`/api/v1`) — nada que migrar aparte

Los tokens Sanctum viven en `personal_access_tokens` (tabla estándar), que se migra con el
resto de la base de datos. **Los tokens en texto plano no se pueden recuperar tras la
migración** (Sanctum solo guarda el hash) — si Auditoría u otro consumidor pierde su token
por cualquier motivo relacionado con la migración, debe emitirse uno nuevo con
`php artisan api:consumers:revoke` + `api:consumers:create` (ver
`docs/api-reporteria.md`), nunca intentar "recuperar" el anterior.

## 4. Invalidación de proyecciones financieras tras reimportar

Fuera de alcance de esta sesión (no se tocó el pipeline de radiografías) — el mecanismo de
invalidación de `PeriodSummary`/`PeriodRadiographyRun` ya existente no cambió. La API nueva
simplemente lee lo que ya esté calculado (`buildSnapshot()`), así que una radiografía
invalidada/reimportada se refleja automáticamente en la próxima consulta a `/api/v1/financial/*`
sin ningún paso adicional de migración.

## 5. Efectividad de Cobranza Aprobada (Fase 2) — bloqueada, no migrar nada todavía

No existe código ni tabla nueva para este módulo (ver
`docs/efectividad-cobranza-aprobada.md`) — no hay nada que migrar en este frente hasta que
se resuelva el bloqueo de la fuente VBA.

## 6. Configuración específica de Browsershot por servidor

`BROWSERSHOT_NODE_BINARY`/`BROWSERSHOT_CHROME_PATH` son rutas ABSOLUTAS — nunca se migran
tal cual entre Windows (este entorno de desarrollo) y Linux (VPS). En el nuevo VPS deben
apuntar a los binarios reales de ESE servidor (típicamente `/usr/bin/node`,
`/usr/bin/chromium` o la ruta que instale Puppeteer). Ver checklist completo en
`docs/okr-documentos-browsershot.md` y `docs/deployment-workers.md`.

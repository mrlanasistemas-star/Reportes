# Generación de documentos OKR (Warning / Carta Compromiso) — Browsershot

Auditoría Fase 4.6-4.9. Hallazgo principal: **esto ya estaba implementado** antes de esta
sesión (commits `872d736`, `672cdde`) — el trabajo real de esta auditoría fue **verificar
que de verdad funciona en este entorno**, no construirlo de cero.

## 1. Arquitectura (ya existente, verificada)

- `App\Services\Pdf\BrowsershotPdfRenderer` — capa única de PDF de todo el sistema (no solo
  OKR: también Reportería). Envuelve `spatie/browsershot`, espera
  `window.__PDF_READY__ === true` (nunca un `sleep()` fijo), soporta `full_bleed` (sin
  márgenes/footer inyectados — usado por Warning/Carta porque el footer ya viene en el
  fondo rasterizado), valida binarios con pre-flight (`is_file()`) antes de intentar
  ejecutar, y traduce fallos a `PdfRenderException` tipadas (`NODE_NOT_FOUND`,
  `CHROME_NOT_FOUND`, `TIMEOUT`, `CHART_TIMEOUT`, `HTML_FAILED`).
- `OkrWarningService` / `OkrCommitmentLetterService` — construyen un snapshot inmutable
  (datos congelados de la semana/Objective, nunca "el valor de hoy"), generan folio
  (`OkrDocumentFolioGenerator`, p. ej. `WR-2026-000123`), renderizan a
  `storage/okr-warnings/{objectiveId}/{folio}.pdf` /
  `storage/okr-commitment-letters/{objectiveId}/{folio}.pdf`.
- Plantillas Blade (`resources/views/reports/okr-warning-pdf.blade.php`,
  `okr-commitment-letter-pdf.blade.php`): overlay pixel-perfect sobre PNG rasterizado del
  machote original de la empresa, coordenadas extraídas con `pdftotext -bbox` del PDF
  contractual real — nunca una captura de pantalla estirada.
- **Preview = misma plantilla que el PDF final**: `CommitmentLetterController::preview()`
  llama `renderPreviewPdf()`, que usa la MISMA vista Blade que `generate()` — nunca dos
  diseños que puedan divergir (ver Fase 4.7 del encargo).

## 2. Verificación realizada en esta sesión

Antes de esta auditoría, 2 de 4 tests de `BrowsershotPdfRendererTest` estaban **skipped**
en este entorno porque solo `BROWSERSHOT_CHROME_PATH` estaba configurado en `.env`, pero no
`BROWSERSHOT_NODE_BINARY` (el chequeo pre-flight `is_file()` fallaba con el valor vacío).

Se verificó que sí existen binarios reales utilizables en este equipo Windows:

```
node:   C:\Program Files\nodejs\node.exe        (confirmado con `where node`)
chrome: C:\Program Files\Google\Chrome\Application\chrome.exe (confirmado, is_file=true)
```

Se agregó la variable faltante a `.env` (archivo local, no versionado):

```
BROWSERSHOT_NODE_BINARY="C:/Program Files/nodejs/node.exe"
```

Resultado — los 4 tests pasan, incluyendo render real de HTML→PDF y el timeout real de
`window.__PDF_READY__`:

```
PASS Tests\Feature\BrowsershotPdfRendererTest
✓ throws PDF_RENDER_NODE_NOT_FOUND when the configured node binary path does not exist
✓ throws PDF_RENDER_CHROME_NOT_FOUND when the configured chrome path does not exist
✓ renders real HTML to a valid, non-empty PDF file when Node/Chrome are correctly configured
✓ throws PDF_RENDER_CHART_TIMEOUT when window.__PDF_READY__ never becomes true
```

Y los 13 tests de Warning/Carta Compromiso (incluyendo los que renderizan la plantilla real,
no solo la lógica de negocio) también pasan con el motor de PDF real activo:

```
PASS OkrCommitmentLetterPdfTemplateTest, OkrCommitmentLetterTest, OkrFullClosureRealScenarioTest,
     OkrPlacementWeeklyUploadTest, OkrWarningPdfTemplateTest, OkrWarningTest
13 passed (103 assertions)
```

## 3. Puppeteer (Node) — ya declarado, no instalado aparte

`package.json` ya tenía `"puppeteer": "^25.11.0"` como dependencia (`npm install` ya lo
descarga junto con el resto del front). No fue necesario agregarlo.

## 4. Checklist de la solicitud (Fase 4.6)

| Ítem | Estado en este entorno (Windows) |
|---|---|
| Node | ✅ `C:\Program Files\nodejs\node.exe` |
| Chrome/Chromium | ✅ `C:\Program Files\Google\Chrome\Application\chrome.exe` |
| Puppeteer | ✅ declarado en `package.json`, no requiere paso adicional |
| Browsershot | ✅ `spatie/browsershot` en `composer.json`, capa `BrowsershotPdfRenderer` |
| Fuentes | No verificado al detalle — las plantillas usan fuentes del sistema/web-safe, sin reporte de glifos faltantes en las pruebas |
| Permisos de ejecución | ✅ (las pruebas reales ejecutan el binario sin error de permisos) |
| Directorios temporales | ✅ `storage/app/` ya usado por el resto de exports, sin cambios |
| Memoria/Timeouts | Configurable vía `BROWSERSHOT_TIMEOUT`/`BROWSERSHOT_PDF_READY_TIMEOUT` (`config/pdf.php`) — no se estresó con PDFs de miles de páginas en esta sesión |
| Ruta de ejecutables configurable | ✅ vía `.env` (`BROWSERSHOT_NODE_BINARY`/`BROWSERSHOT_CHROME_PATH`) |
| Funcionamiento en Windows | ✅ **verificado real en esta sesión** (este entorno) |
| Funcionamiento en Linux | **No verificado** — este entorno de trabajo es Windows; el VPS de producción (Linux) debe confirmarse por separado, ver `docs/deployment-workers.md` |

## 5. Pendiente / fuera de esta sesión

- Confirmar en el VPS Linux de producción que `BROWSERSHOT_NODE_BINARY`/
  `BROWSERSHOT_CHROME_PATH` apuntan a binarios reales ahí (rutas típicas:
  `/usr/bin/node`, `/usr/bin/chromium-browser` o el Chrome que instale Puppeteer bajo
  `node_modules/puppeteer/.local-chromium`).
- No se hizo una revisión visual humana de los PDF generados (los tests verifican
  existencia/tamaño del archivo, no su apariencia pixel a pixel) — se confía en que las
  plantillas ya fueron validadas visualmente en los commits previos (`672cdde`: "pixel-perfect
  sobre el machote original").

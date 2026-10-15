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

## Actualización 14-oct-2026 — plantillas rehechas en HTML de flujo

El overlay con `position:absolute` sobre el machote rasterizado se **retiró**: nombres u
objetivos largos pisaban etiquetas, líneas y casillas. Ahora:

- Layout común `resources/views/reports/layouts/okr-document.blade.php`: contenido como HTML
  real (tablas, grid, saltos de página controlados). Del machote solo se conservan los
  elementos corporativos decorativos recortados 1:1 del original
  (`resources/templates/okr/branding/header.png`, `footer.png`, `watermark.png`), repetidos en
  cada página con `position:fixed` y un `<thead>/<tfoot>` espaciador que reserva su alto.
- Tipografía **Montserrat** (SIL OFL 1.1, `resources/fonts/montserrat/OFL.txt`) embebida en
  base64 desde archivos locales — sin Google Fonts ni internet al imprimir. La señal
  `window.__PDF_READY__` se activa cuando `document.fonts.ready` confirma la fuente.
- Sigue usándose `BrowsershotPdfRenderer` con `full_bleed=true` (el pie corporativo lo dibuja
  la plantilla, no Puppeteer).
- Formato de valores: `App\Services\Okr\OkrDocumentFormatter` (moneda MXN `$1,234.56`,
  porcentajes, pp, fechas en español).
- Carta Compromiso: revisión previa (`GET /okr/{id}/commitment-letter/review`) que lista los
  datos indispensables faltantes; campos autorizados capturables: puesto, jefe inmediato y
  observaciones. La emisión definitiva se rechaza (422) mientras falte un dato.
- Carta del gestor = SUS Key Results individuales; carta de sucursal = meta de sucursal.
- Warning: imprime también las acciones correctivas y observaciones capturadas; usa los KR y
  snapshots del Objective evaluado (un Warning individual nunca usa resultados de la sucursal).

## Actualización 15-oct-2026 — jefe inmediato confirmado, Warnings con motivo exacto, PDFs revisados visualmente

**Carta Compromiso**
- Jefe inmediato: solo el CONFIRMADO en la revisión. Se rechaza (queda "Pendiente de captura" y
  no se emite) si es el propio colaborador evaluado, o si coincide con el usuario responsable /
  creador del OKR y el directorio laboral vigente no lo respalda como su jefe
  (`OkrCommitmentLetterService::bossIssue()`).
- Sin jefe confirmado la emisión responde 422 con el mensaje
  "No es posible emitir la Carta Compromiso porque falta confirmar el jefe inmediato." y
  `action: complete_information` → la UI muestra el botón **Completar información**
  (enfoca el campo). Excepción tipada: `OkrCommitmentLetterIncompleteException`.
- Fallo del motor PDF al emitir: la transacción se revierte (sin folio) y se responde con una
  referencia `CC-PDF-…` registrada en el log. La vista previa fallida muestra una página HTML
  legible con referencia `CC-PREV-…`.
- Plantilla: periodo de medición dentro de "I. Datos del colaborador" (la tabla de KPI ya no se
  parte con una fila suelta), firmas en tabla alineada (`reports/partials/okr-signatures`),
  rol "Jefe inmediato" (ya no "Jefe inmediato / Responsable").

**Warning**
- La ventana siempre abre; motivos clasificados: OKR no activado, Falta KR, Semana abierta
  ("La semana todavía no termina. Podrás evaluar su cumplimiento después del cierre."),
  Falta colocación, Sin evidencia suficiente, Sin incumplimiento comprobado, Documento ya
  emitido (con folio), Falta autorización (`authorization_notice`). Semana sin información =
  "Pendiente de evaluación", nunca incumplimiento.
- Error técnico real: "No pudimos consultar la información. Intenta nuevamente. Si continúa,
  proporciona esta referencia a Sistemas: WR-ELIG-…" (referencia en el log). Corregido el
  `catch (Throwable)` sin `use Throwable;` que nunca atrapaba nada.
- El Warning imprime número de empleado y jefe inmediato de la persona evaluada.

**Tipografía**: `reports/partials/montserrat-font-face.blade.php` embebe Montserrat local
(base64) — lo usan los documentos OKR, el PDF del Dashboard y la calculadora de Efectividad.
`pdffonts` reporta `MontserratThin-*` (nombre interno de la familia variable de
@fontsource; es Montserrat con sus pesos 400–800). El pie de página que inyecta Chrome en
los PDFs no full-bleed sigue en Helvetica/Arial (las plantillas de pie de Chrome no cargan
fuentes web). Radiografías y guía siguen en Helvetica/Arial (no cambiadas: tablas densas).

**Dashboard**: "Exportar PDF" ahora llama `GET /dashboard/exportar.pdf?period_id=&branch_id=`
(`DashboardController::exportPdf`, vista `reports.dashboard-pdf`, gráficas SVG de
`DashboardPdfChartBuilder`) con los mismos 8 KPI y 4 gráficas del Dashboard. Antes descargaba
la Radiografía completa.

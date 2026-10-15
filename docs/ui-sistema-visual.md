# Sistema visual — Reportes MR LANA

Guía breve para que todos los módulos se vean y se comporten igual. Fuente de verdad:
`resources/js/styles/theme.css` (tokens) y `resources/css/app.css` (clases `app-*`).

## Principios

- Corporativo y sobrio: fondo claro, tarjetas blancas con borde fino y sombra suave. Sin degradados
  llamativos, colores fluorescentes ni animaciones decorativas en bucle.
- Una acción principal por bloque (botón `primary`). El resto, `outline` o `ghost`.
- Acciones peligrosas (eliminar, reabrir, desactivar) separadas, en texto/borde `danger`, y siempre
  con confirmación.
- Todo control deshabilitado explica por qué (texto `app-help` debajo o `title`).
- Modo claro por defecto. El oscuro se conserva y se activa en Configuración → Apariencia.

## Tokens (theme.css)

| Uso | Token / utilidad Tailwind |
| --- | --- |
| Acción principal (verde institucional, contraste AA con texto blanco) | `bg-primary text-primary-foreground` (`#047857`) |
| Acento suave / seleccionado | `bg-accent text-accent-foreground` |
| Acento secundario de marca (índigo existente) | `text-brand-accent`, `bg-brand-accent/10` |
| Superficies | `bg-background`, `bg-card`, `bg-muted` |
| Texto | `text-foreground`, `text-muted-foreground` |
| Estados | `success`, `warning`, `danger`, `info` + variantes `-soft` (fondo) y `-border` |
| Sombras | `shadow-soft-xs` (tarjetas), `shadow-soft-md` (flotantes), `shadow-soft-lg` (modales) |
| Radio base | `--radius: 1rem` → `rounded-lg` 16px · `rounded-xl` 20px; usa `rounded-xl/2xl` en tarjetas y `rounded-lg` en botones |

Evita colores fijos (`bg-indigo-600`, `text-slate-500`…) en código nuevo: usa los tokens para que el
modo oscuro funcione sin `dark:` repetidos.

## Tipografía

- `app-kicker` — etiqueta superior en mayúsculas (módulo o paso: “Operación”, “Paso 1”).
- `app-title` — título de página (24–28 px, bold).
- `app-subtitle` — explicación bajo el título (14 px, muted).
- `app-section-title` — título de tarjeta/sección (16 px, semibold).
- Texto de cuerpo 14 px; ayudas 12 px (`app-help`). Nunca menos de 11 px.

## Componentes y clases

- Encabezado de página: `components/app/AppPageHeader.vue` (props `kicker`, `title`, `subtitle`;
  slots `actions`, `icon`).
- Sección/tarjeta: `.app-section` / `AppSectionCard.vue` (slot `actions`); `.app-card` para tarjetas simples.
- KPI: `AppStatCard.vue`. Vacío: `AppEmptyState.vue`.
- Tablas: `.app-table-wrap` + `.app-table` (encabezado gris en mayúsculas, filas con hover).
- Banners: `.app-banner` + `.app-banner-info | -success | -warning | -danger` (icono SVG como primer hijo).
- Píldoras de estado: `.app-pill` + `.app-pill-success | -warning | -danger | -info | -muted`.
- Errores de campo: `components/InputError.vue` (icono + `role="alert"`). Ayuda: `.app-help`.
- Zona peligrosa: `.app-danger-zone`.
- Configuración: `components/settings/SettingsCard.vue` (icono, título, descripción, pie de acciones).
- Formularios: botones de envío muestran estado (“Guardando…”) y se deshabilitan mientras procesan.

## Carga y retroalimentación

- La pantalla de carga global (`lib/routeLoadingOverlay.ts`) solo aparece en cambios de pantalla (GET)
  que tardan más de 600 ms y en los procesos largos de Histórico General. Los formularios
  (POST/PUT/PATCH/DELETE) usan el estado de su propio botón.
- Errores 422 junto al campo; 403/500 con mensaje claro; nada queda bloqueado tras un error o cancelación.

## Navegación

- Menú lateral agrupado (Análisis, Operación, Administración, Ayuda y cuenta) desde `config/mainNav.ts`
  (`group`, `shortTitle` para la barra móvil). El filtrado por módulos del rol no cambia.
- Ítem activo: fondo `accent`, texto semibold y barra lateral verde; `aria-current="page"`.

## Accesibilidad y responsive

- Foco visible (`outline` con `--ring`) en todos los controles; Escape cierra modales y el recorrido.
- `prefers-reduced-motion` desactiva animaciones y scroll suave.
- Probar a 390 px: sin scroll horizontal de página (tablas anchas dentro de `overflow-x-auto`),
  botones de al menos 36 px de alto, textos sin cortar (usar `shortTitle`, `truncate` + `title`).
- Iconos decorativos con `aria-hidden="true"`; botones de solo icono con `aria-label`.

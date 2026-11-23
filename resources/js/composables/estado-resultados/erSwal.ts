/**
 * Módulo: avisos SweetAlert2 del Estado de Resultados.
 *
 * Reúne los helpers de diálogo que usan los composables del ER
 * (useErActions, useErGovernance, useMovementControl, etc.):
 * - `swal`: instancia común con el estilo de la app.
 * - `htmlList` / `rowErrorsTable`: fragmentos HTML (escapados) para listar
 *   detalles o errores por fila de una carga rechazada.
 * - `busy` / `updateBusy` / `closeSwal`: aviso modal de "trabajando…".
 * - `showError`: presenta un `ApiError` (o cualquier error) de forma explicada.
 */
import { ApiError, escapeHtml } from '@/components/estado-resultados/types';
import type { RowError } from '@/components/estado-resultados/types';
import { appSwal } from '@/components/feedback/ConfirmDialog';

/**
 * SweetAlert del módulo con la tarjeta y los botones de la app: deriva de la
 * puerta única `appSwal` (components/feedback/ConfirmDialog.ts).
 */
export const swal = appSwal;

/**
 * Lista HTML con viñetas para mostrar detalles dentro de un SweetAlert.
 * Cada elemento se escapa para evitar inyección de HTML.
 * @param items Textos a listar.
 * @returns HTML de la lista, o cadena vacía si no hay elementos.
 */
export const htmlList = (items: string[]) =>
    items.length
        ? `<ul style="text-align:left;margin:10px auto 0;font-size:13px;max-height:240px;overflow:auto;max-width:460px">${items.map((d) => `<li style="margin:4px 0">• ${escapeHtml(d)}</li>`).join('')}</ul>`
        : '';

/**
 * Tabla compacta de errores por fila (carga rechazada): Fila / Columna / Qué corregir.
 * Si hay más de `max` errores, añade una nota que remite a "Intentos de carga" en Fuentes.
 * @param errors Errores devueltos por el servidor.
 * @param max Máximo de filas a mostrar (por defecto 60).
 * @returns HTML de la tabla, o cadena vacía si no hay errores.
 */
export function rowErrorsTable(errors: RowError[], max = 60): string {
    if (!errors.length) {
        return '';
    }

    const rows = errors
        .slice(0, max)
        .map(
            (e) =>
                `<tr><td style="padding:3px 8px;white-space:nowrap;font-variant-numeric:tabular-nums">${e.row ?? '—'}</td><td style="padding:3px 8px;white-space:nowrap">${escapeHtml(e.column ?? '—')}</td><td style="padding:3px 8px">${escapeHtml(e.message)}</td></tr>`,
        )
        .join('');
    const more =
        errors.length > max
            ? `<p style="margin-top:6px;font-size:12px">…y ${errors.length - max} error(es) más. Consulta “Intentos de carga” en Fuentes.</p>`
            : '';

    return `<div style="margin-top:12px;max-height:260px;overflow:auto;text-align:left;border:1px solid var(--border);border-radius:12px"><table style="width:100%;font-size:12px;border-collapse:collapse"><thead><tr style="text-align:left;background:var(--muted)"><th style="padding:4px 8px;white-space:nowrap">Fila</th><th style="padding:4px 8px;white-space:nowrap">Columna</th><th style="padding:4px 8px;white-space:nowrap">Qué corregir</th></tr></thead><tbody>${rows}</tbody></table></div>${more}`;
}

/**
 * Pantalla de "trabajando…" (no se puede cerrar a mano: sin clic fuera, sin Esc
 * ni botón). Se cierra con `closeSwal()` o al abrir otro aviso.
 * @param title Título del aviso.
 * @param text Texto explicativo.
 */
export function busy(title: string, text: string): void {
    swal.fire({
        title,
        text,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => appSwal.showLoading(),
    });
}

/**
 * Actualiza el aviso de "trabajando…" sin volver a abrirlo (evita parpadeo al
 * consultar una tarea). Si no hay un aviso visible, abre uno nuevo con `busy`.
 * @param title Nuevo título.
 * @param text Nuevo texto (p. ej. la etapa actual de la tarea).
 */
export function updateBusy(title: string, text: string): void {
    const titleEl = appSwal.isVisible() ? appSwal.getTitle() : null;
    const textEl = appSwal.isVisible() ? appSwal.getHtmlContainer() : null;

    if (!titleEl || !textEl) {
        busy(title, text);

        return;
    }

    titleEl.textContent = title;
    textEl.textContent = text;
}

/** Cierra el SweetAlert abierto (p. ej. el aviso de "trabajando…"). */
export function closeSwal(): void {
    appSwal.close();
}

/**
 * Error explicado; 423 (aprobado) y 409 (conflicto) se presentan como avisos, no como fallas.
 * Normaliza cualquier valor lanzado a `ApiError`; si trae errores por fila/columna
 * los muestra en tabla, si no, lista los detalles generales.
 * @param e Error capturado (ApiError, Error u otro valor).
 * @param title Título para errores que no son 423/409.
 * @returns La promesa del SweetAlert (se resuelve al cerrarlo).
 */
export function showError(
    e: unknown,
    title = 'No se pudo completar la acción',
) {
    const err =
        e instanceof ApiError
            ? e
            : new ApiError(e instanceof Error ? e.message : String(e));
    // Sólo errores con fila o columna van a la tabla (los generales ya están en la lista).
    const rowLevel = err.rowErrors.filter((x) => x.row !== null || x.column);
    const locked = err.status === 423;
    const conflict = err.status === 409;

    return swal.fire({
        icon: locked ? 'info' : conflict ? 'warning' : 'error',
        title: locked
            ? 'El periodo está aprobado'
            : conflict
              ? 'Hay otra operación en curso'
              : title,
        html: `<p>${escapeHtml(err.message)}</p>${rowLevel.length ? rowErrorsTable(rowLevel) : htmlList(err.details)}`,
        confirmButtonText: 'Entendido',
        width: rowLevel.length ? 680 : undefined,
    });
}

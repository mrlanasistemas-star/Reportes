import Swal from 'sweetalert2';
import { ApiError, escapeHtml } from '@/components/estado-resultados/types';
import type { RowError } from '@/components/estado-resultados/types';

/** SweetAlert con la tarjeta y los botones de la app (mismo estilo que useConfirm). */
export const swal = Swal.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    customClass: {
        popup: 'rounded-[28px] border border-border bg-card text-card-foreground shadow-2xl',
        title: 'text-xl font-bold text-foreground',
        htmlContainer: 'text-sm text-muted-foreground',
        actions: 'gap-3',
        confirmButton: 'app-btn app-btn-primary',
        cancelButton: 'app-btn app-btn-secondary',
        input: 'rounded-xl border border-border bg-background text-sm text-foreground',
        validationMessage: 'text-sm',
    },
});

export const htmlList = (items: string[]) =>
    items.length
        ? `<ul style="text-align:left;margin:10px auto 0;font-size:13px;max-height:240px;overflow:auto;max-width:460px">${items.map((d) => `<li style="margin:4px 0">• ${escapeHtml(d)}</li>`).join('')}</ul>`
        : '';

/** Tabla compacta de errores por fila (carga rechazada). */
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

/** Pantalla de "trabajando…" (no se puede cerrar a mano). */
export function busy(title: string, text: string): void {
    swal.fire({
        title,
        text,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
    });
}

export function closeSwal(): void {
    Swal.close();
}

/** Error explicado; 423 (aprobado) y 409 (conflicto) se presentan como avisos, no como fallas. */
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

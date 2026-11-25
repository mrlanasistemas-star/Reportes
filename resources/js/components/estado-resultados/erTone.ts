/**
 * Tonos de color con significado para las tarjetas y modales del Estado de Resultados
 * (pestaña Validación y detalle de movimiento). Cada tono trae su versión clara y oscura.
 */

/** Tono semántico: verde = cuadra, ámbar = pendiente, rosa = diferencia, etc. */
export type ErTone =
    | 'emerald'
    | 'amber'
    | 'rose'
    | 'sky'
    | 'violet'
    | 'cyan'
    | 'slate';

/** Fondo y color del icono redondeado (encabezado de tarjeta o modal). */
export const TONE_ICON: Record<ErTone, string> = {
    emerald:
        'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-900',
    amber: 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-900',
    rose: 'bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-900',
    sky: 'bg-sky-100 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-900',
    violet: 'bg-violet-100 text-violet-700 ring-violet-200 dark:bg-violet-950/60 dark:text-violet-300 dark:ring-violet-900',
    cyan: 'bg-cyan-100 text-cyan-700 ring-cyan-200 dark:bg-cyan-950/60 dark:text-cyan-300 dark:ring-cyan-900',
    slate: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700',
};

/** Chip suave (texto de color sobre fondo claro). */
export const TONE_CHIP: Record<ErTone, string> = {
    emerald:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    rose: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
    sky: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    violet: 'bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300',
    cyan: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300',
    slate: 'bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
};

/** Barra o franja de acento (borde superior de la tarjeta). */
export const TONE_ACCENT: Record<ErTone, string> = {
    emerald: 'from-emerald-500 to-emerald-300',
    amber: 'from-amber-500 to-amber-300',
    rose: 'from-rose-500 to-rose-300',
    sky: 'from-sky-500 to-sky-300',
    violet: 'from-violet-500 to-violet-300',
    cyan: 'from-cyan-500 to-cyan-300',
    slate: 'from-slate-400 to-slate-200',
};

/** Texto del importe principal según el tono. */
export const TONE_TEXT: Record<ErTone, string> = {
    emerald: 'text-emerald-700 dark:text-emerald-400',
    amber: 'text-amber-700 dark:text-amber-400',
    rose: 'text-rose-700 dark:text-rose-400',
    sky: 'text-sky-700 dark:text-sky-400',
    violet: 'text-violet-700 dark:text-violet-400',
    cyan: 'text-cyan-700 dark:text-cyan-400',
    slate: 'text-foreground',
};

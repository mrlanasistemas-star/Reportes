import type { RosterMovementStatus } from '@/types/asignaciones'

// Tonos por categoría de movimiento laboral (ver App\Services\Roster\RosterMovementStatus).
// Rojo SOLO para baja confirmada; ámbar = pendiente de conciliación; gris = informativo.
const TONES: Record<string, string> = {
    rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    slate: 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
}

const STATUS_TONE: Record<RosterMovementStatus, keyof typeof TONES> = {
    alta_confirmada: 'emerald',
    reingreso: 'emerald',
    plantilla_inicial: 'slate',
    activo_confirmado: 'slate',
    cambio_sucursal: 'sky',
    sin_actividad_comercial: 'slate',
    retorno_tras_ausencia: 'sky',
    baja_confirmada: 'rose',
    posible_baja: 'amber',
    no_localizado: 'amber',
    vigencia_desconocida: 'amber',
    ausencia_justificada: 'slate',
    registro_duplicado: 'amber',
    contradiccion_fuentes: 'amber',
    sin_clasificar: 'amber',
}

export function statusClasses(status?: string | null): string {
    const tone = status ? STATUS_TONE[status as RosterMovementStatus] : undefined

    return TONES[tone ?? 'slate']
}

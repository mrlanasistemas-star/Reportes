// Asistente "Asignar OKR" — tipos y reglas compartidas por los pasos
// (corrección 14-oct-2026). Un mismo KrRow sirve para los Key Results de la
// sucursal y para los de CADA gestor: cada fila tiene su propia línea base,
// incremento, meta y peso. Nunca se copian base/meta de la sucursal al gestor.
import { Hash, Percent, Wallet } from 'lucide-vue-next'

export type BaselineMode = 'auto' | 'manual' | 'manual_authorized'

export type BaselinePreview = {
    available: boolean
    value: number | null
    period: { id: number; label: string; start_date?: string | null; end_date?: string | null } | null
    scope_type?: string
    error?: string | null
}

export type KrRow = {
    client_key: string
    kpi_id: number | null
    description: string
    baseline_value: string
    increment: string
    target_value: string
    weight: string
    baseline_mode: BaselineMode
    baseline_manual_reason: string
    // Solo estado de UI (nunca se envían): filtros del catálogo y consulta.
    type_filter: string | null
    direction_filter: string | null
    preview: BaselinePreview | null
    loading: boolean
}

export type GestorPlan = {
    client_key: string
    employee_id: number
    employee_name: string
    title: string
    key_results: KrRow[]
}

export const KPI_TYPE_LABELS: Record<string, string> = { cumulative: 'Acumulativo', balance: 'Saldo', percentage: 'Porcentual' }
export const KPI_DIRECTION_LABELS: Record<string, string> = { increase: 'Incrementar', decrease: 'Disminuir' }
export const ALL_FILTER = '__all__'

export function kpiTypeLabel(type?: string | null): string {
    return type ? (KPI_TYPE_LABELS[type] ?? type) : '—'
}

export function kpiDirectionLabel(direction?: string | null): string {
    return direction ? (KPI_DIRECTION_LABELS[direction] ?? direction) : '—'
}

// Ícono + color por unidad real del KPI (el catálogo no tiene columna de ícono).
export function kpiIconFor(kpi: any): { icon: any; class: string } {
    if (kpi?.unit === 'currency') {
        return { icon: Wallet, class: 'bg-blue-500/10 text-blue-600 dark:text-blue-300' }
    }

    if (kpi?.unit === 'percentage') {
        return { icon: Percent, class: 'bg-rose-500/10 text-rose-600 dark:text-rose-300' }
    }

    return { icon: Hash, class: kpi ? 'bg-violet-500/10 text-violet-600 dark:text-violet-300' : 'bg-muted text-muted-foreground' }
}

export function round2(n: number): number {
    return Math.round(n * 100) / 100
}

export function newKrRow(partial: Partial<KrRow> = {}): KrRow {
    return {
        client_key: crypto.randomUUID(),
        kpi_id: null, description: '', baseline_value: '', increment: '', target_value: '', weight: '',
        baseline_mode: 'auto', baseline_manual_reason: '',
        type_filter: null, direction_filter: null, preview: null, loading: false,
        ...partial,
    }
}

/** Copia solo la ESTRUCTURA (KPI, descripción, peso) — jamás base, incremento ni meta. */
export function cloneKrStructure(rows: KrRow[]): KrRow[] {
    return rows.map((kr) => newKrRow({ kpi_id: kr.kpi_id, description: kr.description, weight: kr.weight }))
}

export function totalWeight(rows: KrRow[]): number {
    return round2(rows.reduce((sum, kr) => sum + (Number(kr.weight) || 0), 0))
}

export function weightIsValid(rows: KrRow[]): boolean {
    return rows.length > 0 && Math.abs(totalWeight(rows) - 100) < 0.01
}

/** Meta = Línea base + Incremento (pp para porcentuales). */
export function applyIncrement(kr: KrRow, value: string): void {
    kr.increment = value

    if (value !== '' && kr.baseline_value !== '') {
        kr.target_value = String(round2(Number(kr.baseline_value) + Number(value)))
    }
}

export function applyTarget(kr: KrRow, value: string): void {
    kr.target_value = value
    kr.increment = value !== '' && kr.baseline_value !== '' ? String(round2(Number(value) - Number(kr.baseline_value))) : kr.increment
}

export function applyBaseline(kr: KrRow, value: string): void {
    kr.baseline_value = value

    if (value !== '' && kr.increment !== '') {
        kr.target_value = String(round2(Number(value) + Number(kr.increment)))
    }
}

export function isAutomatic(kpi: any): boolean {
    return kpi?.automation === 'automatic'
}

/**
 * ¿La fila tiene lo indispensable para guardarse? KPI, descripción, peso y
 * meta; y una línea base resoluble: manual capturada, automática verificada
 * por Reportería, o manual AUTORIZADA (con motivo) cuando no hay dato.
 */
export function krGoalIsComplete(kr: KrRow, kpi: any): boolean {
    if (!kr.kpi_id || !kr.description.trim() || kr.weight === '' || kr.target_value === '') {
        return false
    }

    if (!isAutomatic(kpi)) {
        return kr.baseline_value !== ''
    }

    if (kr.baseline_mode === 'manual_authorized') {
        return kr.baseline_value !== '' && kr.baseline_manual_reason.trim().length >= 10
    }

    return kr.preview?.available === true
}

export function krStructureIsComplete(rows: KrRow[]): boolean {
    return rows.length > 0 && rows.every((kr) => kr.kpi_id && kr.description.trim() && kr.weight !== '') && weightIsValid(rows)
}

export function serializeKr(kr: KrRow, kpi: any) {
    const authorized = isAutomatic(kpi) && kr.baseline_mode === 'manual_authorized'

    return {
        kpi_id: kr.kpi_id,
        description: kr.description,
        // KPI automático: la base la congela Reportería al ACTIVAR (lo consultado
        // aquí es la vista previa). Solo viaja si es manual o manual autorizada.
        baseline_value: !isAutomatic(kpi) || authorized ? (kr.baseline_value === '' ? null : Number(kr.baseline_value)) : null,
        baseline_manual_reason: authorized ? kr.baseline_manual_reason.trim() : null,
        target_value: Number(kr.target_value),
        weight: Number(kr.weight),
    }
}

export function initialsOf(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '··'
}

// Paleta estable por persona para los avatares (sólo decorativo).
const AVATAR_TONES = [
    'from-indigo-500 to-violet-500', 'from-emerald-500 to-teal-500', 'from-amber-500 to-orange-500',
    'from-sky-500 to-blue-600', 'from-rose-500 to-pink-500', 'from-fuchsia-500 to-purple-600',
]

export function avatarTone(seed: number | string): string {
    const n = String(seed).split('').reduce((acc, ch) => acc + ch.charCodeAt(0), 0)

    return AVATAR_TONES[n % AVATAR_TONES.length]
}

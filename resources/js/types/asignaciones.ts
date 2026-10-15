export type AssignmentAlias = {
    employee_id: number
    employee_name: string
    branch_name?: string | null
    branch_id?: number | null
    match_type?: string | null
}

export type Assignment = {
    id: number
    employee_id?: number | null
    branch_id?: number | null
    employee_name: string
    normalized_name?: string | null
    position?: string | null
    branch_name?: string | null
    source_name?: string | null
    source_reference?: string | null
    match_type?: 'exact' | 'normalized' | 'manual' | 'unmatched' | string | null
    match_label?: string | null
    match_explanation?: string | null
    confidence?: number | null
    was_manual_reviewed?: boolean
    ui_status: 'matched' | 'pending' | 'manual' | 'unmatched'
    period_label?: string | null
    updated_at?: string | null
    notes?: string | null
    needs_manual_attention?: boolean
    context?: string
    aliases?: AssignmentAlias[]
}

// Altas/bajas del periodo — vienen de `period_employee_rosters` (roster canónico,
// deduplicado por persona real, misma fuente que el Índice de Rotación de OKR),
// nunca de `Assignment` — forma más chica a propósito, no confundir con una
// asignación real.
// 18-oct-2026 — categorías de PeriodEmployeeRosterService (ver
// App\Services\Roster\RosterMovementStatus). Solo 'baja_confirmada' es una baja.
export type RosterMovementStatus =
    | 'alta_confirmada'
    | 'reingreso'
    | 'plantilla_inicial'
    | 'activo_confirmado'
    | 'cambio_sucursal'
    | 'sin_actividad_comercial'
    | 'retorno_tras_ausencia'
    | 'baja_confirmada'
    | 'posible_baja'
    | 'no_localizado'
    | 'vigencia_desconocida'
    | 'ausencia_justificada'
    | 'registro_duplicado'
    | 'contradiccion_fuentes'
    | 'sin_clasificar'

/** @deprecated nombre anterior, se conserva por compatibilidad. */
export type RosterReconciliationStatus = RosterMovementStatus

export type RosterEvidenceItem = {
    fuente: string
    detalle: string
}

export type RosterMovementItem = {
    id: number
    employee_id: number
    employee_name: string
    nombre_normalizado?: string
    branch_name?: string | null
    source?: string | null
    period_label?: string | null
    is_active?: boolean
    movement_type?: string | null
    status?: RosterMovementStatus
    status_label?: string
    reconciliation_status?: RosterMovementStatus | null
    reconciliation_note?: string | null
    evidence?: RosterEvidenceItem[]
    requires_review?: boolean
    can_review?: boolean
}

export type LaborRosterSummary = {
    calculado: boolean
    plantilla: number
    periodo_anterior: string | null
    plantilla_anterior: number
    drop_ratio: number
    mass_drop_detected: boolean
    fuentes: { ambos: number; solo_normal: number; solo_fiscal: number }
    sin_clasificar: number
}

export type LaborData = {
    roster: LaborRosterSummary
    active: RosterMovementItem[]
    movements: {
        altas: RosterMovementItem[]
        reingresos: RosterMovementItem[]
        bajas: RosterMovementItem[]
        cambios_sucursal: RosterMovementItem[]
        retornos: RosterMovementItem[]
    }
    pending: RosterMovementItem[]
    counts: {
        altas: number
        bajas_confirmadas: number
        pendientes: number
        ausencias_pendientes: number
        sin_actividad_comercial: number
    }
}

export type LaborHistoryRow = {
    period_id: number
    label: string
    calculado: boolean
    plantilla: number
    altas: number
    bajas_confirmadas: number
    pendientes: number
}

export type Branch = {
    id: number
    name: string
}

export type PeriodOption = {
    id: number
    label: string
    type?: string | null
    start_date?: string | null
    end_date?: string | null
}

export type BranchHeadcount = {
    name: string
    count: number
}

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
export type RosterMovementItem = {
    id: number
    employee_id: number
    employee_name: string
    branch_name?: string | null
    period_label?: string | null
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

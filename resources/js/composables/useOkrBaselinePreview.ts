// Consulta de línea base (GET /okr/baseline-preview) para el asistente OKR —
// por SUCURSAL o por GESTOR (corrección 14-oct-2026). Solo lectura: nunca
// guarda nada. Para un gestor jamás se usa la base de la sucursal como
// sustituto: si Reportería no tiene dato verificable, la fila queda en
// "sin dato" y el usuario decide si captura una base manual autorizada.
import type { BaselinePreview, KrRow } from '@/components/okr/wizard/okrWizard'
import { applyBaseline } from '@/components/okr/wizard/okrWizard'

type Scope = { scope_type: 'branch' | 'employee'; branch_id: number | null; employee_id?: number | null; start_date: string }

export function useOkrBaselinePreview() {
    async function consult(kr: KrRow, scope: Scope): Promise<void> {
        if (!kr.kpi_id || !scope.branch_id) {
            return
        }

        kr.loading = true

        try {
            const params = new URLSearchParams({
                kpi_id: String(kr.kpi_id), scope_type: scope.scope_type, branch_id: String(scope.branch_id), start_date: scope.start_date,
            })

            if (scope.scope_type === 'employee' && scope.employee_id) {
                params.set('employee_id', String(scope.employee_id))
            }

            const res = await fetch(`/okr/baseline-preview?${params.toString()}`, { cache: 'no-store', headers: { Accept: 'application/json' } })
            const data = (await res.json().catch(() => null)) as (BaselinePreview & { message?: string }) | null

            if (!res.ok || !data) {
                kr.preview = { available: false, value: null, period: null, error: data?.message ?? 'No se pudo consultar Reportería.' }

                return
            }

            kr.preview = data

            if (data.available && data.value !== null && kr.baseline_mode !== 'manual_authorized') {
                kr.baseline_mode = 'auto'
                applyBaseline(kr, String(data.value))
            }
        } catch {
            kr.preview = { available: false, value: null, period: null, error: 'No se pudo consultar Reportería.' }
        } finally {
            kr.loading = false
        }
    }

    return { consult }
}

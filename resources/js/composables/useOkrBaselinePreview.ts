// Consulta de línea base (GET /okr/baseline-preview) para el asistente OKR —
// por SUCURSAL o por GESTOR (corrección 14-oct-2026, autosugerencia por gestor
// 18-oct-2026). Solo lectura: nunca guarda nada. Para un gestor jamás se usa la
// base de la sucursal como sustituto: si Reportería no tiene dato verificable,
// la fila queda en "sin dato" y el usuario captura su base manual.
//
// Robustez (19-oct-2026) — la sugerencia automática SOLO se aplica cuando es
// seguro; nunca pisa un valor escrito a mano:
//  1. Nunca sobrescribe una base capturada manualmente (baseline_mode manual /
//     manual_authorized).
//  2. Ignora respuestas obsoletas: un token monotónico por fila invalida
//     cualquier consulta previa en vuelo cuando arranca una nueva (al cambiar
//     KPI, gestor, sucursal o periodo el contenedor dispara otra consulta); y
//     se revalida que el KPI de la fila no haya cambiado desde que se pidió.
//  3. Si el usuario escribe MIENTRAS se consulta Reportería, su valor manual
//     prevalece (se compara el valor del campo antes y después de la consulta).
import type { BaselinePreview, KrRow } from '@/components/okr/wizard/okrWizard'
import { applyBaseline } from '@/components/okr/wizard/okrWizard'

type Scope = { scope_type: 'branch' | 'employee'; branch_id: number | null; employee_id?: number | null; start_date: string }

export function useOkrBaselinePreview() {
    // Token monotónico por fila (client_key): solo la ÚLTIMA consulta puede
    // aplicar su resultado o apagar su spinner. Una respuesta cuya consulta ya
    // fue reemplazada por otra más nueva se descarta en silencio.
    const latestSeq = new Map<string, number>()

    async function consult(kr: KrRow, scope: Scope): Promise<void> {
        if (!kr.kpi_id || !scope.branch_id) {
            return
        }

        const seq = (latestSeq.get(kr.client_key) ?? 0) + 1
        latestSeq.set(kr.client_key, seq)

        // Contexto exacto de ESTA consulta, capturado al iniciar.
        const requestedKpiId = kr.kpi_id
        // Valor del campo al arrancar: si cambia durante la consulta, el usuario
        // escribió a mano y su valor manda sobre la sugerencia.
        const valueAtRequest = kr.baseline_value

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

            // ¿Obsoleta? Otra consulta más nueva arrancó para esta fila, o su KPI
            // cambió desde que se pidió este dato. En cualquier caso, descartar:
            // no tocar preview, ni el valor, ni el spinner (lo gobierna la vigente).
            if (latestSeq.get(kr.client_key) !== seq || kr.kpi_id !== requestedKpiId) {
                return
            }

            if (!res.ok || !data) {
                kr.preview = { available: false, value: null, period: null, error: data?.message ?? 'No se pudo consultar Reportería.' }

                return
            }

            kr.preview = data

            // Aplicación SEGURA de la sugerencia automática:
            //  · hay valor disponible,
            //  · el usuario NO capturó una base manual,
            //  · y el campo NO se editó durante la consulta.
            // Para adoptar la sugerencia sobre un valor manual el usuario usa el
            // botón explícito "Usar base sugerida" (nunca un reemplazo silencioso).
            const userTypedDuringRequest = kr.baseline_value !== valueAtRequest
            const hasManualValue = kr.baseline_mode === 'manual' || kr.baseline_mode === 'manual_authorized'

            if (data.available && data.value !== null && !hasManualValue && !userTypedDuringRequest) {
                kr.baseline_mode = 'auto'
                applyBaseline(kr, String(data.value)) // recalcula la meta = base + incremento
            }
        } catch {
            if (latestSeq.get(kr.client_key) === seq) {
                kr.preview = { available: false, value: null, period: null, error: 'No se pudo consultar Reportería.' }
            }
        } finally {
            // Solo la consulta vigente apaga el spinner: una obsoleta jamás debe
            // apagarlo si todavía hay otra más nueva en vuelo.
            if (latestSeq.get(kr.client_key) === seq) {
                kr.loading = false
            }
        }
    }

    return { consult }
}

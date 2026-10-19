// Consulta de línea base (GET /okr/baseline-preview) para el asistente OKR —
// por SUCURSAL o por GESTOR (corrección 14-oct-2026, autosugerencia por gestor
// 18-oct-2026). Solo lectura: nunca guarda nada. Para un gestor jamás se usa la
// base de la sucursal como sustituto: si Reportería no tiene dato verificable,
// la fila queda en "sin dato" y el usuario captura su base manual.
//
// Robustez (19-oct-2026) — la sugerencia automática SOLO se aplica cuando es
// seguro; nunca pisa un valor escrito a mano ni muestra/guarda datos de otro
// gestor o periodo:
//  1. Nunca sobrescribe una base capturada manualmente (baseline_mode manual /
//     manual_authorized).
//  2. Cada fila lleva un token monotónico { seq } y una FIRMA de contexto
//     (KPI + gestor + sucursal + periodo). Al cambiar cualquiera de esos datos
//     el contenedor llama invalidate(), que sube el seq y reescribe la firma AUN
//     si una petición sigue en vuelo (kr.loading === true). Una respuesta solo
//     se aplica si su seq sigue siendo el vigente Y su firma coincide con la
//     firma vigente Y el KPI de la fila no cambió — nunca solo por el KPI/seq.
//  3. Si el usuario escribe MIENTRAS se consulta Reportería, su valor manual
//     prevalece (se compara el valor del campo antes y después de la consulta).
import type { BaselinePreview, KrRow } from '@/components/okr/wizard/okrWizard'
import { applyBaseline } from '@/components/okr/wizard/okrWizard'

type Scope = { scope_type: 'branch' | 'employee'; branch_id: number | null; employee_id?: number | null; start_date: string }
type RowState = { seq: number; sig: string }

// Firma del contexto COMPLETO de una consulta: si cualquier dato cambia, una
// respuesta en vuelo del contexto anterior deja de coincidir y se descarta.
function contextSignature(kr: KrRow, scope: Scope): string {
    return [
        kr.kpi_id ?? '',
        scope.scope_type,
        scope.scope_type === 'employee' ? (scope.employee_id ?? '') : '',
        scope.branch_id ?? '',
        scope.start_date,
    ].join('|')
}

export function useOkrBaselinePreview() {
    // Estado vigente por fila (client_key): el token monotónico y la firma de
    // contexto de la ÚLTIMA intención (consulta o invalidación). Solo una
    // respuesta que coincida con AMBOS puede aplicar su resultado o apagar su
    // spinner.
    const latest = new Map<string, RowState>()

    function bump(clientKey: string, sig: string): number {
        const seq = (latest.get(clientKey)?.seq ?? 0) + 1
        latest.set(clientKey, { seq, sig })

        return seq
    }

    // Invalida de inmediato cualquier consulta en vuelo de esta fila y fija la
    // nueva firma de contexto, AUNQUE kr.loading sea verdadero. El contenedor la
    // llama al cambiar KPI, gestor, sucursal o periodo: así la respuesta anterior
    // (de otro gestor/periodo) ya nunca coincide y se descarta sin tocar nada.
    function invalidate(kr: KrRow, scope: Scope): void {
        bump(kr.client_key, contextSignature(kr, scope))
        // Abandona el spinner de la petición en vuelo: su respuesta ya se
        // descartará. Si enseguida arranca una consulta nueva, ésta vuelve a
        // encender kr.loading de forma síncrona; si no (fila manual), no queda
        // un spinner pegado.
        kr.loading = false
    }

    async function consult(kr: KrRow, scope: Scope): Promise<void> {
        if (!kr.kpi_id || !scope.branch_id) {
            return
        }

        // Contexto exacto de ESTA consulta, capturado al iniciar.
        const requestedSig = contextSignature(kr, scope)
        const requestedKpiId = kr.kpi_id
        const seq = bump(kr.client_key, requestedSig)
        // Valor del campo al arrancar: si cambia durante la consulta, el usuario
        // escribió a mano y su valor manda sobre la sugerencia.
        const valueAtRequest = kr.baseline_value

        kr.loading = true

        // ¿La respuesta recién llegada sigue siendo la vigente para ESTA fila?
        // Debe coincidir el token, la firma de contexto completa Y el KPI vivo.
        const isCurrent = (): boolean => {
            const state = latest.get(kr.client_key)

            return state !== undefined && state.seq === seq && state.sig === requestedSig && kr.kpi_id === requestedKpiId
        }

        try {
            const params = new URLSearchParams({
                kpi_id: String(kr.kpi_id), scope_type: scope.scope_type, branch_id: String(scope.branch_id), start_date: scope.start_date,
            })

            if (scope.scope_type === 'employee' && scope.employee_id) {
                params.set('employee_id', String(scope.employee_id))
            }

            const res = await fetch(`/okr/baseline-preview?${params.toString()}`, { cache: 'no-store', headers: { Accept: 'application/json' } })
            const data = (await res.json().catch(() => null)) as (BaselinePreview & { message?: string }) | null

            // Obsoleta: cambió el contexto (gestor/sucursal/periodo/KPI) o arrancó
            // otra consulta. No tocar preview, ni el valor, ni el spinner — jamás
            // mostrar/guardar un resultado de otro gestor o periodo.
            if (!isCurrent()) {
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
            if (isCurrent()) {
                kr.preview = { available: false, value: null, period: null, error: 'No se pudo consultar Reportería.' }
            }
        } finally {
            // Solo la consulta vigente apaga el spinner: una obsoleta jamás debe
            // apagarlo si todavía hay otra más nueva en vuelo.
            if (isCurrent()) {
                kr.loading = false
            }
        }
    }

    return { consult, invalidate }
}

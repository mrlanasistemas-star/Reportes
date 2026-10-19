import { afterEach, describe, expect, it, vi } from 'vitest'
import { newKrRow } from '../components/okr/wizard/okrWizard'
import { useOkrBaselinePreview } from '../composables/useOkrBaselinePreview'

// Robustez de la autosugerencia de línea base del gestor (19-oct-2026): la
// respuesta automática nunca debe pisar un valor escrito a mano, ni aplicar
// una respuesta obsoleta cuando cambió KPI/gestor/sucursal/periodo.

const scope = { scope_type: 'employee' as const, branch_id: 2, employee_id: 21, start_date: '2026-10-01' }

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function stubFetchResolving(body: unknown, status = 200) {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(body, status)))
}

/** fetch que no resuelve hasta que se llama el `resolve` devuelto (consulta "en vuelo"). */
function deferredFetch() {
    let resolve!: (r: Response) => void
    const pending = new Promise<Response>((r) => {
        resolve = r
    })
    vi.stubGlobal('fetch', vi.fn(() => pending))

    return { resolve }
}

afterEach(() => vi.unstubAllGlobals())

describe('useOkrBaselinePreview', () => {
    it('autollena la base cuando el campo está vacío y no es manual', async () => {
        stubFetchResolving({ available: true, value: 95000, period: { id: 14, label: 'Octubre 2026' }, scope_type: 'employee' })
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        await consult(kr, scope)

        expect(kr.baseline_value).toBe('95000')
        expect(kr.baseline_mode).toBe('auto')
        expect(kr.preview?.available).toBe(true)
    })

    it('recalcula la meta = base + incremento al autollenar (req. 6)', async () => {
        stubFetchResolving({ available: true, value: 100000, period: null })
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3, increment: '30000' })

        await consult(kr, scope)

        expect(kr.baseline_value).toBe('100000')
        expect(kr.target_value).toBe('130000')
    })

    it('NUNCA sobrescribe una base ya capturada manualmente (req. 1)', async () => {
        stubFetchResolving({ available: true, value: 95000, period: null })
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3, baseline_value: '12345', baseline_mode: 'manual' })

        await consult(kr, scope)

        expect(kr.baseline_value).toBe('12345')
        expect(kr.baseline_mode).toBe('manual')
        // Pero sí refresca la vista previa para poder ofrecer "Usar base sugerida".
        expect(kr.preview?.available).toBe(true)
    })

    it('si el usuario escribe MIENTRAS se consulta, prevalece su valor manual (req. 3)', async () => {
        const { resolve } = deferredFetch()
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p = consult(kr, scope)
        // El usuario captura su propio valor con la consulta aún en vuelo.
        kr.baseline_mode = 'manual'
        kr.baseline_value = '777'
        resolve(jsonResponse({ available: true, value: 95000, period: null }))
        await p

        expect(kr.baseline_value).toBe('777')
        expect(kr.baseline_mode).toBe('manual')
    })

    it('ignora una respuesta OBSOLETA cuando arrancó otra consulta más nueva (req. 2)', async () => {
        let resolveFirst!: (r: Response) => void
        const first = new Promise<Response>((r) => {
            resolveFirst = r
        })
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => first) // seq 1 — lenta
            .mockImplementationOnce(async () => jsonResponse({ available: true, value: 50000, period: null })) // seq 2 — rápida
        vi.stubGlobal('fetch', fetchMock)

        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p1 = consult(kr, scope) // seq 1
        const p2 = consult(kr, scope) // seq 2 → invalida la 1
        await p2
        resolveFirst(jsonResponse({ available: true, value: 95000, period: null })) // llega tarde la obsoleta
        await p1

        expect(kr.baseline_value).toBe('50000') // la vigente, nunca la obsoleta
    })

    it('ignora la respuesta si el KPI de la fila cambió durante la consulta (req. 2)', async () => {
        const { resolve } = deferredFetch()
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p = consult(kr, scope)
        kr.kpi_id = 4 // el usuario cambia el KPI mientras consulta
        resolve(jsonResponse({ available: true, value: 95000, period: null }))
        await p

        expect(kr.baseline_value).toBe('') // no se aplicó la base del KPI viejo
    })

    it('cuando no hay dato deja la base intacta y expone el mensaje claro', async () => {
        stubFetchResolving({ available: false, value: null, period: null, scope_type: 'employee', message: 'No se encontró base automática para este gestor. Captúrala manualmente.' })
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3, baseline_value: '' })

        await consult(kr, scope)

        expect(kr.baseline_value).toBe('')
        expect(kr.preview?.available).toBe(false)
        expect((kr.preview as { message?: string } | null)?.message).toContain('No se encontró base automática')
    })

    it('un error HTTP deja la base intacta y muestra un mensaje legible', async () => {
        stubFetchResolving({ message: 'Reportería no disponible.' }, 500)
        const { consult } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3, baseline_value: '5000', baseline_mode: 'manual' })

        await consult(kr, scope)

        expect(kr.baseline_value).toBe('5000') // nunca se borra lo capturado
        expect(kr.preview?.available).toBe(false)
    })
})

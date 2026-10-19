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

/**
 * Cola de fetch diferidos: cada llamada a fetch encola un resolver. Permite
 * resolver las respuestas en CUALQUIER orden (out-of-order) para simular
 * respuestas HTTP que llegan desordenadas tras cambios rápidos de contexto.
 */
function deferredQueue() {
    const resolvers: ((r: Response) => void)[] = []
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((res) => {
        resolvers.push(res)
    })))

    return {
        resolve(index: number, body: unknown, status = 200) {
            resolvers[index](jsonResponse(body, status))
        },
    }
}

const emp = (employee_id: number, over: Partial<typeof scope> = {}) => ({ ...scope, employee_id, ...over })

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

    // --- Cambios rápidos de contexto con respuestas HTTP fuera de orden (req. 6) ---
    // Simulan lo que hace el contenedor (OkrGoalsTable) al cambiar el contexto:
    // invalidate(fila, nuevoScope) + consult(fila, nuevoScope), AUNQUE la anterior
    // siga en vuelo. En todos, solo el gestor/sucursal/periodo VIGENTE puede
    // aplicarse o mostrarse en el preview.

    it('cambio rápido de GESTOR (respuesta nueva antes que la vieja): solo aplica el gestor vigente', async () => {
        const q = deferredQueue()
        const { consult, invalidate } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p21 = consult(kr, emp(21)) // fetch idx 0
        invalidate(kr, emp(22)) // el contenedor invalida al cambiar de gestor…
        const p22 = consult(kr, emp(22)) // …y relanza. fetch idx 1

        q.resolve(1, { available: true, value: 22000, period: { id: 14, label: 'Octubre 2026' } }) // llega primero la del 22
        await p22
        q.resolve(0, { available: true, value: 21000, period: { id: 14, label: 'Octubre 2026' } }) // luego la obsoleta del 21
        await p21

        expect(kr.baseline_value).toBe('22000') // solo el gestor vigente
        expect(kr.preview?.value).toBe(22000) // preview nunca contaminado por el 21
        expect(kr.loading).toBe(false)
    })

    it('cambio rápido de GESTOR (la vieja llega primero): la del gestor anterior se descarta', async () => {
        const q = deferredQueue()
        const { consult, invalidate } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p21 = consult(kr, emp(21)) // idx 0
        invalidate(kr, emp(22))
        const p22 = consult(kr, emp(22)) // idx 1

        q.resolve(0, { available: true, value: 21000, period: null }) // obsoleta llega primero
        await p21
        expect(kr.baseline_value).toBe('') // nunca aplicó la del 21
        q.resolve(1, { available: true, value: 22000, period: null })
        await p22

        expect(kr.baseline_value).toBe('22000')
        expect(kr.preview?.value).toBe(22000)
    })

    it('cambio rápido de SUCURSAL con respuestas fuera de orden: solo aplica la sucursal vigente', async () => {
        const q = deferredQueue()
        const { consult, invalidate } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const pA = consult(kr, emp(21, { branch_id: 2 })) // idx 0
        invalidate(kr, emp(21, { branch_id: 5 }))
        const pB = consult(kr, emp(21, { branch_id: 5 })) // idx 1

        q.resolve(1, { available: true, value: 5000, period: null }) // sucursal 5 (vigente)
        await pB
        q.resolve(0, { available: true, value: 2000, period: null }) // sucursal 2 (obsoleta)
        await pA

        expect(kr.baseline_value).toBe('5000')
        expect(kr.preview?.value).toBe(5000)
    })

    it('cambio rápido de PERIODO con respuestas fuera de orden: solo aplica el periodo vigente', async () => {
        const q = deferredQueue()
        const { consult, invalidate } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const pOld = consult(kr, emp(21, { start_date: '2026-09-01' })) // idx 0
        invalidate(kr, emp(21, { start_date: '2026-10-01' }))
        const pNew = consult(kr, emp(21, { start_date: '2026-10-01' })) // idx 1

        q.resolve(0, { available: true, value: 900, period: { id: 13, label: 'Septiembre 2026' } }) // obsoleto primero
        await pOld
        expect(kr.baseline_value).toBe('')
        q.resolve(1, { available: true, value: 1000, period: { id: 14, label: 'Octubre 2026' } })
        await pNew

        expect(kr.baseline_value).toBe('1000')
        expect(kr.preview?.period?.label).toBe('Octubre 2026') // nunca el periodo anterior
    })

    it('cambio de gestor con base MANUAL en curso: conserva el valor, no muestra el preview anterior ni deja spinner pegado', async () => {
        const q = deferredQueue()
        const { consult, invalidate } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p21 = consult(kr, emp(21)) // idx 0, en vuelo
        // El usuario captura su propia base para el gestor actual.
        kr.baseline_mode = 'manual'
        kr.baseline_value = '999'
        // Cambia de gestor: el contenedor invalida (la fila manual NO se re-consulta).
        invalidate(kr, emp(22))

        q.resolve(0, { available: true, value: 21000, period: { id: 14, label: 'Octubre 2026' } }) // respuesta del gestor 21
        await p21

        expect(kr.baseline_value).toBe('999') // base manual conservada
        expect(kr.preview).toBeNull() // jamás se mostró el dato del gestor 21
        expect(kr.loading).toBe(false) // spinner no queda pegado
    })

    it('invalidate deja la fila lista para una nueva sugerencia vigente', async () => {
        const q = deferredQueue()
        const { consult, invalidate } = useOkrBaselinePreview()
        const kr = newKrRow({ kpi_id: 3 })

        const p21 = consult(kr, emp(21)) // idx 0
        invalidate(kr, emp(22))
        expect(kr.loading).toBe(false) // invalidate abandona el spinner de inmediato
        const p22 = consult(kr, emp(22)) // idx 1
        expect(kr.loading).toBe(true) // la nueva consulta lo vuelve a encender

        q.resolve(0, { available: true, value: 21000, period: null }) // obsoleta
        await p21
        q.resolve(1, { available: true, value: 22000, period: null })
        await p22

        expect(kr.baseline_value).toBe('22000')
        expect(kr.loading).toBe(false)
    })
})

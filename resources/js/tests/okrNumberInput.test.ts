// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, nextTick, ref } from 'vue'
import type { App } from 'vue'
import OkrNumberInput from '../components/okr/OkrNumberInput.vue'
import OkrGoalsTable from '../components/okr/wizard/OkrGoalsTable.vue'
import { newKrRow, roundTo } from '../components/okr/wizard/okrWizard'
import type { KrRow } from '../components/okr/wizard/okrWizard'

// Campos numéricos del asistente OKR montados de verdad (19-oct-2026). El
// defecto de "escribir dos veces" (Input de shadcn con :value + @input) solo se
// reproduce con el microtask que Chrome corre entre listeners: eso lo cubre
// tests/browser/okr-inputs/run.mjs. Aquí se fija el contrato del enlace
// modelValue ↔ update:modelValue y el recálculo de la tabla de metas.

let app: App | null = null

afterEach(() => {
    app?.unmount()
    app = null
    document.body.innerHTML = ''
    vi.unstubAllGlobals()
})

function mount(component: any) {
    const el = document.createElement('div')
    document.body.appendChild(el)
    app = createApp(component)
    app.mount(el)

    return el
}

async function flush() {
    for (let i = 0; i < 4; i++) {
        await nextTick()
        await Promise.resolve()
    }
}

/** Escribe como el navegador: cambia el texto y dispara "input". */
async function type(input: HTMLInputElement, text: string) {
    input.focus()
    input.value = text
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await flush()
}

/** Campo controlado por un padre que aplica `controller` (como hace la tabla de metas). */
function controlled(initial: string, controller?: (v: string, model: { value: string }) => void) {
    const model = ref(initial)
    const emitted: string[] = []
    const el = mount(defineComponent({
        setup: () => () => h(OkrNumberInput, {
            modelValue: model.value,
            'onUpdate:modelValue': (v: string) => {
                emitted.push(v)

                if (controller) {
                    controller(v, model)
                } else {
                    model.value = v
                }
            },
        }),
    }))

    return { model, emitted, input: el.querySelector('input') as HTMLInputElement }
}

describe('OkrNumberInput (Input de shadcn con un solo enlace)', () => {
    it('muestra al montar el valor que ya existe en el modelo', () => {
        const { input } = controlled('30000')

        expect(input.type).toBe('number')
        expect(input.value).toBe('30000')
    })

    it('emite el número desde la primera captura', async () => {
        const { input, model, emitted } = controlled('')

        await type(input, '10000')

        expect(emitted).toEqual(['10000'])
        expect(model.value).toBe('10000')
        expect(input.value).toBe('10000')
    })

    it('no reescribe lo que se está tecleando ("30000.50" no pasa a "30000.5")', async () => {
        const { input, model } = controlled('')

        await type(input, '30000.5')
        await type(input, '30000.50')

        expect(model.value).toBe('30000.5')
        expect(input.value).toBe('30000.50')
    })

    it('refleja un cambio externo (meta recalculada / base sugerida)', async () => {
        const { input, model } = controlled('40000')

        model.value = '45000'
        await flush()

        expect(input.value).toBe('45000')
    })

    it('acepta 0, negativos y decimales', async () => {
        const { input, model } = controlled('')

        await type(input, '0')
        expect(model.value).toBe('0')
        await type(input, '-5000')
        expect(model.value).toBe('-5000')
        await type(input, '12500.75')
        expect(model.value).toBe('12500.75')
    })

    it('vacío o texto inválido se emite como "" (nunca NaN ni Infinity)', async () => {
        const { input, model } = controlled('5')

        await type(input, '')
        expect(model.value).toBe('')
        await type(input, '1e400')
        expect(model.value).toBe('')
    })

    it('si el controlador ajusta el valor (tope), el campo muestra el vigente', async () => {
        const { input, model } = controlled('24.5', (v, m) => {
            m.value = String(Math.min(Number(v), 24.5))
        })

        await type(input, '90')

        expect(model.value).toBe('24.5')
        expect(input.value).toBe('24.5')
    })

    it('al salir del campo redondea a 2 decimales', async () => {
        const { input, model } = controlled('')

        await type(input, '10.555')
        input.dispatchEvent(new FocusEvent('blur'))
        await flush()

        expect(model.value).toBe('10.56')
        expect(input.value).toBe('10.56')
    })
})

describe('roundTo', () => {
    it('evita el error binario de Math.round(n * 100) / 100', () => {
        expect(roundTo(1.005, 2)).toBe(1.01)
        expect(roundTo(30000.005, 2)).toBe(30000.01)
        expect(roundTo(30000.1 + 0.2, 2)).toBe(30000.3)
        expect(roundTo(-2.675, 2)).toBe(-2.68)
        expect(roundTo(-0.001, 2)).toBe(0)
        expect(roundTo(98765432109.25, 2)).toBe(98765432109.25)
    })
})

describe('OkrGoalsTable montada', () => {
    const kpis = [
        { id: 1, name: 'Colocación', unit: 'currency', type: 'cumulative', direction: 'increase', automation: 'automatic' },
        { id: 3, name: 'Clientes nuevos', unit: 'count', type: 'cumulative', direction: 'increase', automation: 'manual' },
    ]

    function mountTable(rows: KrRow[], scope: any) {
        const model = ref(rows)
        const el = mount(defineComponent({
            setup: () => () => h(OkrGoalsTable, {
                rows: model.value, 'onUpdate:rows': (v: KrRow[]) => (model.value = v), kpis, scope, scopeLabel: 'de prueba',
            }),
        }))
        const cell = (col: number) => el.querySelector(`tbody tr td:nth-child(${col}) input`) as HTMLInputElement

        return { model, base: () => cell(2), inc: () => cell(3), goal: () => cell(4) }
    }

    const branchScope = { scope_type: 'branch', branch_id: 2, start_date: '2026-10-01' }

    it('base 30000 + incremento 10000 → meta 40000; base 35000 → 45000; meta 50000 → incremento 15000', async () => {
        const t = mountTable([newKrRow({ kpi_id: 3, description: 'x', weight: '100', baseline_value: '30000' })], branchScope)

        expect(t.base().value).toBe('30000')
        await type(t.inc(), '10000')
        expect(t.goal().value).toBe('40000')
        expect(t.model.value[0].target_value).toBe('40000')

        await type(t.base(), '35000')
        expect(t.goal().value).toBe('45000')

        await type(t.goal(), '50000')
        expect(t.inc().value).toBe('15000')
        expect(t.model.value[0].increment).toBe('15000')
    })

    it('reabrir: base, incremento y meta guardados se ven en los campos', () => {
        const t = mountTable([newKrRow({ kpi_id: 3, description: 'x', weight: '100', baseline_value: '35000', increment: '10000', target_value: '45000' })], branchScope)

        expect([t.base().value, t.inc().value, t.goal().value]).toEqual(['35000', '10000', '45000'])
    })

    it('gestor: la respuesta tardía de Reportería no cambia la base que se está escribiendo', async () => {
        let resolve!: (r: Response) => void
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((r) => {
            resolve = r
        })))
        const t = mountTable([newKrRow({ kpi_id: 1, description: 'x', weight: '100' })], { scope_type: 'employee', branch_id: 2, employee_id: 21, start_date: '2026-10-01' })
        await flush()
        expect(t.model.value[0].loading).toBe(true)

        await type(t.base(), '27500')
        resolve(new Response(JSON.stringify({ available: true, value: 30000, period: { id: 21, label: 'Sep 2026' } }), { status: 200 }))
        await flush()

        expect(t.base().value).toBe('27500')
        expect(t.model.value[0].baseline_value).toBe('27500')
        expect(t.model.value[0].baseline_mode).toBe('manual')
        expect(document.body.textContent).toContain('Usar base sugerida')
    })
})

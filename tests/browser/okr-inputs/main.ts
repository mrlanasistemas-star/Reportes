// Banco de pruebas de navegador (19-oct-2026) para los campos numéricos del
// asistente OKR: monta los componentes REALES (OkrGoalsTable, OkrKrEditor,
// OkrCompleteKrDialog con el Input de shadcn) con Reportería simulada, para que
// run.mjs teclee en Chrome como lo haría el usuario.
import './harness.css'
import { router } from '@inertiajs/vue3'
import { createApp } from 'vue'
import Harness from './Harness.vue'

type Pending = { url: string; resolve: (r: Response) => void }
const pending: Pending[] = []
const saved: any[] = []

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

// Reportería simulada: cada consulta queda EN VUELO hasta que la prueba la responde.
window.fetch = ((url: string, init?: RequestInit) => {
    if (url.includes('/okr/baseline-preview')) {
        return new Promise<Response>((resolve) => pending.push({ url, resolve }))
    }

    if (url.includes('/key-results/complete')) {
        saved.push(JSON.parse(String(init?.body ?? '{}')))

        return Promise.resolve(json({ ok: true }))
    }

    return Promise.resolve(json({}, 404))
}) as typeof fetch

;(window as any).__okr = {
    pending: () => pending.map((p) => p.url),
    respond(index: number, value: number | null) {
        const [p] = pending.splice(index, 1)
        p.resolve(json(value === null
            ? { available: false, value: null, period: null, message: 'No se encontró base automática para este gestor.' }
            : { available: true, value, period: { id: 21, label: 'Sep 2026' } }))
    },
    saved: () => saved,
}

// Sin página Inertia: guardar no debe intentar recargar.
router.reload = () => {}

createApp(Harness).mount('#app')

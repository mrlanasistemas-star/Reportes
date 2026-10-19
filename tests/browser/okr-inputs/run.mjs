// Pruebas de navegador REALES de los campos numéricos del asistente OKR
// (19-oct-2026). Levanta Vite con el banco de pruebas (index.html + Harness.vue,
// componentes reales con el Input de shadcn y Reportería simulada) y teclea en
// Chrome (puppeteer) los escenarios A–F: un solo clic, escritura rápida,
// Backspace, Tab, respuestas HTTP tardías y fuera de orden, guardar y reabrir.
//
// Uso:  node tests/browser/okr-inputs/run.mjs
import { existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import puppeteer from 'puppeteer'
import { createServer } from 'vite'

const here = path.dirname(fileURLToPath(import.meta.url))
const projectRoot = path.resolve(here, '../../..')

const server = await createServer({
    configFile: false,
    root: here,
    logLevel: 'error',
    plugins: [vue(), tailwindcss()],
    resolve: { alias: { '@': path.join(projectRoot, 'resources/js') } },
    server: { host: '127.0.0.1', port: 5199, strictPort: false, fs: { allow: [projectRoot] } },
})
await server.listen()
const url = server.resolvedUrls.local[0]

// Chrome del sistema si puppeteer no descargó el suyo (CHROME_PATH lo fuerza).
const systemChrome = ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome'].find((p) => existsSync(p))
const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'], executablePath: process.env.CHROME_PATH ?? systemChrome })
const page = await browser.newPage()
await page.setViewport({ width: 1400, height: 2200 })
const pageErrors = []
page.on('pageerror', (e) => pageErrors.push(String(e)))

const results = []
let scenario = ''

function check(name, actual, expected) {
    const pass = JSON.stringify(actual) === JSON.stringify(expected)
    results.push({ scenario, name, pass, actual, expected })
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const settle = () => sleep(60)

// Celdas de la tabla de metas: 2 = línea base, 3 = incremento, 4 = meta final.
const cell = (section, row, col) => `#${section} table tbody tr:nth-child(${row}) td:nth-child(${col}) input`
const base = (s, r) => cell(s, r, 2)
const inc = (s, r) => cell(s, r, 3)
const goal = (s, r) => cell(s, r, 4)

const dom = (sel) => page.$eval(sel, (el) => el.value)
const focused = (sel) => page.$eval(sel, (el) => document.activeElement === el)
const model = (list, row, field) => page.evaluate((l, r, f) => window.__state[l].value[r - 1][f], list, row, field)
const pending = () => page.evaluate(() => window.__okr.pending())
const respond = (i, v) => page.evaluate((i, v) => window.__okr.respond(i, v), i, v)

/** Un solo clic y escritura con teclado real. */
async function typeIn(sel, text, delay = 0) {
    await page.$eval(sel, (el) => el.scrollIntoView({ block: 'center' }))
    await page.click(sel)
    await page.keyboard.type(text, { delay })
    await settle()
}

/** Seleccionar todo + Backspace + escribir (reemplazo como lo haría el usuario). */
async function replaceIn(sel, text, delay = 0) {
    await page.$eval(sel, (el) => el.scrollIntoView({ block: 'center' }))
    await page.click(sel)
    await page.keyboard.down('Control')
    await page.keyboard.press('KeyA')
    await page.keyboard.up('Control')
    await page.keyboard.press('Backspace')
    await page.keyboard.type(text, { delay })
    await settle()
}

async function clickText(text, root = 'body') {
    await page.evaluate((t, r) => {
        const btn = [...document.querySelector(r).querySelectorAll('button')].find((b) => b.textContent?.trim().includes(t) && !b.disabled)

        if (!btn) {
throw new Error(`Botón no encontrado: ${t}`)
}

        btn.click()
    }, text, root)
    await settle()
}

try {
    await page.goto(url, { waitUntil: 'networkidle0' })
    await settle()

    // ── Montaje: un valor que ya existe en el modelo debe verse en el campo ──
    scenario = 'Montaje'
    check('base precargada (sucursal) visible al montar', await dom(base('branch', 1)), '30000')

    // ── A: OKR general ──
    scenario = 'A · General'
    await typeIn(inc('branch', 1), '10000')
    check('incremento visible al primer intento', await dom(inc('branch', 1)), '10000')
    check('incremento en el modelo', await model('branchRows', 1, 'increment'), '10000')
    check('meta inmediata (DOM)', await dom(goal('branch', 1)), '40000')
    check('meta inmediata (modelo)', await model('branchRows', 1, 'target_value'), '40000')
    await replaceIn(base('branch', 1), '35000')
    check('base 35000 → meta 45000', await dom(goal('branch', 1)), '45000')
    await replaceIn(goal('branch', 1), '50000')
    check('meta 50000 → incremento 15000 (DOM)', await dom(inc('branch', 1)), '15000')
    check('meta 50000 → incremento 15000 (modelo)', await model('branchRows', 1, 'increment'), '15000')
    check('meta conserva lo escrito', await dom(goal('branch', 1)), '50000')

    // ── B: OKR individual (tabla del gestor) ──
    scenario = 'B · Individual'
    check('consulta automática en vuelo (solo KPI automático)', (await pending()).length, 1)
    await respond(0, 30000)
    await settle()
    check('base sugerida visible', await dom(base('employee', 1)), '30000')
    await replaceIn(base('employee', 1), '32000')
    await typeIn(inc('employee', 1), '10000')
    check('incremento al primer intento', await dom(inc('employee', 1)), '10000')
    check('meta = 42000', await dom(goal('employee', 1)), '42000')
    await page.keyboard.press('Tab')
    await settle()
    check('tras Tab: incremento intacto', await dom(inc('employee', 1)), '10000')
    check('tras Tab: base intacta', await dom(base('employee', 1)), '32000')
    await page.click('#emp-toggle')
    await settle()
    await page.click('#emp-toggle')
    await settle()
    check('reabrir: base', await dom(base('employee', 1)), '32000')
    check('reabrir: incremento', await dom(inc('employee', 1)), '10000')
    check('reabrir: meta', await dom(goal('employee', 1)), '42000')
    check('reabrir: no reconsulta una base manual', (await pending()).length, 0)

    // ── B2: Editar metas → guardar → reabrir (OkrCompleteKrDialog real) ──
    scenario = 'B2 · Guardar y reabrir'
    await page.click('#dialog-open')
    await sleep(300)
    await clickText('Siguiente')
    await sleep(200)
    const dlg = '[role="dialog"]'
    const dCell = (col) => `${dlg} table tbody tr:nth-child(1) td:nth-child(${col}) input`
    check('existente: base visible al abrir', await dom(dCell(2)), '20000')
    check('existente: incremento visible al abrir', await dom(dCell(3)), '5000')
    check('existente: meta visible al abrir', await dom(dCell(4)), '25000')
    await clickText('Volver a consultar', dlg)
    await respond(0, 30000)
    await settle()
    await clickText('Usar base sugerida', dlg)
    check('usar base sugerida → base', await dom(dCell(2)), '30000')
    check('usar base sugerida → meta recalculada', await dom(dCell(4)), '35000')
    await replaceIn(dCell(3), '10000')
    check('incremento 10000 → meta 40000', await dom(dCell(4)), '40000')
    await replaceIn(dCell(2), '35000')
    check('base manual 35000 → meta 45000', await dom(dCell(4)), '45000')
    await clickText('Guardar Key Results')
    await sleep(400)
    const saved = await page.evaluate(() => window.__okr.saved().at(-1)?.key_results?.[0])
    check('payload guardado: base', saved?.baseline_value, 35000)
    check('payload guardado: meta', saved?.target_value, 45000)
    await page.click('#dialog-reopen')
    await sleep(300)
    await clickText('Siguiente')
    await sleep(200)
    check('reabierto: base', await dom(dCell(2)), '35000')
    check('reabierto: incremento', await dom(dCell(3)), '10000')
    check('reabierto: meta', await dom(dCell(4)), '45000')
    await page.keyboard.press('Escape')
    await sleep(300)

    // ── C: respuesta HTTP tardía mientras se escribe la base ──
    scenario = 'C · HTTP tardío'
    await page.click('#emp-reset')
    await sleep(150)
    check('dos consultas en vuelo', (await pending()).length, 2)
    await typeIn(base('employee', 1), '27500', 40)
    await respond(0, 30000)
    await settle()
    check('el valor escrito permanece (DOM)', await dom(base('employee', 1)), '27500')
    check('el valor escrito permanece (modelo)', await model('empRows', 1, 'baseline_value'), '27500')
    check('el campo conserva el foco', await focused(base('employee', 1)), true)
    // Escribir A MEDIAS (sin terminar) mientras llega la respuesta.
    await page.click(base('employee', 2))
    await page.keyboard.type('12', { delay: 30 })
    await respond(0, 99999)
    await page.keyboard.type('50', { delay: 30 })
    await settle()
    check('escritura interrumpida por la respuesta: 1250', await dom(base('employee', 2)), '1250')
    check('modelo 1250', await model('empRows', 2, 'baseline_value'), '1250')

    // ── D: escritura rápida, borrar, decimales, Backspace ──
    scenario = 'D · Escritura rápida'
    await page.click('#emp-reset')
    await sleep(150)
    await respond(0, null)
    await respond(0, null)
    await settle()
    const f = inc('employee', 2)
    await typeIn(f, '30000')
    check('30000 rápido', await dom(f), '30000')
    await replaceIn(f, '12500.75')
    check('12500.75', await dom(f), '12500.75')
    check('modelo 12500.75', await model('empRows', 2, 'increment'), '12500.75')
    await page.keyboard.press('Backspace')
    await settle()
    check('Backspace → 12500.7', await dom(f), '12500.7')
    check('modelo 12500.7', await model('empRows', 2, 'increment'), '12500.7')
    check('no pierde el foco', await focused(f), true)
    await replaceIn(f, '30000.50')
    check('cero decimal final se conserva al escribir', await dom(f), '30000.50')
    await replaceIn(f, '12500.')
    await page.keyboard.type('5')
    await settle()
    check('decimal en curso "12500." + 5', await dom(f), '12500.5')
    await replaceIn(f, '-5000')
    check('negativo -5000', await dom(f), '-5000')
    check('modelo -5000', await model('empRows', 2, 'increment'), '-5000')
    await replaceIn(f, '0')
    check('cero', await dom(f), '0')
    check('modelo 0', await model('empRows', 2, 'increment'), '0')
    await replaceIn(f, '1')
    check('uno', await dom(f), '1')
    await replaceIn(f, '98765432109.25')
    check('cifra grande', await dom(f), '98765432109.25')
    await replaceIn(f, '10.555')
    await page.keyboard.press('Tab')
    await settle()
    check('al salir: se normaliza a 2 decimales (modelo)', await model('empRows', 2, 'increment'), '10.56')
    check('al salir: se normaliza a 2 decimales (DOM)', await dom(f), '10.56')

    // ── E: múltiples KR, cada fila independiente ──
    scenario = 'E · Varios KR'
    await replaceIn(base('branch', 2), '12.5')
    await typeIn(inc('branch', 2), '-2.25')
    check('fila 2: meta 10.25', await dom(goal('branch', 2)), '10.25')
    check('fila 1 intacta: base', await dom(base('branch', 1)), '35000')
    check('fila 1 intacta: meta', await dom(goal('branch', 1)), '50000')
    const w = (r) => `#editor table tbody tr:nth-child(${r}) td:nth-child(6) input`
    await replaceIn(w(2), '25.5')
    check('peso fila 2 = 25.5', await dom(w(2)), '25.5')
    check('peso fila 1 intacto', await dom(w(1)), '50')
    await replaceIn(w(3), '90')
    check('peso fila 3 se limita a lo disponible (24.5)', await dom(w(3)), '24.5')
    check('modelo peso fila 3', await model('edRows', 3, 'weight'), '24.5')

    // ── F: cambios rápidos de gestor / sucursal / periodo / KPI con consultas en vuelo ──
    scenario = 'F · Cambios rápidos'
    await page.click('#emp-reset')
    await sleep(150)
    await page.click('#emp-change-employee')
    await page.click('#emp-change-branch')
    await page.click('#emp-change-date')
    await page.click('#emp-change-kpi')
    await settle()
    const urls = await pending()
    // Cada cambio invalida y relanza la consulta de ambas filas: las dos últimas
    // son las vigentes (fila 1, fila 2) y deben llevar el contexto final.
    const total = urls.length
    const finalCtx = (u) => u.includes('kpi_id=4') && u.includes('employee_id=22') && u.includes('branch_id=3') && u.includes('start_date=2026-11-01')
    check('consultas vigentes con el contexto final', [finalCtx(urls[total - 2]), finalCtx(urls[total - 1])], [true, true])

    // Responde primero las vigentes y DESPUÉS todas las viejas (llegan tarde).
    for (let i = total - 1; i >= 0; i--) {
        await respond(i, i === total - 2 ? 999 : i === total - 1 ? 888 : 111)
    }

    await settle()
    check('fila 1 solo toma la respuesta vigente', await dom(base('employee', 1)), '999')
    check('fila 2 solo toma la respuesta vigente', await dom(base('employee', 2)), '888')
    const leaked = await page.evaluate(() => window.__state.empRows.value.some((r) => r.baseline_value === '111'))
    check('ninguna respuesta obsoleta se cruzó', leaked, false)
} catch (e) {
    results.push({ scenario, name: 'EXCEPCIÓN', pass: false, actual: `${e} @ ${String(e.stack).split('\n').find((l) => l.includes('run.mjs'))}`, expected: '' })
} finally {
    await browser.close()
    await server.close()
}

let failed = 0

for (const r of results) {
    if (!r.pass) {
failed++
}

    console.log(`${r.pass ? 'OK  ' : 'FAIL'} [${r.scenario}] ${r.name}${r.pass ? '' : ` — obtenido ${JSON.stringify(r.actual)}, esperado ${JSON.stringify(r.expected)}`}`)
}

if (pageErrors.length) {
console.log('Errores de página:', pageErrors)
}

console.log(`\n${results.length - failed}/${results.length} verificaciones OK`)
process.exit(failed ? 1 : 0)

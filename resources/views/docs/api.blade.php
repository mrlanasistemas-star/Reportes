<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>API de Reportería</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @verbatim
    <style>
        :root {
            --bg: #f7f8fa; --surface: #ffffff; --surface-2: #f1f3f6; --border: #e3e6eb;
            --text: #16191f; --muted: #5b6472; --accent: #0f766e; --accent-soft: #e6f4f2;
            --code-bg: #0f1720; --code-text: #e6edf3; --warn-bg: #fff7e6; --warn-border: #f0c36d;
            --get: #2563eb;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg: #0d1117; --surface: #151b23; --surface-2: #1c232d; --border: #2a323d;
                --text: #e6edf3; --muted: #9aa5b4; --accent: #2dd4bf; --accent-soft: #10302d;
                --code-bg: #0a0e13; --code-text: #e6edf3; --warn-bg: #2a2212; --warn-border: #8a6a24;
                --get: #60a5fa;
            }
        }
        :root[data-theme="dark"] {
            --bg: #0d1117; --surface: #151b23; --surface-2: #1c232d; --border: #2a323d;
            --text: #e6edf3; --muted: #9aa5b4; --accent: #2dd4bf; --accent-soft: #10302d;
            --code-bg: #0a0e13; --code-text: #e6edf3; --warn-bg: #2a2212; --warn-border: #8a6a24;
            --get: #60a5fa;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.6 Inter, system-ui, sans-serif; }
        a { color: var(--accent); }
        .layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); max-width: 1180px; margin: 0 auto; gap: 40px; padding: 32px 24px 80px; }
        nav.toc { position: sticky; top: 24px; align-self: start; font-size: 14px; }
        nav.toc strong { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 8px; }
        nav.toc a { display: block; padding: 4px 10px; color: var(--muted); text-decoration: none; border-left: 2px solid var(--border); }
        nav.toc a:hover { color: var(--text); border-left-color: var(--accent); }
        header.hero { margin-bottom: 32px; }
        .badge { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); }
        h1 { font-size: 30px; margin: 10px 0 6px; letter-spacing: -.01em; }
        h2 { font-size: 21px; margin: 44px 0 12px; padding-top: 8px; scroll-margin-top: 16px; }
        h3 { font-size: 16px; margin: 24px 0 8px; }
        p, li { color: var(--text); }
        .lead { color: var(--muted); font-size: 16px; margin: 0; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; }
        .base { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 16px; }
        code { font-family: "JetBrains Mono", ui-monospace, monospace; font-size: 13px; background: var(--surface-2); padding: 1px 6px; border-radius: 5px; overflow-wrap: anywhere; }
        pre { position: relative; background: var(--code-bg); color: var(--code-text); border-radius: 10px; padding: 16px 18px; overflow-x: auto; font-size: 13px; line-height: 1.55; }
        pre code { background: none; padding: 0; color: inherit; overflow-wrap: normal; }
        .copy { position: absolute; top: 8px; right: 8px; font: 500 12px Inter, sans-serif; color: #c9d1d9; background: #ffffff14; border: 1px solid #ffffff22; border-radius: 6px; padding: 3px 9px; cursor: pointer; }
        .copy:hover { background: #ffffff26; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 9px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { background: var(--surface-2); font-weight: 600; font-size: 13px; color: var(--muted); }
        tr:last-child td { border-bottom: none; }
        .method { font: 600 11px "JetBrains Mono", monospace; color: var(--get); }
        .note { border-left: 3px solid var(--warn-border); background: var(--warn-bg); padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 16px 0; }
        .steps { counter-reset: s; list-style: none; padding: 0; }
        .steps > li { counter-increment: s; position: relative; padding-left: 40px; margin-bottom: 18px; }
        .steps > li::before { content: counter(s); position: absolute; left: 0; top: 0; width: 26px; height: 26px; border-radius: 50%; background: var(--accent); color: var(--bg); font-weight: 700; font-size: 13px; display: grid; place-items: center; }
        .tabs { display: flex; gap: 4px; margin-top: 8px; flex-wrap: wrap; }
        .tabs button { font: 500 13px Inter, sans-serif; border: 1px solid var(--border); background: var(--surface); color: var(--muted); padding: 5px 12px; border-radius: 6px; cursor: pointer; }
        .tabs button[aria-selected="true"] { background: var(--accent); border-color: var(--accent); color: var(--bg); }
        .tab-panel[hidden] { display: none; }
        footer { margin-top: 56px; color: var(--muted); font-size: 13px; }
        @media (max-width: 860px) {
            .layout { grid-template-columns: 1fr; padding: 20px 16px 60px; gap: 0; }
            nav.toc { display: none; }
            h1 { font-size: 25px; }
        }
    </style>
    @endverbatim
</head>
<body>
<div class="layout">
    <nav class="toc" aria-label="Contenido">
        <strong>Contenido</strong>
        <a href="#inicio">Inicio rápido</a>
        <a href="#credenciales">Alta de credenciales</a>
        <a href="#autenticacion">Autenticación</a>
        <a href="#permisos">Permisos</a>
        <a href="#endpoints">Endpoints</a>
        <a href="#parametros">Parámetros</a>
        <a href="#respuestas">Formato de respuesta</a>
        <a href="#metricas">Métricas</a>
        <a href="#efectividad">Efectividad de cobranza</a>
        <a href="#ejemplos">Ejemplos de código</a>
        <a href="#errores">Errores y límites</a>
        <a href="#sucursales">Restricción por sucursal</a>
    </nav>

    <main>
        <header class="hero">
            <span class="badge">v1 · Solo lectura</span>
            <h1>API de Reportería MR Lana</h1>
            <p class="lead">API REST de servidor a servidor para que otros sistemas consulten los indicadores que Reportería ya calcula (EBITDA, OPEX, cartera, mora, colocación, recuperación, efectividad y OKR). La API no recalcula nada: entrega exactamente los mismos valores que ves en la web, el Excel y el PDF.</p>
            <div class="base card">
                <span>URL base:</span>
                <code id="base-url">{{ $baseUrl }}</code>
            </div>
        </header>

        <h2 id="inicio">Inicio rápido</h2>
        <ol class="steps">
            <li><strong>Pide un token</strong> al administrador de Reportería (ver <a href="#credenciales">Alta de credenciales</a>).</li>
            <li><strong>Comprueba la conexión</strong> (no requiere token):
<pre><code>curl {{ $baseUrl }}/health</code></pre></li>
            <li><strong>Revisa qué puede hacer tu token</strong>:
<pre><code>curl -H "Authorization: Bearer TU_TOKEN" -H "Accept: application/json" \
     {{ $baseUrl }}/capabilities</code></pre></li>
            <li><strong>Obtén los periodos</strong> y usa su <code>id</code> como <code>period_id</code> en las consultas financieras:
<pre><code>curl -H "Authorization: Bearer TU_TOKEN" -H "Accept: application/json" \
     "{{ $baseUrl }}/periods?type=monthly"</code></pre></li>
            <li><strong>Consulta indicadores</strong>:
<pre><code>curl -H "Authorization: Bearer TU_TOKEN" -H "Accept: application/json" \
     "{{ $baseUrl }}/financial/metric-values?period_id=21&amp;scope=general&amp;metrics=ebitda,opex,mora"</code></pre></li>
        </ol>

        <h2 id="credenciales">Alta de credenciales (administrador)</h2>
        <p>Cada sistema que consume la API se registra como un <strong>consumidor</strong> (un sistema, nunca una persona) y recibe su propio token. No hay pantalla para esto; se hace por consola en el servidor.</p>
        <ol class="steps">
            <li>Entra al VPS por SSH y ve a la carpeta del proyecto:
<pre><code>ssh usuario@tu-vps
cd /ruta/del/proyecto/Reportes</code></pre></li>
            <li>Crea el consumidor con solo los permisos que necesite:
<pre><code>php artisan api:consumers:create "Auditoría Operativa" "auditoria-operativa" \
    --ability=branches.read --ability=employees.read --ability=periods.read \
    --ability=financial.read --ability=collections.read --ability=okr.read</code></pre>
                <p>El primer texto es el nombre visible; el segundo es un código único (minúsculas y guiones).</p></li>
            <li><strong>Copia el token que aparece en consola</strong> y entrégalo por un canal seguro. Solo se muestra una vez; si se pierde, hay que revocarlo y crear otro consumidor.</li>
            <li>Opcional: limita el acceso a ciertas sucursales con <code>--branch=5 --branch=9</code>, o crea un consumidor sin acceso a ninguna sucursal con <code>--deny-branches</code>.</li>
        </ol>
        <h3>Revocar acceso</h3>
<pre><code>php artisan api:consumers:revoke auditoria-operativa --deactivate</code></pre>
        <div class="note">El token equivale a una contraseña: guárdalo en variables de entorno del otro sistema (por ejemplo <code>.env</code>), nunca en el código, en el navegador ni en un repositorio.</div>

        <h2 id="autenticacion">Autenticación</h2>
        <p>Todas las rutas, excepto <code>/health</code>, exigen estos encabezados:</p>
<pre><code>Authorization: Bearer TU_TOKEN
Accept: application/json</code></pre>
        <p>La API es de servidor a servidor. No la llames desde JavaScript en el navegador, porque expondrías el token.</p>

        <h2 id="permisos">Permisos (abilities)</h2>
        <p>Cada token tiene una lista de permisos. Si falta el permiso que exige un endpoint, la respuesta es <code>403</code>.</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Permiso</th><th>Da acceso a</th></tr></thead>
            <tbody>
                <tr><td><code>branches.read</code></td><td><code>/branches</code></td></tr>
                <tr><td><code>branches.read.global</code></td><td>Ver el catálogo completo de sucursales aunque el consumidor esté restringido</td></tr>
                <tr><td><code>employees.read</code></td><td><code>/employees</code>, <code>/employees/{id}</code></td></tr>
                <tr><td><code>employees.read</code> + <code>financial.read</code></td><td><code>/employees/{id}/financial-summary</code> (exige ambos)</td></tr>
                <tr><td><code>periods.read</code></td><td><code>/periods</code>, <code>/periods/{id}</code></td></tr>
                <tr><td><code>financial.read</code></td><td><code>/financial/*</code></td></tr>
                <tr><td><code>collections.read</code></td><td><code>/collections/effectiveness</code></td></tr>
                <tr><td><code>okr.read</code></td><td><code>/okr/*</code></td></tr>
            </tbody>
        </table></div>

        <h2 id="endpoints">Endpoints</h2>
        <p>Todos son <code>GET</code>. La API no permite escribir ni modificar datos.</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Ruta</th><th>Descripción</th><th>Permiso</th></tr></thead>
            <tbody>
                <tr><td><span class="method">GET</span> <code>/health</code></td><td>Estado del servicio</td><td>Ninguno</td></tr>
                <tr><td><span class="method">GET</span> <code>/capabilities</code></td><td>Qué recursos puede usar este token</td><td>Token</td></tr>
                <tr><td><span class="method">GET</span> <code>/branches</code></td><td>Catálogo de sucursales activas (paginado)</td><td><code>branches.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/employees?branch_id=</code></td><td>Colaboradores activos (paginado)</td><td><code>employees.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/employees/{id}</code></td><td>Datos de identificación del colaborador</td><td><code>employees.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/employees/{id}/financial-summary?period_id=</code></td><td>Ficha financiera del colaborador en un periodo</td><td><code>employees.read</code> + <code>financial.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/periods?type=</code></td><td>Periodos disponibles. <code>type</code>: weekly, monthly, bimonthly, quarterly, semiannual, annual</td><td><code>periods.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/periods/{id}</code></td><td>Detalle de un periodo</td><td><code>periods.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/financial/summary?period_id=&amp;scope=</code></td><td>Todos los indicadores de un alcance</td><td><code>financial.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/financial/branches/{id}?period_id=</code></td><td>Indicadores de una sucursal</td><td><code>financial.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/financial/employees/{id}?period_id=</code></td><td>Indicadores atribuibles a un colaborador</td><td><code>financial.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/financial/metrics</code></td><td>Catálogo de indicadores disponibles</td><td><code>financial.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/financial/metric-values?period_id=&amp;scope=&amp;metrics=</code></td><td>Solo los indicadores que pidas (separados por coma)</td><td><code>financial.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/collections/effectiveness?period_id=&amp;scope=</code></td><td>Efectividad de cobranza (histórica y V4)</td><td><code>collections.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/okr/summary</code></td><td>Resumen de OKR activos</td><td><code>okr.read</code></td></tr>
                <tr><td><span class="method">GET</span> <code>/okr/employees/{id}</code></td><td>Cumplimiento OKR de un colaborador</td><td><code>okr.read</code></td></tr>
            </tbody>
        </table></div>

        <h2 id="parametros">Parámetros comunes</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Parámetro</th><th>Valores</th><th>Notas</th></tr></thead>
            <tbody>
                <tr><td><code>period_id</code></td><td>Entero</td><td>Obligatorio en los endpoints financieros y de efectividad. La API nunca supone «el último periodo». Se obtiene de <code>/periods</code>.</td></tr>
                <tr><td><code>scope</code></td><td><code>general</code> · <code>branch</code> · <code>employee</code></td><td>Por defecto <code>general</code>.</td></tr>
                <tr><td><code>branch_id</code></td><td>Entero</td><td>Obligatorio si <code>scope=branch</code>.</td></tr>
                <tr><td><code>employee_id</code></td><td>Entero</td><td>Obligatorio si <code>scope=employee</code>.</td></tr>
                <tr><td><code>metrics</code></td><td>Lista separada por comas</td><td>Solo en <code>/financial/metric-values</code>. Usa códigos de <code>/financial/metrics</code>.</td></tr>
                <tr><td><code>per_page</code></td><td>1 a 100</td><td>En listados paginados.</td></tr>
            </tbody>
        </table></div>

        <h2 id="respuestas">Formato de respuesta</h2>
        <p>Cada indicador financiero llega con esta forma:</p>
@verbatim
<pre><code>{
  "metric": "ebitda",
  "period_id": 21,
  "period_type": "monthly",
  "period_label": "Junio 2026",
  "start_date": "2026-06-01",
  "end_date": "2026-06-30",
  "calculated_at": "11/10/2026 01:30",
  "source": "reporteria.radiography_snapshot",
  "status": "ok",
  "scope": { "type": "branch", "branch_id": 5, "employee_id": null },
  "value": 123456.78,
  "unit": "MXN",
  "definition_version": "v1"
}</code></pre>
@endverbatim
        <div class="table-wrap"><table>
            <thead><tr><th><code>status</code></th><th>Significado</th></tr></thead>
            <tbody>
                <tr><td><code>ok</code></td><td>Valor disponible.</td></tr>
                <tr><td><code>not_available</code></td><td>El periodo no tiene radiografía generada. <code>value</code> es <code>null</code>.</td></tr>
                <tr><td><code>not_attributable</code></td><td>El indicador no aplica a ese alcance (por ejemplo, rotación por colaborador). <code>value</code> es <code>null</code>.</td></tr>
            </tbody>
        </table></div>
        <div class="note">Un <code>null</code> no significa cero. Tu sistema debe mostrarlo como «sin dato», nunca como $0.</div>

        <h2 id="metricas">Catálogo de métricas</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Código</th><th>Indicador</th><th>Unidad</th><th>Alcances</th></tr></thead>
            <tbody>
                <tr><td><code>ebitda</code></td><td>EBITDA</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>ebitda_margin</code></td><td>Margen EBITDA</td><td>%</td><td>general, branch, employee</td></tr>
                <tr><td><code>opex</code></td><td>OPEX</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>placement</code></td><td>Colocación</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>recovery</code></td><td>Recuperación</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>mora</code></td><td>Mora</td><td>%</td><td>general, branch, employee</td></tr>
                <tr><td><code>portfolio_total</code></td><td>Cartera total</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>portfolio_overdue</code></td><td>Cartera vencida</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>portfolio_current</code></td><td>Cartera vigente</td><td>MXN</td><td>general, branch, employee</td></tr>
                <tr><td><code>turnover</code></td><td>Rotación de personal</td><td>%</td><td>general, branch</td></tr>
                <tr><td><code>collections_effectiveness_legacy</code></td><td>Efectividad de cobranza (histórica)</td><td>%</td><td>general, branch, employee</td></tr>
            </tbody>
        </table></div>

        <h2 id="efectividad">Efectividad de cobranza</h2>
        <p><code>/collections/effectiveness</code> siempre devuelve dos versiones por separado, que nunca se mezclan:</p>
        <ul>
            <li><code>versions.legacy</code>: la efectividad histórica (vigente, atrasado, vencido y porcentaje).</li>
            <li><code>versions.approved</code>: Efectividad de Cobranza V4. Solo existe para <strong>periodos semanales</strong> y únicamente si ya se generó desde el módulo web. Sus estados son <code>ok</code>, <code>stale</code> (los archivos cambiaron después de generarla), <code>not_available</code>, <code>not_attributable</code> y <code>not_supported_period</code> (para meses o bimestres).</li>
        </ul>

        <h2 id="ejemplos">Ejemplos de código</h2>
        <div class="tabs" role="tablist">
            <button role="tab" aria-selected="true" data-tab="php">PHP (Laravel)</button>
            <button role="tab" aria-selected="false" data-tab="node">Node.js</button>
            <button role="tab" aria-selected="false" data-tab="python">Python</button>
        </div>
        <div class="tab-panel" data-panel="php">
<pre><code># .env del otro proyecto
REPORTERIA_API_URL={{ $baseUrl }}
REPORTERIA_API_TOKEN=pega_aqui_el_token

// config/services.php
'reporteria' => [
    'url'   => env('REPORTERIA_API_URL'),
    'token' => env('REPORTERIA_API_TOKEN'),
],

// Uso
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.reporteria.token'))
    ->acceptJson()
    ->timeout(30)
    ->get(config('services.reporteria.url') . '/financial/metric-values', [
        'period_id' => 21,
        'scope'     => 'branch',
        'branch_id' => 5,
        'metrics'   => 'ebitda,opex,mora',
    ]);

if ($response->successful()) {
    $metrics = $response->json();
} else {
    logger()->warning('Reportería API', ['status' => $response->status(), 'body' => $response->json()]);
}</code></pre>
        </div>
        <div class="tab-panel" data-panel="node" hidden>
<pre><code>// Node 18+ (fetch nativo), en el servidor
const BASE = process.env.REPORTERIA_API_URL; // {{ $baseUrl }}
const TOKEN = process.env.REPORTERIA_API_TOKEN;

const params = new URLSearchParams({ period_id: '21', scope: 'general', metrics: 'ebitda,opex,mora' });
const res = await fetch(`${BASE}/financial/metric-values?${params}`, {
  headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' },
});

if (!res.ok) throw new Error(`Reportería API ${res.status}: ${(await res.json()).message}`);
const data = await res.json();</code></pre>
        </div>
        <div class="tab-panel" data-panel="python" hidden>
<pre><code>import os, requests

BASE = os.environ["REPORTERIA_API_URL"]  # {{ $baseUrl }}
TOKEN = os.environ["REPORTERIA_API_TOKEN"]

r = requests.get(
    f"{BASE}/financial/summary",
    params={"period_id": 21, "scope": "general"},
    headers={"Authorization": f"Bearer {TOKEN}", "Accept": "application/json"},
    timeout=30,
)
r.raise_for_status()
data = r.json()</code></pre>
        </div>

        <h2 id="errores">Errores y límites</h2>
        <p>Todas las respuestas de error son JSON con la forma <code>{"message": "..."}</code>.</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Código</th><th>Causa</th></tr></thead>
            <tbody>
                <tr><td><code>401</code></td><td>Falta el token o no es válido.</td></tr>
                <tr><td><code>403</code></td><td>El token no tiene el permiso, el consumidor está desactivado o se pidió una sucursal fuera de su alcance.</td></tr>
                <tr><td><code>404</code></td><td>El periodo, colaborador o sucursal no existe.</td></tr>
                <tr><td><code>422</code></td><td>Parámetros inválidos o faltantes (<code>period_id</code>, <code>scope</code>, métricas desconocidas, <code>per_page</code> fuera de rango).</td></tr>
                <tr><td><code>429</code></td><td>Se superó el límite de 60 peticiones por minuto por consumidor. Espera y reintenta.</td></tr>
            </tbody>
        </table></div>
        <p>Cada consulta autenticada queda registrada (quién, qué endpoint, cuándo y desde qué IP). Nunca se guardan los valores financieros de la respuesta.</p>

        <h2 id="sucursales">Restricción por sucursal</h2>
        <ul>
            <li><strong>Global</strong>: puede consultar <code>scope=general</code> y cualquier sucursal o colaborador.</li>
            <li><strong>Restringido</strong> (creado con <code>--branch</code>): solo sus sucursales. <code>scope=general</code> responde <code>403</code>.</li>
            <li><strong>Sin acceso</strong> (creado con <code>--deny-branches</code>): catálogos vacíos y <code>403</code> en todo lo que dependa de una sucursal.</li>
        </ul>
        <p>Para un colaborador se valida la sucursal en la que estaba <em>en el periodo consultado</em>, no la actual.</p>

        <footer>Reportería MR Lana · Documentación interna de la API v1 · Uso exclusivo de administradores.</footer>
    </main>
</div>
@verbatim
<script>
    document.querySelectorAll('pre').forEach(function (pre) {
        var btn = document.createElement('button');
        btn.className = 'copy';
        btn.type = 'button';
        btn.textContent = 'Copiar';
        btn.addEventListener('click', function () {
            var text = pre.querySelector('code').innerText;
            try {
                navigator.clipboard.writeText(text).then(function () {
                    btn.textContent = 'Copiado';
                    setTimeout(function () { btn.textContent = 'Copiar'; }, 1500);
                });
            } catch (e) {}
        });
        pre.appendChild(btn);
    });

    var tabs = document.querySelectorAll('.tabs button');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.setAttribute('aria-selected', String(t === tab)); });
            document.querySelectorAll('.tab-panel').forEach(function (p) {
                p.hidden = p.dataset.panel !== tab.dataset.tab;
            });
        });
    });
</script>
@endverbatim
</body>
</html>

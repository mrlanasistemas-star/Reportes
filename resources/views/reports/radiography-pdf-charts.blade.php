<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gráficas — {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Helvetica, Arial, sans-serif; color: #1e293b; background: #fff; }
@page { margin: 14mm; }
.page { page-break-after: always; }
.page:last-child { page-break-after: auto; }
.header {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 10px; margin-bottom: 16px; border-bottom: 2px solid #1f2937;
}
.brand { font-size: 15pt; font-weight: 700; color: #106A59; letter-spacing: .5px; }
.subtitle { font-size: 9.5pt; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; }
.grid2 { display: flex; gap: 16px; margin-bottom: 16px; }
.card {
    flex: 1; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.card h3 {
    font-size: 10pt; color: #1f2937; text-transform: uppercase; letter-spacing: .4px;
    margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0;
}
.card canvas { width: 100% !important; }
.card.full { flex: none; width: 100%; }
.footer-note { margin-top: 10px; font-size: 7.5pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>

<div class="page">
    <div class="header">
        <div class="brand">MR LANA — Radiografía Financiera</div>
        <div class="subtitle">{{ strtoupper($period->label) }} · Gráficas ejecutivas</div>
    </div>

    <div class="grid2">
        <div class="card">
            <h3>Categoría EBITDA por sucursal</h3>
            <canvas id="chartCategorias" height="220"></canvas>
        </div>
        <div class="card">
            <h3>Cartera vencida por antigüedad (mora)</h3>
            <canvas id="chartMora" height="220"></canvas>
        </div>
    </div>

    <div class="grid2">
        <div class="card full">
            <h3>Top conceptos de gasto operativo</h3>
            <canvas id="chartGastos" height="200"></canvas>
        </div>
    </div>

    <div class="footer-note">Generado automáticamente — Radiografía Financiera MR LANA · {{ $period->label }}</div>
</div>

<div class="page">
    <div class="header">
        <div class="brand">MR LANA — Radiografía Financiera</div>
        <div class="subtitle">{{ strtoupper($period->label) }} · EBITDA por sucursal</div>
    </div>

    <div class="grid2">
        <div class="card full">
            <h3>EBITDA por sucursal</h3>
            <canvas id="chartEbitdaSucursal" height="260"></canvas>
        </div>
    </div>

    <div class="grid2">
        <div class="card full">
            <h3>Colocación vs. recuperación por sucursal</h3>
            <canvas id="chartColocRec" height="260"></canvas>
        </div>
    </div>

    <div class="footer-note">Generado automáticamente — Radiografía Financiera MR LANA · {{ $period->label }}</div>
</div>

<script>{!! $chartJsInline !!}</script>
<script>
Chart.defaults.font.family = "'Segoe UI', Helvetica, Arial, sans-serif";
Chart.defaults.font.size = 10;
Chart.defaults.animation = false;

const palette = ['#106A59', '#1DC1A2', '#5B9BD5', '#F59E0B', '#EF4444', '#8B5CF6', '#0EA5E9', '#84CC16'];

new Chart(document.getElementById('chartCategorias'), {
    type: 'doughnut',
    data: {
        labels: {!! json_encode($categoriaLabels) !!},
        datasets: [{ data: {!! json_encode($categoriaCounts) !!}, backgroundColor: {!! json_encode($categoriaColors) !!} }],
    },
    options: { plugins: { legend: { position: 'bottom' } } },
});

new Chart(document.getElementById('chartMora'), {
    type: 'bar',
    data: {
        labels: {!! json_encode(array_column($moraBuckets, 'label')) !!},
        datasets: [{ data: {!! json_encode(array_column($moraBuckets, 'valor')) !!}, backgroundColor: '#EF4444', borderRadius: 4 }],
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
});

new Chart(document.getElementById('chartGastos'), {
    type: 'bar',
    data: {
        labels: {!! json_encode(array_keys($gastosTopN)) !!},
        datasets: [{ data: {!! json_encode(array_values($gastosTopN)) !!}, backgroundColor: '#5B9BD5', borderRadius: 4 }],
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } },
});

new Chart(document.getElementById('chartEbitdaSucursal'), {
    type: 'bar',
    data: {
        labels: {!! json_encode(array_column($categorias, 'nombre')) !!},
        datasets: [{ data: {!! json_encode(array_column($categorias, 'ebitda')) !!}, backgroundColor: palette, borderRadius: 4 }],
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
});

new Chart(document.getElementById('chartColocRec'), {
    type: 'bar',
    data: {
        labels: {!! json_encode(array_column($sucursalRows, 'sucursal')) !!},
        datasets: [
            { label: 'Colocación', data: {!! json_encode(array_column($sucursalRows, 'colocacion')) !!}, backgroundColor: '#106A59', borderRadius: 4 },
            { label: 'Recuperación', data: {!! json_encode(array_column($sucursalRows, 'recuperacion')) !!}, backgroundColor: '#1DC1A2', borderRadius: 4 },
        ],
    },
    options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } },
});
</script>

</body>
</html>

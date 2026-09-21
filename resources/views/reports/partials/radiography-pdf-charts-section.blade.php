{{-- Gráficas ejecutivas (Chart.js, renderizado real vía Chrome headless) — incluidas
     DENTRO del mismo documento del PDF general (un solo render Browsershot, sin fusión
     de PDFs vía FPDI). Reutiliza el sistema visual compartido (x-pdf.*) — retoma
     21-sep-2026, Parte B. --}}
<x-pdf.section-title title="Gráficas ejecutivas" :subtitle="strtoupper($period->label)" />
<div class="pdf-charts-row">
    <x-pdf.chart-card title="Categoría EBITDA por sucursal" chart-id="chartCategorias" :height="200" />
    <x-pdf.chart-card title="Cartera vencida por antigüedad (mora)" chart-id="chartMora" :height="200" />
</div>
<div class="pdf-avoid" style="border: 0.75pt solid #e2e8f0; border-radius: 8px; padding: 10px 12px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); margin-bottom: 10px;">
    <h3 style="font-size:8.3pt; color:#1f2937; text-transform:uppercase; letter-spacing:.3px; margin-bottom:6px; padding-bottom:5px; border-bottom:0.5pt solid #e2e8f0;">Top conceptos de gasto operativo</h3>
    <canvas id="chartGastos" height="180"></canvas>
</div>

<x-pdf.section-title title="EBITDA por sucursal" alt />
<div class="pdf-avoid" style="border: 0.75pt solid #e2e8f0; border-radius: 8px; padding: 10px 12px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); margin-bottom: 10px;">
    <canvas id="chartEbitdaSucursal" height="220"></canvas>
</div>
<div class="pdf-avoid" style="border: 0.75pt solid #e2e8f0; border-radius: 8px; padding: 10px 12px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);">
    <h3 style="font-size:8.3pt; color:#1f2937; text-transform:uppercase; letter-spacing:.3px; margin-bottom:6px; padding-bottom:5px; border-bottom:0.5pt solid #e2e8f0;">Colocación vs. recuperación por sucursal</h3>
    <canvas id="chartColocRec" height="220"></canvas>
</div>

<script>window.__PDF_READY__ = false;</script>
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

window.__PDF_READY__ = true;
</script>

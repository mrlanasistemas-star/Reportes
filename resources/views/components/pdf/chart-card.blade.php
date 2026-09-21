@props(['title', 'subtitle' => null, 'chartId', 'height' => 200])
{{-- Tarjeta con <canvas> para Chart.js — el caller dibuja el chart en un <script>
     al final del documento (ver window.__PDF_READY__ en pdf/layout.blade.php,
     readyImmediately=false mientras haya gráficas pendientes de renderizar). --}}
<div class="pdf-chart-card">
    <h3>{{ $title }}</h3>
    @if($subtitle)
    <div class="chart-subtitle">{{ $subtitle }}</div>
    @endif
    <canvas id="{{ $chartId }}" height="{{ $height }}"></canvas>
</div>

@props(['label', 'value', 'tone' => '', 'sub' => null, 'subTone' => ''])
{{-- Una sola tarjeta KPI — envolver varias en <div class="pdf-kpi-grid"> (flex, wrap).
     $tone/$subTone: '', 'pos', 'negv' — ver RadiographyMetricToneHelper::toneClass(). --}}
<div class="pdf-kpi-card">
    <div class="kpi-label">{{ $label }}</div>
    <div class="kpi-value {{ $tone }}">{{ $value }}</div>
    @if($sub)
    <div class="kpi-sub {{ $subTone }}">{{ $sub }}</div>
    @endif
</div>

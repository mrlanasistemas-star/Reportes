@props(['title', 'subtitle' => null, 'meta' => []])
{{-- Header compacto (retoma 21-sep-2026, punto 24) — nunca una portada aparte
     desperdiciada: es la cabecera de la primera página útil, los KPIs empiezan
     inmediatamente debajo. $meta es un array label => value ya formateado. --}}
<div class="pdf-header pdf-avoid">
    <div class="brand-block">
        <div class="brand-mark">MR LANA</div>
        <div class="brand-divider"></div>
        <div>
            <div class="report-title">{{ $title }}</div>
            @if($subtitle)
            <div class="report-subtitle">{{ $subtitle }}</div>
            @endif
        </div>
    </div>
    @if(!empty($meta))
    <div class="meta-block">
        @foreach($meta as $label => $value)
        <div><b>{{ $label }}:</b> {{ $value }}</div>
        @endforeach
    </div>
    @endif
</div>

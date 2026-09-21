@props(['title', 'subtitle' => null, 'alt' => false])
<div class="pdf-section-title {{ $alt ? 'alt' : '' }}">
    {{ $title }}
    @if($subtitle)
    <div class="subtitle">{{ $subtitle }}</div>
    @endif
</div>

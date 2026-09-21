@props(['title' => null, 'tone' => null])
@php
$toneClass = match($tone) { 'ok' => 'tone-ok', 'warn' => 'tone-warn', 'alert' => 'tone-alert', default => '' };
@endphp
<div class="pdf-summary-panel {{ $toneClass }}">
    @if($title)
    <h4>{{ $title }}</h4>
    @endif
    {{ $slot }}
</div>

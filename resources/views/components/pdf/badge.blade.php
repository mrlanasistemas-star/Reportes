@props(['label', 'category' => null])
@php
$class = match(strtoupper((string) $category)) {
    'DIAMANTE'  => 'pdf-badge-diamante',
    'MASTER'    => 'pdf-badge-master',
    'SENIOR'    => 'pdf-badge-senior',
    'JUNIOR'    => 'pdf-badge-junior',
    'MANTENIDO' => 'pdf-badge-mantenido',
    default     => 'pdf-badge-neutral',
};
@endphp
<span class="pdf-badge {{ $class }}">{{ $label }}</span>

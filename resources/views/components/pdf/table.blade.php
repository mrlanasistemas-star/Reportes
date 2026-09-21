@props(['avoid' => false])
{{-- Shell de tabla compacta — header repetible en cada página (thead display:
     table-header-group), filas que nunca se cortan (tbody tr page-break-inside:
     avoid). El caller arma sus propias <tr><th>/<td> dentro de los slots. --}}
<table class="pdf-table {{ $avoid ? 'pdf-avoid' : '' }}">
    @isset($head)
    <thead>{{ $head }}</thead>
    @endisset
    <tbody>{{ $slot }}</tbody>
    @isset($foot)
    <tfoot>{{ $foot }}</tfoot>
    @endisset
</table>

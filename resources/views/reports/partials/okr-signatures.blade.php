{{--
  Bloque de firmas de los documentos OKR (Carta Compromiso / Warning).
  $signers = [['role' => 'Colaborador', 'name' => '...'], ...] — siempre 3.
  Tabla en vez de grid para que cada renglón quede alineado entre columnas.
--}}
<table class="signatures">
  <tr class="sig-space">@foreach($signers as $s)<td></td>@endforeach</tr>
  <tr>@foreach($signers as $s)<td class="role">{{ $s['role'] }}</td>@endforeach</tr>
  <tr>@foreach($signers as $s)<td class="name">{{ $s['name'] ?? '' }}</td>@endforeach</tr>
  <tr>@foreach($signers as $s)<td class="hint">Nombre y firma</td>@endforeach</tr>
</table>

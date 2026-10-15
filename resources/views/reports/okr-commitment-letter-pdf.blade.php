<?php
/**
 * Carta Compromiso OKR — rehecha 14-oct-2026 en HTML de flujo real (ver
 * layouts/okr-document.blade.php). Mismo texto, secciones, tabla de KPI y
 * firmas del machote "CARTA COMPROMISO OKR - MR LANA.pdf", pero cada dato
 * ocupa su propio renglón/celda: un nombre u objetivo largo crece hacia abajo
 * en vez de encimarse sobre etiquetas o líneas.
 *
 * Usa SIEMPRE el snapshot del Objective emitido: la carta de un gestor trae
 * SUS Key Results individuales (meta propia), la de sucursal la meta de
 * sucursal — nunca se mezclan.
 */
use App\Services\Okr\OkrDocumentFormatter as Fmt;

$krs = $snapshot['key_results'] ?? [];
$slotOf = function (array $kr): string {
    $norm = \Illuminate\Support\Str::of($kr['kpi_name'] ?? '')->ascii()->upper()->value();

    return match (true) {
        str_contains($norm, 'COLOCAC') => 'colocacion',
        str_contains($norm, 'RECUPERAC') && !str_contains($norm, 'EFECTIVIDAD') => 'recuperacion',
        str_contains($norm, 'EBITDA') && !str_contains($norm, 'MARGEN') => 'ebitda',
        default => 'otro',
    };
};
// Las 3 filas fijas del machote + "Otro KPI"; los KR adicionales se agregan
// como filas propias con el nombre real de su KPI (nunca se descartan).
$rows = ['colocacion' => ['label' => 'Colocación', 'kr' => null], 'recuperacion' => ['label' => 'Recuperación', 'kr' => null], 'ebitda' => ['label' => 'EBITDA', 'kr' => null]];
$others = [];
foreach ($krs as $kr) {
    $slot = $slotOf($kr);
    if ($slot !== 'otro' && $rows[$slot]['kr'] === null) {
        $rows[$slot]['kr'] = $kr;
    } else {
        $others[] = $kr;
    }
}
if ($others === []) {
    $rows['otro'] = ['label' => 'Otro KPI', 'kr' => null];
} else {
    foreach ($others as $i => $kr) {
        $rows['otro_' . $i] = ['label' => 'Otro KPI: ' . ($kr['kpi_name'] ?? ''), 'kr' => $kr];
    }
}

$collaborator = $snapshot['collaborator_name'] ?? $snapshot['employee_name'] ?? null;
// 15-oct-2026: el jefe inmediato es el CONFIRMADO en la revisión — nunca el
// responsable administrativo del OKR como respaldo silencioso.
$boss = $snapshot['immediate_boss'] ?? null;
$field = function (?string $value) {
    $value = $value !== null ? trim($value) : null;

    return $value !== null && $value !== ''
        ? '<span class="value">' . e($value) . '</span>'
        : '<span class="value pending">Pendiente de captura</span>';
};
?>
@extends('reports.layouts.okr-document')

@section('title', 'Carta Compromiso OKR — ' . $folio)

@if($isPreview)
  @section('badge')<div class="badge-preview">VISTA PREVIA — SIN VALIDEZ OFICIAL</div>@endsection
@endif

@section('content')
  <div class="doc-title">
    <h1>CARTA COMPROMISO</h1>
    <p>CUMPLIMIENTO DE OKR Y KPI</p>
  </div>

  <div class="grid-2" style="padding: 0 6pt 8pt; border-bottom: .8pt solid #cbd5e1;">
    <div><div class="label">Lugar y fecha de emisión:</div>{!! $field(trim(($snapshot['place'] ?? '') . ', ' . Fmt::dateLong($generatedAt?->toDateString()), ', ')) !!}</div>
    <div><div class="label">Folio interno:</div>{!! $field($folio) !!}</div>
  </div>

  <div class="section keep">
    <div class="section-title">I. Datos del colaborador</div>
    <div class="boxed">
      <div><div class="label">Nombre del colaborador:</div>{!! $field($collaborator) !!}</div>
      <div><div class="label">Número de empleado:</div>{!! $field($snapshot['employee_code'] ?? null) !!}</div>
      <div><div class="label">Puesto:</div>{!! $field($snapshot['position'] ?? null) !!}</div>
      <div><div class="label">Sucursal / Área:</div>{!! $field($snapshot['branch_name'] ?? null) !!}</div>
      <div><div class="label">Jefe inmediato:</div>{!! $field($boss) !!}</div>
    </div>
  </div>

  <div class="section keep">
    <div class="section-title">II. Periodo y objetivo comprometido</div>
    <div class="boxed">
      <div><div class="label">Fecha de inicio:</div>{!! $field(Fmt::dateShort($snapshot['start_date'] ?? null)) !!}</div>
      <div><div class="label">Fecha de término:</div>{!! $field(Fmt::dateShort($snapshot['end_date'] ?? null)) !!}</div>
    </div>
  </div>

  <p class="body">
    Por medio de la presente, <b>PRODUCTOS Y SERVICIOS MR LANA S.A.P.I. DE C.V.</b> establece con el colaborador
    señalado el presente compromiso de desempeño, con la finalidad de documentar de manera clara, objetiva y medible
    el <b>Objetivo y Resultado Clave (OKR)</b> correspondiente al periodo indicado, así como los <b>Indicadores Clave de
    Desempeño (KPI)</b> mediante los cuales se dará seguimiento a su cumplimiento.
  </p>

  <div class="section keep">
    <div class="label" style="font-size: 9.6pt;">OKR / OBJETIVO PRINCIPAL:</div>
    <div class="value" style="min-height: 20pt; font-size: 10pt;">{{ $snapshot['objective_title'] ?? '' }}</div>
  </div>

  <div class="section">
    <div class="label keep-next" style="font-size: 9.6pt;">KPI Y META COMPROMETIDA</div>
    <table class="data">
      <thead>
        <tr><th style="width: 24%;">KPI</th><th style="width: 18%;">Aplica</th><th style="width: 22%;">Meta / Monto</th><th>Observaciones</th></tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          @php($kr = $row['kr'])
          <tr>
            <td class="kpi">{{ $row['label'] }}</td>
            <td class="center">
              <span class="check"><i class="{{ $kr ? 'on' : '' }}"></i>Sí</span>
              <span class="check"><i class="{{ $kr ? '' : 'on' }}"></i>No</span>
            </td>
            <td class="num">{{ $kr ? Fmt::byUnit($kr['target_value'] ?? null, $kr['unit'] ?? null) : '—' }}</td>
            <td class="note">
              @if($kr)
                @if(!empty($kr['description']))<div style="font-weight: 600; color: #1f2933;">{{ $kr['description'] }}</div>@endif
                <div>
                  {{ $kr['baseline_label'] ?? 'Línea base' }}: <b>{{ ($kr['baseline_value'] ?? null) !== null ? Fmt::byUnit($kr['baseline_value'], $kr['unit'] ?? null) : 'se congela al activar' }}</b>
                  @if(($kr['baseline_value'] ?? null) !== null)
                    · Incremento: <b>{{ Fmt::incrementByUnit((float) $kr['target_value'] - (float) $kr['baseline_value'], $kr['unit'] ?? null) }}</b>
                  @endif
                  · Peso: <b>{{ Fmt::percentage($kr['weight'] ?? null, 0) }}</b>
                </div>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @if(!empty($snapshot['observations']))
      <div style="margin-top: 6pt;"><span class="label">Observaciones generales:</span> <span style="font-size: 9pt;">{{ $snapshot['observations'] }}</span></div>
    @endif
  </div>

  <div class="section keep">
    <div class="section-title">III. Compromiso del colaborador</div>
    <p class="body"><b>1.</b> El colaborador declara que conoce y comprende el <b>OKR</b> asignado, los <b>KPI</b> mediante los cuales será evaluado, las metas establecidas y el periodo de medición señalado en la presente carta. En consecuencia, asume el compromiso de orientar su gestión, ejecución y seguimiento al logro de los resultados definidos.</p>
  </div>
  <p class="body"><b>2.</b> Para el cumplimiento del presente compromiso, el colaborador deberá dar seguimiento periódico a sus resultados, identificar oportunamente cualquier desviación respecto de la meta y ejecutar las acciones comerciales, operativas, administrativas y/o de cobranza que correspondan a su función.</p>
  <p class="body"><b>3.</b> Asimismo, se compromete a informar a su jefe inmediato sobre circunstancias relevantes que pudieran afectar el cumplimiento, participar en las revisiones de desempeño que determine <b>MR LANA</b> y atender los planes de acción o medidas correctivas que se acuerden durante el periodo.</p>
  <p class="body"><b>4.</b> El cumplimiento de las metas deberá realizarse en todo momento observando las políticas, procedimientos, controles internos y criterios de calidad de <b>MR LANA</b>. El logro de un resultado cuantitativo no justificará el incumplimiento de los lineamientos institucionales.</p>

  <div class="section keep">
    <div class="section-title">IV. Seguimiento y evaluación</div>
    <p class="body" style="margin-top: 0;">El avance y resultado del presente compromiso serán determinados con base en los <b>sistemas, reportes y controles oficiales de MR LANA</b>. Durante el periodo podrán realizarse revisiones parciales para evaluar el comportamiento de los KPI, documentar desviaciones y definir acciones de seguimiento. Al cierre del periodo se comparará el resultado obtenido contra la meta comprometida.</p>
    <table class="data">
      <thead><tr><th style="background:#eef0f2;color:#111827;">Resultado obtenido:</th><th style="background:#eef0f2;color:#111827;">% de cumplimiento:</th><th style="background:#eef0f2;color:#111827;">Fecha de evaluación:</th></tr></thead>
      <tbody><tr>
        <td class="center" style="height: 22pt;"><span class="blank-line" style="min-width: 120pt;"></span></td>
        <td class="center"><span class="blank-line" style="min-width: 50pt;"></span> %</td>
        <td class="center"><span class="blank-line" style="min-width: 22pt;"></span> / <span class="blank-line" style="min-width: 22pt;"></span> / <span class="blank-line" style="min-width: 34pt;"></span></td>
      </tr></tbody>
    </table>
  </div>

  <p class="body">Con la firma de la presente, el colaborador deja constancia de que recibió explicación del objetivo, los indicadores, las metas y el periodo de medición; que comprende los resultados esperados de su función; y que <b>asume formalmente el compromiso de desarrollar las acciones necesarias para procurar su cumplimiento</b>, sujeto a las políticas y procedimientos internos aplicables.</p>
  <div class="keep">
  <p class="body muted">La presente carta constituye un instrumento interno de planeación, seguimiento y gestión del desempeño y deberá conservarse como evidencia del objetivo comunicado y aceptado para el periodo correspondiente.</p>

  <div class="signatures">
    <div><div class="line"><div class="role">Colaborador</div><div class="name">{{ $collaborator }}</div><div class="hint">Nombre y firma</div></div></div>
    <div><div class="line"><div class="role">Jefe inmediato / Responsable</div><div class="name">{{ $boss }}</div><div class="hint">Nombre y firma</div></div></div>
    <div><div class="line"><div class="role">Vo. Bo. MR LANA</div><div class="name"></div><div class="hint">Nombre y firma</div></div></div>
  </div>
  </div>
@endsection

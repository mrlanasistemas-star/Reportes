<?php
/**
 * Warning Rojo — Llamada de atención por incumplimiento de OKR. Rehecho
 * 14-oct-2026 con el mismo layout que la Carta Compromiso (HTML de flujo,
 * Montserrat local, encabezado/pie corporativos) y el texto íntegro del
 * machote "WARNING ROJO - LLAMADA DE ATENCIÓN - MR LANA.pdf". Los resultados
 * son los del snapshot de la SEMANA evaluada de ESTE Objective — un Warning
 * individual nunca usa los resultados de toda la sucursal.
 */
use App\Services\Okr\OkrDocumentFormatter as Fmt;

$rows = $snapshot['rows'] ?? [];
$collaborator = $snapshot['collaborator_name'] ?? $snapshot['employee_name'] ?? null;
$boss = $snapshot['immediate_boss'] ?? null;
$field = function (?string $value) {
    $value = $value !== null ? trim($value) : null;

    return $value !== null && $value !== ''
        ? '<span class="value">' . e($value) . '</span>'
        : '<span class="value" style="color:#9ca3af;">—</span>';
};
$letterDate = $snapshot['letter_date'] ?? null;
?>
@extends('reports.layouts.okr-document')

@section('title', 'Warning Rojo OKR — ' . $folio)

@push('styles')
  .wr-banner { background: #c00000; color: #fff; text-align: center; font-weight: 700; font-size: 11.5pt; letter-spacing: .4pt; padding: 7pt 8pt; margin-top: 8pt; border-bottom: 2pt solid #8b0000; }
  .wr-company { text-align: center; font-weight: 700; font-size: 11.5pt; color: #1f2933; line-height: 1.3; }
  .wr-sub { text-align: center; font-weight: 700; font-size: 11.5pt; color: #9b1c1c; line-height: 1.3; }
  .wr-grid { display: grid; grid-template-columns: 1fr 1fr; column-gap: 22pt; row-gap: 6pt; padding: 8pt 10pt 4pt; }
  .wr-red { color: #9b1c1c; }
  table.data.wr th { background: #1f4e5f; }
  .wr-box { background: #f6f8fb; border: .8pt solid #d6dde7; padding: 7pt 10pt; font-weight: 600; color: #0f2a3d; font-size: 9.8pt; line-height: 1.4; }
  ol.wr-list { margin: 4pt 0 0 14pt; }
  ol.wr-list li { margin-top: 3pt; text-align: justify; padding-left: 2pt; }
  .gap-positive { color: #9b1c1c; }
@endpush

@section('content')
  <div class="wr-company">PRODUCTOS Y SERVICIOS MR LANA S.A.P.I. DE C.V.</div>
  <div class="wr-sub">LLAMADA DE ATENCIÓN POR INCUMPLIMIENTO DE OKR</div>
  <div class="wr-banner">WARNING ROJO – LLAMADA DE ATENCIÓN ESCRITA</div>

  <div class="wr-grid keep">
    <div><div class="label">FOLIO:</div>{!! $field($folio) !!}</div>
    <div><div class="label">FECHA:</div>{!! $field(Fmt::dateShort($generatedAt?->toDateString())) !!}</div>
    <div><div class="label">COLABORADOR:</div>{!! $field($collaborator) !!}</div>
    <div><div class="label">PUESTO:</div>{!! $field($snapshot['position'] ?? null) !!}</div>
    <div><div class="label">SUCURSAL / ÁREA:</div>{!! $field($snapshot['branch_name'] ?? null) !!}</div>
    <div><div class="label">PERIODO EVALUADO:</div>{!! $field('Semana ' . ($snapshot['week_number'] ?? '') . ' · del ' . Fmt::dateShort($snapshot['week_start'] ?? null) . ' al ' . Fmt::dateShort($snapshot['week_end'] ?? null)) !!}</div>
  </div>

  <p class="body">Por medio del presente, <b>PRODUCTOS Y SERVICIOS MR LANA S.A.P.I. DE C.V.</b> notifica formalmente al colaborador antes señalado que, al cierre del periodo evaluado, se identificó un <b>incumplimiento respecto del OKR y/o de los KPI previamente comunicados y comprometidos.</b></p>
  <p class="body">Este aviso se emite con base en la <b>Carta Compromiso de Cumplimiento de OKR y KPI</b> de fecha
    @if($letterDate)<b>{{ Fmt::dateShort($letterDate) }}</b>{{ !empty($snapshot['letter_folio']) ? ' (folio ' . $snapshot['letter_folio'] . ')' : '' }}@else<span class="blank-line" style="min-width: 90pt;"></span>@endif{{ ',' }}
    mediante la cual quedaron establecidos los objetivos, indicadores, metas y periodo de medición aplicables.</p>

  <div class="section keep">
    <div class="section-title" style="border-bottom: none; font-size: 11pt;">OKR / Objetivo evaluado</div>
    <div class="wr-box">{{ $snapshot['objective_title'] ?? '' }}</div>
  </div>

  <div class="section">
    <div class="section-title keep-next" style="border-bottom: none; font-size: 11pt;">Resultado del periodo</div>
    <table class="data wr">
      <thead><tr><th style="width: 22%;">KPI</th><th>Meta comprometida</th><th>Esperado a la semana</th><th>Resultado alcanzado</th><th style="width: 13%;">Cumplimiento</th><th>Brecha vs. esperado</th></tr></thead>
      <tbody>
        @forelse($rows as $row)
          <tr>
            <td class="kpi">{{ $row['kpi_name'] ?? '' }}@if(!empty($row['description']))<div style="font-weight: 500; font-size: 7.4pt; color: #4b5563;">{{ $row['description'] }}</div>@endif</td>
            <td class="num">{{ Fmt::byUnit($row['target_value'] ?? null, $row['unit'] ?? null) }}</td>
            <td class="num">{{ Fmt::byUnit($row['expected_value'] ?? null, $row['unit'] ?? null) }}</td>
            <td class="num">{{ ($row['has_data'] ?? false) ? Fmt::byUnit($row['actual_value'] ?? null, $row['unit'] ?? null) : 'Sin datos' }}</td>
            <td class="num {{ ($row['has_breach'] ?? false) ? 'wr-red' : '' }}">{{ ($row['has_data'] ?? false) ? Fmt::percentage($row['compliance_percentage'] ?? null, 1) : '—' }}</td>
            <td class="num {{ (($row['gap'] ?? 0) > 0) ? 'gap-positive' : '' }}">{{ ($row['has_data'] ?? false) ? Fmt::byUnit($row['gap'] ?? null, ($row['unit'] ?? null) === 'percentage' ? 'decimal' : ($row['unit'] ?? null)) . (($row['unit'] ?? null) === 'percentage' && ($row['gap'] ?? null) !== null ? ' pp' : '') : '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="center">Sin Key Results registrados.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="section">
    <div class="section-title wr-red" style="border-bottom: none; font-size: 11pt;">Notificación y acciones correctivas</div>
    <p class="body" style="margin-top: 0;">El resultado anterior representa una <b>desviación respecto de la meta comprometida</b> y da lugar a la presente <b class="wr-red">llamada de atención escrita</b>. A partir de su recepción, el colaborador deberá reforzar las acciones bajo su responsabilidad y atender el plan de mejora que sea acordado con su jefe inmediato.</p>
    <ol class="wr-list">
      <li>Revisar con su jefe inmediato las causas de la desviación y los factores que afectaron el cumplimiento.</li>
      <li>Definir acciones correctivas, responsables y fechas de seguimiento dentro de los <span class="blank-line" style="min-width: 26pt;"></span> días hábiles siguientes.</li>
      <li>Dar seguimiento periódico a los KPI señalados y reportar oportunamente cualquier desviación relevante.</li>
      <li>Cumplir las metas del siguiente periodo sin contravenir políticas, controles internos ni criterios de calidad de cartera.</li>
    </ol>
  </div>

  <div class="section keep">
    <div class="label">ACCIONES CORRECTIVAS ACORDADAS:</div>
    <div class="wr-box" style="font-weight: 500; white-space: pre-line;">{{ $correctiveActions }}</div>
    @if(!empty($observations))
      <div class="label" style="margin-top: 6pt;">OBSERVACIONES DE LA EMPRESA:</div>
      <div class="wr-box" style="font-weight: 500; white-space: pre-line;">{{ $observations }}</div>
    @endif
  </div>

  <p class="body">En caso de <b>incumplimiento reiterado o reincidencia</b>, la empresa podrá evaluar las medidas administrativas o disciplinarias que, en su caso, resulten procedentes conforme a las políticas internas, condiciones aplicables y disposiciones laborales vigentes.</p>
  <p class="body">La firma del colaborador <b>acredita la recepción y conocimiento del presente aviso</b> y no implica necesariamente conformidad con los hechos asentados. El colaborador podrá registrar sus observaciones en el espacio siguiente.</p>

  <div class="keep">
    <div class="section">
      <div class="label" style="font-size: 9.6pt;">OBSERVACIONES DEL COLABORADOR</div>
      <div class="ruled"></div><div class="ruled"></div><div class="ruled"></div>
    </div>
    <div class="signatures">
      <div><div class="line"><div class="role">Colaborador</div><div class="name">{{ $collaborator }}</div><div class="hint">Nombre y firma</div></div></div>
      <div><div class="line"><div class="role">Jefe inmediato</div><div class="name">{{ $boss }}</div><div class="hint">Nombre y firma</div></div></div>
      <div><div class="line"><div class="role">RH / Testigo (opcional)</div><div class="name"></div><div class="hint">Nombre y firma</div></div></div>
    </div>
  </div>
@endsection

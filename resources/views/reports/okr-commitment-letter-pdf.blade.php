<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Carta Compromiso OKR — {{ $folio }}</title>
<script>window.__PDF_READY__ = true;</script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5pt; color: #1e293b; }
@page { margin: 20mm 16mm 22mm 16mm; }

.header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2pt solid #106A59; padding-bottom: 10px; margin-bottom: 16px; }
.header .mark { font-size: 16pt; font-weight: bold; color: #106A59; letter-spacing: 0.5px; }
.header .doctype { font-size: 11pt; color: #334155; margin-top: 2px; text-transform: uppercase; letter-spacing: 1px; }
.header .folio { text-align: right; font-size: 8.5pt; color: #64748b; }
.header .folio b { color: #106A59; font-size: 10pt; }

.preview-banner { background: #fef3c7; border: 1pt solid #f59e0b; color: #92400e; text-align: center; font-weight: bold; padding: 6px; margin-bottom: 14px; border-radius: 4px; font-size: 9pt; }

.place-date { margin-bottom: 14px; font-size: 9pt; color: #475569; }

.intro { line-height: 1.6; margin-bottom: 14px; text-align: justify; }

table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
table.data td { border: 0.5pt solid #e2e8f0; padding: 6px 8px; font-size: 9pt; vertical-align: top; }
table.data td.label { width: 32%; font-weight: bold; background: #f8fafc; color: #0f172a; }

h2.section-title { font-size: 10.5pt; color: #fff; background: #106A59; padding: 6px 10px; margin-bottom: 8px; border-radius: 4px; }

table.kr { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 8.5pt; }
table.kr th { background: #1f2937; color: #fff; padding: 5px 7px; text-align: left; }
table.kr td { padding: 5px 7px; border-bottom: 0.5pt solid #e2e8f0; }
table.kr .r { text-align: right; }

.observ { border: 0.5pt solid #e2e8f0; border-radius: 4px; padding: 10px; min-height: 40px; margin-bottom: 20px; font-size: 9pt; color: #475569; }

.signatures { margin-top: 40px; display: flex; justify-content: space-between; }
.sig-box { width: 45%; text-align: center; }
.sig-line { border-top: 1pt solid #0f172a; margin-bottom: 6px; padding-top: 50px; }
.sig-label { font-size: 8.5pt; color: #475569; }

.footnote { margin-top: 16px; font-size: 7.5pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>

@if($isPreview)
<div class="preview-banner">VISTA PREVIA — ESTE DOCUMENTO NO HA SIDO EMITIDO OFICIALMENTE</div>
@endif

<div class="header">
    <div>
        <div class="mark">MR LANA</div>
        <div class="doctype">Carta Compromiso OKR</div>
    </div>
    <div class="folio">
        Folio<br><b>{{ $folio }}</b><br>
        @if($generatedAt)Emitida: {{ \Illuminate\Support\Carbon::parse($generatedAt)->format('d/m/Y H:i') }}@endif
    </div>
</div>

<div class="place-date">{{ $snapshot['place'] }}, {{ \Illuminate\Support\Carbon::parse($generatedAt ?? now())->format('d \d\e F \d\e Y') }}</div>

<p class="intro">
    Por medio del presente documento, <strong>{{ $snapshot['employee_name'] ?? ($snapshot['branch_name'] ?? 'el responsable asignado') }}</strong>
    se compromete a cumplir el siguiente Objetivo (OKR) asignado, aceptando la meta, el periodo de evaluación y el seguimiento
    semanal correspondiente.
</p>

<table class="data">
    <tr><td class="label">Colaborador</td><td>{{ $snapshot['employee_name'] ?? '— (Objective de sucursal)' }}</td></tr>
    <tr><td class="label">Puesto</td><td>{{ $snapshot['position'] ?? '—' }}</td></tr>
    <tr><td class="label">Sucursal</td><td>{{ $snapshot['branch_name'] ?? '—' }}</td></tr>
    <tr><td class="label">Jefe / Responsable</td><td>{{ $snapshot['responsible_name'] ?? '—' }}</td></tr>
    <tr><td class="label">Fecha de inicio</td><td>{{ \Illuminate\Support\Carbon::parse($snapshot['start_date'])->format('d/m/Y') }}</td></tr>
    <tr><td class="label">Fecha de término</td><td>{{ \Illuminate\Support\Carbon::parse($snapshot['end_date'])->format('d/m/Y') }}</td></tr>
    <tr><td class="label">Plazo</td><td>{{ $snapshot['duration_weeks'] }} semanas</td></tr>
</table>

<h2 class="section-title">Objective</h2>
<p class="intro">{{ $snapshot['objective_title'] }}</p>

<h2 class="section-title">KPI y Meta comprometida</h2>
<table class="kr">
    <thead>
        <tr><th>KPI</th><th class="r">Línea base</th><th class="r">Meta objetivo</th><th class="r">Ponderación</th></tr>
    </thead>
    <tbody>
        @foreach($snapshot['key_results'] as $kr)
        <tr>
            <td>{{ $kr['kpi_name'] }}</td>
            <td class="r">{{ $kr['baseline_value'] !== null ? number_format((float) $kr['baseline_value'], 2) : '—' }}</td>
            <td class="r">{{ number_format((float) $kr['target_value'], 2) }}</td>
            <td class="r">{{ $kr['weight'] }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>

<h2 class="section-title">Observaciones</h2>
<div class="observ">&nbsp;</div>

<div class="signatures">
    <div class="sig-box"><div class="sig-line"></div><div class="sig-label">Firma del colaborador</div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-label">Firma del jefe / responsable</div></div>
</div>

<div class="footnote">Generado automáticamente por el módulo OKR — MR LANA. {{ $generatedByName ? "Emitido por {$generatedByName}." : '' }}</div>

</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Warning Rojo OKR — {{ $folio }}</title>
<script>window.__PDF_READY__ = true;</script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5pt; color: #1e293b; }
@page { margin: 20mm 16mm 22mm 16mm; }

.header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2pt solid #dc2626; padding-bottom: 10px; margin-bottom: 16px; }
.header .mark { font-size: 16pt; font-weight: bold; color: #dc2626; letter-spacing: 0.5px; }
.header .doctype { font-size: 11pt; color: #334155; margin-top: 2px; text-transform: uppercase; letter-spacing: 1px; }
.header .folio { text-align: right; font-size: 8.5pt; color: #64748b; }
.header .folio b { color: #dc2626; font-size: 10pt; }

.banner { background: #fee2e2; border: 1pt solid #dc2626; color: #991b1b; text-align: center; font-weight: bold; padding: 7px; margin-bottom: 14px; border-radius: 4px; font-size: 10pt; letter-spacing: 1px; }

table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
table.data td { border: 0.5pt solid #e2e8f0; padding: 6px 8px; font-size: 9pt; vertical-align: top; }
table.data td.label { width: 32%; font-weight: bold; background: #f8fafc; color: #0f172a; }

h2.section-title { font-size: 10.5pt; color: #fff; background: #dc2626; padding: 6px 10px; margin-bottom: 8px; border-radius: 4px; }

table.kr { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 8.5pt; }
table.kr th { background: #1f2937; color: #fff; padding: 5px 7px; text-align: left; }
table.kr td { padding: 5px 7px; border-bottom: 0.5pt solid #e2e8f0; }
table.kr .r { text-align: right; }
.gap-pos { color: #b91c1c; font-weight: bold; }
.gap-neg { color: #15803d; font-weight: bold; }
.no-data { color: #94a3b8; font-style: italic; }

.block { border: 0.5pt solid #e2e8f0; border-radius: 4px; padding: 10px; margin-bottom: 14px; font-size: 9pt; color: #334155; line-height: 1.5; white-space: pre-wrap; }

.signatures { margin-top: 36px; display: flex; justify-content: space-between; }
.sig-box { width: 45%; text-align: center; }
.sig-line { border-top: 1pt solid #0f172a; margin-bottom: 6px; padding-top: 50px; }
.sig-label { font-size: 8.5pt; color: #475569; }

.footnote { margin-top: 16px; font-size: 7.5pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>

<div class="header">
    <div>
        <div class="mark">MR LANA</div>
        <div class="doctype">Warning Rojo — Llamada de Atención</div>
    </div>
    <div class="folio">
        Folio<br><b>{{ $folio }}</b><br>
        @if($generatedAt)Emitido: {{ \Illuminate\Support\Carbon::parse($generatedAt)->format('d/m/Y H:i') }}@endif
    </div>
</div>

<div class="banner">DESVIACIÓN DETECTADA EN EL CUMPLIMIENTO DEL OKR</div>

<table class="data">
    <tr><td class="label">Colaborador</td><td>{{ $snapshot['employee_name'] ?? '— (Objective de sucursal)' }}</td></tr>
    <tr><td class="label">Sucursal</td><td>{{ $snapshot['branch_name'] ?? '—' }}</td></tr>
    <tr><td class="label">Jefe / Responsable</td><td>{{ $snapshot['responsible_name'] ?? '—' }}</td></tr>
    <tr><td class="label">Objective</td><td>{{ $snapshot['objective_title'] }}</td></tr>
    <tr><td class="label">Semana evaluada</td><td>Semana {{ $snapshot['week_number'] }} ({{ \Illuminate\Support\Carbon::parse($snapshot['week_start'])->format('d/m/Y') }} — {{ \Illuminate\Support\Carbon::parse($snapshot['week_end'])->format('d/m/Y') }})</td></tr>
</table>

<h2 class="section-title">Resultado de la semana evaluada</h2>
<table class="kr">
    <thead>
        <tr><th>KPI</th><th class="r">Meta</th><th class="r">Esperado semana</th><th class="r">Real semana</th><th class="r">Cumplimiento</th><th class="r">Brecha</th></tr>
    </thead>
    <tbody>
        @foreach($snapshot['rows'] as $row)
        <tr>
            <td>{{ $row['kpi_name'] }}</td>
            <td class="r">{{ number_format($row['target_value'], 2) }}</td>
            @if($row['has_data'])
                <td class="r">{{ $row['expected_value'] !== null ? number_format((float) $row['expected_value'], 2) : '—' }}</td>
                <td class="r">{{ number_format((float) $row['actual_value'], 2) }}</td>
                <td class="r">{{ $row['compliance_percentage'] !== null ? number_format((float) $row['compliance_percentage'], 1) . '%' : '—' }}</td>
                <td class="r {{ $row['gap'] > 0 ? 'gap-pos' : 'gap-neg' }}">{{ number_format((float) $row['gap'], 2) }}</td>
            @else
                <td colspan="4" class="no-data">Sin datos cargados para esta semana</td>
            @endif
        </tr>
        @endforeach
    </tbody>
</table>

<h2 class="section-title">Acciones correctivas</h2>
<div class="block">{{ $correctiveActions }}</div>

@if($observations)
<h2 class="section-title">Observaciones</h2>
<div class="block">{{ $observations }}</div>
@endif

<div class="signatures">
    <div class="sig-box"><div class="sig-line"></div><div class="sig-label">Firma del colaborador (acuse de recibido)</div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-label">Firma del jefe / responsable</div></div>
</div>

<div class="footnote">Generado desde el módulo OKR — MR LANA. {{ $generatedByName ? "Emitido por {$generatedByName}." : '' }} Esta llamada de atención se basa en el resultado REAL de la semana evaluada, no en el dato actual del sistema.</div>

</body>
</html>

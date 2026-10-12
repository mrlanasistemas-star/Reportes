<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
@php
  $banner = match ($kind) {
      'success' => ['bg' => '#dcfce7', 'border' => '#bbf7d0', 'color' => '#15803d', 'text' => '✓  El reporte se generó correctamente'],
      'blocked' => ['bg' => '#fef3c7', 'border' => '#fde68a', 'color' => '#b45309', 'text' => '!  Faltan datos obligatorios para generar el reporte'],
      default   => ['bg' => '#fee2e2', 'border' => '#fecaca', 'color' => '#b91c1c', 'text' => '×  Se produjo un error durante la generación'],
  };
  $cta = match ($kind) {
      'success' => 'Consultar y descargar',
      'blocked' => 'Revisar incidencias',
      default   => 'Ver detalle',
  };
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.08);">

      <tr>
        <td style="background:#0f172a;padding:28px 36px;">
          <p style="margin:0 0 6px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Sistema Reportes</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">{{ $subject }}</h1>
        </td>
      </tr>

      <tr>
        <td style="background:{{ $banner['bg'] }};padding:14px 36px;border-bottom:1px solid {{ $banner['border'] }};">
          <p style="margin:0;color:{{ $banner['color'] }};font-size:13px;font-weight:700;">{{ $banner['text'] }}</p>
        </td>
      </tr>

      <tr>
        <td style="padding:36px 36px 28px;">
          <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.7;">
            Hola{{ $user?->name ? ', ' . $user->name : '' }},
          </p>
          <p style="margin:0 0 28px;color:#334155;font-size:15px;line-height:1.7;">
            @if($kind === 'success')
              Tu reporte de <strong style="color:#0f172a;">Efectividad de Cobranza Aprobada</strong> está listo. Puedes consultarlo y descargar el Excel desde el módulo de Reportes (requiere iniciar sesión).
            @elseif($kind === 'blocked')
              No se pudo generar el reporte de <strong style="color:#0f172a;">Efectividad de Cobranza Aprobada</strong> porque faltan datos obligatorios. Revisa las incidencias y corrige la información de origen.
            @else
              No fue posible completar el reporte de <strong style="color:#0f172a;">Efectividad de Cobranza Aprobada</strong> por un error técnico. El equipo de sistemas puede consultar el detalle en los registros del servidor.
            @endif
          </p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:28px;">
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:40%;border-bottom:1px solid #e2e8f0;">Reporte</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;border-bottom:1px solid #e2e8f0;">Efectividad de Cobranza Aprobada</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Identificador</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;border-bottom:1px solid #e2e8f0;">#{{ $runId }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Periodo</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;border-bottom:1px solid #e2e8f0;">{{ $periodLabel }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Sucursal</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $branchLabel }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Solicitado</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $requestedAt ?? '—' }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Finalizado</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $finishedAt ?? '—' }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Estado</td>
              <td style="padding:12px 18px;font-size:13px;color:{{ $banner['color'] }};font-weight:700;">{{ $statusLabel }}</td>
            </tr>
          </table>

          @if($kind === 'blocked' && count($issues))
            <p style="margin:0 0 10px;color:#0f172a;font-size:14px;font-weight:700;">Motivo</p>
            <ul style="margin:0 0 28px;padding-left:18px;color:#334155;font-size:14px;line-height:1.6;">
              @foreach($issues as $issue)
                <li style="margin-bottom:10px;">
                  {{ $issue['message'] }}
                  @if($issue['column'])<br><span style="color:#64748b;font-size:12px;">Columna / fuente: {{ $issue['column'] }}@if($issue['file']) — Archivo: {{ $issue['file'] }}@endif</span>@endif
                  @if($issue['action'])<br><span style="color:#64748b;font-size:12px;">Acción recomendada: {{ $issue['action'] }}</span>@endif
                </li>
              @endforeach
            </ul>
          @elseif($kind === 'blocked' && $reason)
            <p style="margin:0 0 28px;color:#334155;font-size:14px;line-height:1.6;"><strong>Motivo:</strong> {{ $reason }}</p>
          @endif

          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 8px;">
            <tr><td style="border-radius:12px;background:#0f172a;">
              <a href="{{ $detailUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">{{ $cta }}</a>
            </td></tr>
          </table>
          <p style="margin:12px 0 0;color:#94a3b8;font-size:12px;text-align:center;">El enlace abre el módulo protegido de Reportes; se te pedirá iniciar sesión si no la tienes abierta.</p>
        </td>
      </tr>

      <tr>
        <td style="background:#f8fafc;padding:18px 36px;border-top:1px solid #e2e8f0;">
          <p style="margin:0;color:#94a3b8;font-size:11px;line-height:1.6;">Correo automático del Sistema Reportes. No respondas a este mensaje.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tu acceso a Reportes MR LANA está listo</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.08);">

      <!-- Header -->
      <tr>
        <td style="background:#0f172a;padding:28px 36px;">
          <p style="margin:0 0 6px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Reportes MR LANA</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">Tu acceso está listo</h1>
        </td>
      </tr>

      <!-- Status banner -->
      <tr>
        <td style="background:#dbeafe;padding:14px 36px;border-bottom:1px solid #bfdbfe;">
          <p style="margin:0;color:#1d4ed8;font-size:13px;font-weight:700;">✓ &nbsp;Se creó una cuenta para ti en Reportes MR LANA</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 36px 28px;">
          <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.7;">
            Hola{{ $user->name ? ', ' . $user->name : '' }},
          </p>
          <p style="margin:0 0 28px;color:#334155;font-size:15px;line-height:1.7;">
            Un administrador creó una cuenta para ti en <strong style="color:#0f172a;">Reportes MR LANA</strong>.
            Para entrar por primera vez necesitas crear tu propia contraseña — da clic en el botón de abajo.
          </p>

          <!-- Info table -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:28px;">
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:40%;">Correo de acceso</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;">{{ $user->email }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Enlace</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">Válido por tiempo limitado y de un solo uso</td>
            </tr>
          </table>

          <!-- CTA -->
          <table role="presentation" cellpadding="0" cellspacing="0">
            <tr>
              <td style="background:#4f46e5;border-radius:12px;padding:14px 28px;">
                <a href="{{ $setPasswordUrl }}" style="color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;">
                  Crear mi contraseña →
                </a>
              </td>
            </tr>
          </table>

          <p style="margin:20px 0 0;color:#94a3b8;font-size:13px;line-height:1.6;">
            Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
            <a href="{{ $setPasswordUrl }}" style="color:#4f46e5;text-decoration:none;word-break:break-all;">{{ $setPasswordUrl }}</a>
          </p>
          <p style="margin:16px 0 0;color:#94a3b8;font-size:13px;line-height:1.6;">
            Por tu seguridad, nunca te enviamos tu contraseña por correo. Si no esperabas este mensaje, ignóralo.
          </p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 36px;text-align:center;">
          <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">Este correo fue generado automáticamente por Reportes MR LANA.<br>No respondas a este mensaje.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>

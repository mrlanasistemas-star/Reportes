<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Invitación a Reportes MR LANA</title>
<style>
  @media only screen and (max-width:620px) {
    .px { padding-left:20px !important; padding-right:20px !important; }
    .h1 { font-size:20px !important; }
    .btn a { display:block !important; text-align:center !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Establece tu contraseña para entrar a Reportes MR LANA. El enlace vence en {{ $expiresLabel }}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 4px 28px rgba(15,23,42,.08);">

      <tr>
        <td class="px" style="background:#0f172a;background-image:linear-gradient(135deg,#0f172a,#312e81);padding:28px 36px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;">
              <span style="display:inline-block;background:#ffffff;color:#0f172a;font-weight:900;font-size:13px;letter-spacing:.08em;border-radius:10px;padding:8px 10px;">MR LANA</span>
            </td>
            <td align="right" style="vertical-align:middle;color:#c7d2fe;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Reportes</td>
          </tr></table>
          <h1 class="h1" style="margin:18px 0 0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">Te damos la bienvenida a Reportes MR LANA</h1>
        </td>
      </tr>

      <tr>
        <td class="px" style="padding:32px 36px 8px;">
          <p style="margin:0 0 16px;color:#334155;font-size:15px;line-height:1.7;">Hola{{ $user->name ? ', ' . $user->name : '' }}:</p>
          <p style="margin:0 0 24px;color:#334155;font-size:15px;line-height:1.7;">
            Un administrador te dio acceso a <strong style="color:#0f172a;">Reportes MR LANA</strong>, el sistema de reportería de la empresa.
            Para entrar por primera vez, establece tu propia contraseña con el botón de abajo.
          </p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:24px;">
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:42%;border-bottom:1px solid #e2e8f0;">Tu usuario (correo)</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;border-bottom:1px solid #e2e8f0;word-break:break-all;">{{ $user->email }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Vigencia del enlace</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;"><strong>{{ $expiresLabel }}</strong>, un solo uso</td>
            </tr>
          </table>

          <table role="presentation" cellpadding="0" cellspacing="0" class="btn" width="100%"><tr><td>
            <a href="{{ $setPasswordUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;font-size:15px;font-weight:800;text-decoration:none;border-radius:12px;padding:14px 28px;">Establecer mi contraseña</a>
          </td></tr></table>

          <p style="margin:24px 0 8px;color:#0f172a;font-size:14px;font-weight:800;">Cómo entrar</p>
          <ol style="margin:0 0 20px;padding-left:20px;color:#334155;font-size:14px;line-height:1.8;">
            <li>Da clic en <strong>Establecer mi contraseña</strong> y escribe una contraseña segura (mínimo 8 caracteres).</li>
            <li>Entra a <a href="{{ $loginUrl }}" style="color:#4f46e5;text-decoration:none;">{{ $loginUrl }}</a> con tu correo y la contraseña que elegiste.</li>
            <li>Si el enlace ya venció, en la pantalla de inicio usa <strong>¿Olvidaste tu contraseña?</strong> o pide a un administrador que te reenvíe la invitación.</li>
          </ol>

          <p style="margin:0 0 8px;color:#94a3b8;font-size:12px;line-height:1.6;">
            Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
            <a href="{{ $setPasswordUrl }}" style="color:#4f46e5;text-decoration:none;word-break:break-all;">{{ $setPasswordUrl }}</a>
          </p>
          <p style="margin:0 0 24px;color:#94a3b8;font-size:12px;line-height:1.6;">
            Por tu seguridad, nunca te enviaremos tu contraseña por correo. Si no esperabas esta invitación, ignora este mensaje.
          </p>
        </td>
      </tr>

      <tr>
        <td class="px" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 36px;text-align:center;">
          <p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">
            ¿Necesitas ayuda? Escribe al área de soporte
            @if ($supportEmail)
              (<a href="mailto:{{ $supportEmail }}" style="color:#4f46e5;text-decoration:none;">{{ $supportEmail }}</a>)
            @endif
            o contacta al administrador que te dio de alta.
          </p>
          <p style="margin:8px 0 0;color:#94a3b8;font-size:11px;">Correo generado automáticamente por Reportes MR LANA.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>

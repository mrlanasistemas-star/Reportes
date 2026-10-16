<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Acceso a Reportes MR LANA</title>
<style>
  @media only screen and (max-width:620px) {
    .px { padding-left:20px !important; padding-right:20px !important; }
    .h1 { font-size:20px !important; }
    .btn a { display:block !important; text-align:center !important; }
    .cred { font-size:18px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Tus accesos a Reportes MR LANA ya están listos. Dentro encontrarás tu usuario y tu contraseña temporal.</div>
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
          <p style="margin:0 0 16px;color:#334155;font-size:15px;line-height:1.7;">Estimado(a){{ $user->name ? ' ' . $user->name : '' }}:</p>
          <p style="margin:0 0 24px;color:#334155;font-size:15px;line-height:1.7;">
            Le damos acceso a <strong style="color:#0f172a;">Reportes MR LANA</strong>, el sistema de reportería de la empresa.
            A continuación encontrará sus credenciales de acceso para ingresar por primera vez.
          </p>

          @if (!empty($temporaryPassword))
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:8px;">
            <tr>
              <td style="padding:16px 20px;border-bottom:1px solid #e2e8f0;">
                <div style="font-size:12px;color:#64748b;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:4px;">Usuario (correo)</div>
                <div style="font-size:15px;color:#0f172a;font-weight:700;word-break:break-all;">{{ $user->email }}</div>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 20px;">
                <div style="font-size:12px;color:#64748b;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;">Contraseña temporal</div>
                <div class="cred" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:20px;letter-spacing:.06em;color:#0f172a;font-weight:800;background:#eef2ff;border:1px dashed #c7d2fe;border-radius:10px;padding:12px 16px;display:inline-block;">{{ $temporaryPassword }}</div>
              </td>
            </tr>
          </table>
          @else
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:8px;">
            <tr>
              <td style="padding:16px 20px;">
                <div style="font-size:12px;color:#64748b;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:4px;">Usuario (correo)</div>
                <div style="font-size:15px;color:#0f172a;font-weight:700;word-break:break-all;">{{ $user->email }}</div>
                <p style="margin:12px 0 0;color:#334155;font-size:14px;line-height:1.6;">Su contraseña temporal le será entregada por el administrador que le dio de alta.</p>
              </td>
            </tr>
          </table>
          @endif

          <table role="presentation" cellpadding="0" cellspacing="0" class="btn" width="100%"><tr><td style="padding-top:20px;">
            <a href="{{ $loginUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;font-size:15px;font-weight:800;text-decoration:none;border-radius:12px;padding:14px 28px;">Entrar a Reportes MR LANA</a>
          </td></tr></table>

          <p style="margin:24px 0 8px;color:#0f172a;font-size:14px;font-weight:800;">Cómo entrar</p>
          <ol style="margin:0 0 20px;padding-left:20px;color:#334155;font-size:14px;line-height:1.8;">
            <li>Ingrese a <a href="{{ $loginUrl }}" style="color:#4f46e5;text-decoration:none;">{{ $loginUrl }}</a> con el usuario y la contraseña temporal de arriba.</li>
            <li>Una vez dentro, cambie su contraseña en <strong>Configuración&nbsp;›&nbsp;Seguridad</strong> por una propia que solo usted conozca.</li>
          </ol>

          <p style="margin:0 0 24px;color:#64748b;font-size:13px;line-height:1.6;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;">
            Por su seguridad, cambie la contraseña temporal en cuanto ingrese y no comparta estas credenciales con nadie.
          </p>
        </td>
      </tr>

      <tr>
        <td class="px" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 36px;text-align:center;">
          <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">Productos y Servicios Mr. Lana, S.A.P.I. de C.V.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>

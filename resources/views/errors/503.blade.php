{{--
    Pantalla de mantenimiento (php artisan down → HTTP 503).
    Autocontenida a propósito: no usa Vite ni el layout de la app, porque durante una
    actualización los assets compilados pueden estar a medio generarse. Solo usa el logo
    de public/ y CSS en línea. Se recarga sola cada 30 s para volver en cuanto termine.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="30">
    <title>Mantenimiento en progreso · MR LANA Reportes</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root {
            --bg: #f4f7f5; --card: #ffffff; --fg: #12211b; --muted: #5b6b65; --line: #dbe4e0;
            --brand: #047857; --brand-2: #10b981; --soft: #e3f3ec;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0c1311; --card: #131d1a; --fg: #e4ece9; --muted: #9db0a9; --line: #24332e; --brand: #34c99a; --brand-2: #10b981; --soft: #123129; }
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: "Montserrat", "Segoe UI", system-ui, -apple-system, sans-serif;
            background:
                radial-gradient(1100px 600px at 10% -10%, color-mix(in srgb, var(--brand) 18%, transparent), transparent 60%),
                radial-gradient(900px 500px at 110% 110%, color-mix(in srgb, var(--brand-2) 16%, transparent), transparent 60%),
                var(--bg);
            color: var(--fg);
            display: grid; place-items: center;
            padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
        }
        .card {
            width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line);
            border-radius: 24px; padding: 40px 32px; text-align: center;
            box-shadow: 0 20px 60px -20px rgba(4, 120, 87, .25);
        }
        .logo { height: 56px; width: auto; margin: 0 auto 28px; display: block; }
        .gear { width: 84px; height: 84px; margin: 0 auto 24px; position: relative; }
        .gear svg { width: 100%; height: 100%; color: var(--brand); animation: spin 6s linear infinite; }
        .gear::after {
            content: ""; position: absolute; inset: -10px; border-radius: 50%;
            border: 2px dashed color-mix(in srgb, var(--brand) 35%, transparent); animation: spin 14s linear infinite reverse;
        }
        h1 { font-size: 24px; line-height: 1.25; margin: 0 0 10px; font-weight: 800; }
        p { margin: 0 auto; color: var(--muted); font-size: 15px; line-height: 1.6; max-width: 40ch; }
        .bar { height: 6px; border-radius: 99px; background: var(--soft); overflow: hidden; margin: 28px auto 14px; max-width: 280px; }
        .bar i { display: block; height: 100%; width: 40%; border-radius: 99px; background: linear-gradient(90deg, var(--brand), var(--brand-2)); animation: slide 1.6s ease-in-out infinite; }
        .hint { font-size: 13px; color: var(--muted); }
        .pill { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--brand); background: var(--soft); padding: 6px 12px; border-radius: 99px; margin-bottom: 18px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand); animation: pulse 1.4s ease-in-out infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes slide { 0% { transform: translateX(-110%); } 100% { transform: translateX(260%); } }
        @keyframes pulse { 50% { opacity: .35; } }
        @media (prefers-reduced-motion: reduce) { .gear svg, .gear::after, .bar i, .dot { animation: none; } }
    </style>
</head>
<body>
    <main class="card" role="main" aria-live="polite">
        <img class="logo" src="/logo-mr-lana.png" alt="MR LANA">
        <span class="pill"><span class="dot" aria-hidden="true"></span>Mantenimiento en progreso</span>
        <div class="gear" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>
            </svg>
        </div>
        <h1>Estamos mejorando el sistema</h1>
        <p>Reportes MR LANA se está actualizando para que funcione mejor. Tu información está segura y en unos minutos todo volverá a estar disponible.</p>
        <div class="bar" aria-hidden="true"><i></i></div>
        <div class="hint">Esta página se actualiza sola. No necesitas hacer nada.</div>
    </main>
</body>
</html>

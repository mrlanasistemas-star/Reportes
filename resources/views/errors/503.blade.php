{{--
    Pantalla de mantenimiento (503). Se muestra con `php artisan down` en la web y en la
    PWA instalada. Es autocontenida: sin Vite, sin fuentes externas ni JavaScript de la
    app, para que funcione aunque el build esté a medio desplegar. Solo usa el logo de
    public/. Misma experiencia que MR. LANA PEOPLE (capacitaciones), con la paleta verde
    de Reportes.

    Recomendado: `php artisan down --render="errors::503" --retry=60`
    (pre-renderiza esta vista y no toca la base de datos mientras dure).
    Una visita Inertia (X-Inertia) recibe un 409 de ubicación y recarga esta página
    completa (bootstrap/app.php).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#063b2c">
    <meta http-equiv="refresh" content="60">
    <title>En mantenimiento · MR LANA Reportes</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root {
            --profundo: #063b2c;
            --medio: #047857;
            --vivo: #10b981;
            --menta: #a7f3d0;
            --crema: #f3faf7;
            --texto-suave: #cde9de;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: "Montserrat", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--crema);
            background: radial-gradient(120% 90% at 50% 0%, var(--medio) 0%, var(--profundo) 58%, #03241b 100%);
            display: grid;
            place-items: center;
            overflow: hidden;
            padding: max(24px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
        }
        .fondo { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
        .burbuja {
            position: absolute; bottom: -120px; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(167, 243, 208, .35), rgba(16, 185, 129, .08));
            animation: subir linear infinite;
        }
        .burbuja:nth-child(1) { left: 8%;  width: 70px;  height: 70px;  animation-duration: 16s; }
        .burbuja:nth-child(2) { left: 26%; width: 34px;  height: 34px;  animation-duration: 11s; animation-delay: -4s; }
        .burbuja:nth-child(3) { left: 48%; width: 110px; height: 110px; animation-duration: 21s; animation-delay: -9s; }
        .burbuja:nth-child(4) { left: 68%; width: 46px;  height: 46px;  animation-duration: 13s; animation-delay: -2s; }
        .burbuja:nth-child(5) { left: 84%; width: 82px;  height: 82px;  animation-duration: 18s; animation-delay: -12s; }
        @keyframes subir {
            0%   { transform: translateY(0) scale(.9); opacity: 0; }
            10%  { opacity: 1; }
            100% { transform: translateY(-120vh) scale(1.1); opacity: 0; }
        }

        main {
            position: relative; z-index: 1; width: 100%; max-width: 40rem; text-align: center;
            display: grid; gap: 1.1rem; justify-items: center;
            animation: aparecer .8s cubic-bezier(.2, .8, .2, 1) both;
        }
        @keyframes aparecer { from { transform: translateY(18px) scale(.98); } to { transform: none; } }

        .escena { position: relative; width: min(78vw, 300px); height: min(78vw, 300px); display: grid; place-items: center; }
        .halo {
            position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid rgba(167, 243, 208, .28);
            animation: halo 2.8s ease-out infinite;
        }
        .halo.dos { animation-delay: 1.4s; }
        @keyframes halo { from { transform: scale(.7); opacity: .9; } to { transform: scale(1.35); opacity: 0; } }
        .disco {
            width: 72%; height: 72%; border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 18px 40px rgba(0, 0, 0, .35), inset 0 0 0 4px rgba(16, 185, 129, .35);
            display: grid; place-items: center;
            animation: flotar 4s ease-in-out infinite;
        }
        .disco img { width: 64%; height: auto; animation: latir 2.4s ease-in-out infinite; }
        @keyframes latir { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.06); } }
        @keyframes flotar { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        .engrane {
            position: absolute; right: 0; bottom: 4%; width: 26%; height: 26%;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, .35));
            animation: girar 6s linear infinite;
        }
        .engrane.chico { right: auto; left: 4%; bottom: auto; top: 8%; width: 17%; height: 17%; animation-direction: reverse; animation-duration: 4s; }
        @keyframes girar { to { transform: rotate(360deg); } }
        .grafica {
            position: absolute; left: 2%; bottom: 10%; width: 24%; height: 24%;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, .3));
            animation: flotar 5s ease-in-out infinite reverse;
        }
        .grafica rect { transform-origin: bottom; transform-box: fill-box; animation: crecer 2.2s ease-in-out infinite; }
        .grafica rect:nth-child(2) { animation-delay: .3s; }
        .grafica rect:nth-child(3) { animation-delay: .6s; }
        @keyframes crecer { 0%, 100% { transform: scaleY(.55); } 50% { transform: scaleY(1); } }

        .etiqueta {
            font-size: .72rem; letter-spacing: .18em; text-transform: uppercase; font-weight: 700;
            color: var(--menta); background: rgba(16, 185, 129, .18); border: 1px solid rgba(167, 243, 208, .25);
            padding: .35rem .8rem; border-radius: 999px;
        }
        h1 { margin: 0; font-size: clamp(2rem, 7vw, 3.2rem); line-height: 1.15; font-weight: 800; text-wrap: balance; }
        p { margin: 0; color: var(--texto-suave); line-height: 1.6; max-width: 32rem; font-size: clamp(1rem, 3.6vw, 1.2rem); }

        .barra { width: min(100%, 24rem); height: 8px; border-radius: 999px; background: rgba(255, 255, 255, .12); overflow: hidden; }
        .barra span {
            display: block; height: 100%; width: 40%; border-radius: inherit;
            background: linear-gradient(90deg, var(--vivo), var(--menta));
            animation: cargar 1.8s ease-in-out infinite;
        }
        @keyframes cargar { 0% { transform: translateX(-110%); } 100% { transform: translateX(260%); } }

        .boton {
            margin-top: .3rem; appearance: none; border: 0; cursor: pointer;
            font: inherit; font-weight: 700; color: var(--profundo);
            background: linear-gradient(180deg, var(--menta), #6ee7b7);
            padding: 1rem 2.2rem; border-radius: 999px; min-height: 52px; font-size: 1.05rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .25);
            transition: transform .15s ease, box-shadow .15s ease;
            text-decoration: none; display: inline-block;
        }
        .boton:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(0, 0, 0, .3); }
        .boton:active { transform: translateY(0) scale(.98); }
        .boton:focus-visible { outline: 3px solid var(--menta); outline-offset: 3px; }
        .nota { font-size: .8rem; color: rgba(205, 233, 222, .75); }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .burbuja { display: none; }
        }
    </style>
</head>
<body>
    <div class="fondo" aria-hidden="true">
        <span class="burbuja"></span><span class="burbuja"></span><span class="burbuja"></span><span class="burbuja"></span><span class="burbuja"></span>
    </div>

    <main role="main" aria-live="polite">
        <div class="escena" aria-hidden="true">
            <span class="halo"></span><span class="halo dos"></span>
            <div class="disco">
                <img src="/logoMrLana.png" alt="" width="256" height="256">
            </div>
            <svg class="engrane" viewBox="0 0 24 24"><path fill="#A7F3D0" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
            <svg class="engrane chico" viewBox="0 0 24 24"><path fill="#10B981" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
            <svg class="grafica" viewBox="0 0 24 24"><rect x="3" y="10" width="4.5" height="11" rx="1.2" fill="#6EE7B7"/><rect x="9.75" y="5" width="4.5" height="16" rx="1.2" fill="#A7F3D0"/><rect x="16.5" y="12" width="4.5" height="9" rx="1.2" fill="#10B981"/></svg>
        </div>

        <span class="etiqueta">MR LANA · Reportes</span>
        <h1>Estamos mejorando el sistema</h1>
        <p>Estamos aplicando una actualización para que tus reportes funcionen mejor. Vuelve en unos minutos; tu información está segura.</p>
        <div class="barra" aria-hidden="true"><span></span></div>
        <a class="boton" href="{{ url()->current() }}">Volver a intentar</a>
        <p class="nota">Esta página se actualiza sola cada minuto.</p>
    </main>
</body>
</html>

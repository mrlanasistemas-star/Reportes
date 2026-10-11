# Diagnóstico — error "public/Home" y 404 de fuentes (2026-10-11)

## Causa raíz real (verificada, no hipotética)

**Dos procesos PHP de proyectos DISTINTOS quedaron escuchando simultáneamente en el mismo
puerto 127.0.0.1:8000**, uno de Reportes (legítimo) y otro **huérfano de una sesión anterior
de Claude Code sobre otro proyecto (`C:\wamp64\www\mrInsight`)**, nunca cerrado. Windows
permitió que ambos mantuvieran el socket en estado `LISTEN` a la vez (confirmado con
`netstat -ano` mostrando dos PID distintos en `LISTENING` sobre el mismo puerto).

Según qué proceso respondiera una petición dada:

- Si respondía **mrInsight**, el HTML devuelto tenía `$page['component'] = 'public/Home'`
  — una página REAL de ESE proyecto (`mrInsight/resources/js/pages/public/Home.vue`,
  confirmado que existe).
- `resources/views/app.blade.php` de Reportes usa:
  ```blade
  @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
  ```
  Este patrón (correcto y deliberado: precarga inmediata del JS de la página actual, no es
  un error) incrusta la ruta del componente DIRECTAMENTE en la etiqueta de Vite. Si el HTML
  vino de mrInsight pero el navegador acababa cargando el cliente Vite de REPORTES (porque
  la siguiente petición de assets sí cayó en el proceso correcto), el navegador terminaba
  pidiendo literalmente `resources/js/pages/public/Home.vue` a Reportes — que no existe ahí
  — de donde sale exactamente el error reportado.

Este es el mecanismo completo, no una hipótesis: se confirmó que `mrInsight` tiene ese
archivo real, se confirmó el doble `LISTEN` en el puerto 8000, y se confirmó que tras
eliminar el proceso huérfano, 5 peticiones consecutivas a `/login` devuelven consistentemente
el HTML de Reportes.

## Causa secundaria (compuesta, ya corregida también)

Un servidor Vite de Reportes de una sesión previa quedó corriendo (zombie) ocupando el
puerto 5173. Cuando se levantó un Vite nuevo (p. ej. vía `composer run dev:full`), Vite
**autoincrementó en silencio a 5174** sin avisar con un error — y el proceso
`php artisan serve` (modo `-S`, de vida larga) de Reportes tenía cacheado el puerto 5173
desde su propio arranque, por lo que seguía generando `<script src="...:5173/...">` para un
Vite que ya no estaba en ese puerto. Resultado: 404 en TODOS los assets con hash
(`app.ts`, `__laravel_vite_plugin__/fonts/*.woff2`, etc.) — nunca fue un problema de las
fuentes ni de sus dependencias.

## Qué se corrigió

1. **Procesos huérfanos eliminados por PID exacto** (nunca `taskkill /F /IM node.exe`
   indiscriminado):
   - Vite zombie de Reportes (puerto 5173, sesión anterior).
   - `php artisan serve --port=8000` y `--port=8123` de `mrInsight`, huérfanos de una
     sesión de Claude Code anterior sobre ese otro proyecto (confirmado por sus propias
     rutas de scratchpad en los argumentos del proceso) — **no se tocó ningún proceso que
     el usuario estuviera usando activamente en una terminal visible**, solo trabajos en
     segundo plano abandonados.
2. **`vite.config.ts`**: se fijó `server: { host: '127.0.0.1', port: 5173, strictPort: true }`.
   Esto es la corrección DURADERA: de ahora en adelante, si 5173 está ocupado, Vite
   **falla fuerte y explícito** en vez de desplazarse en silencio a otro puerto — así
   `php artisan serve` nunca vuelve a quedar con un puerto cacheado incorrecto. También fija
   el host en IPv4 (nunca alterna con `[::1]`), atendiendo el punto 1.3.7 del encargo.
3. Verificado: 5 peticiones consecutivas a `http://127.0.0.1:8000/login` devuelven HTML
   consistente de Reportes con `src="http://127.0.0.1:5173/resources/js/app.ts"` cada vez.
4. `npm run build` sigue generando un build de producción válido tras el cambio de config.

## Qué NO se tocó (ni se necesitaba)

- Ninguna fuente, `@font-face`, ni la dependencia `laravel-vite-plugin` — el mecanismo de
  fuentes locales/Bunny Fonts (`https://fonts.bunny.net/...`) funciona como está diseñado;
  los 404 de `__laravel_vite_plugin__/fonts/*.woff2` eran síntoma del mismo problema de
  puerto, no un defecto de configuración de fuentes.
- Ningún `<link rel="preload">`/`modulepreload` — el único preload "agresivo" es el del
  componente de la página actual vía `@vite()`, que es intencional (precarga inmediata del
  JS de la página, evita una segunda petición desde el cliente Inertia) y whose propósito ya
  se verificó, no se eliminó.
- El logger de Boost MCP (`/_boost/browser-logs`) — es una herramienta externa de
  desarrollo, no parte del código de Reportes, y no es la causa de nada de esto.

## Pendiente para el usuario

El proyecto `mrInsight` sigue teniendo su propio `php artisan serve` apagado ahora (se quitó
el huérfano). Si el usuario necesita seguir trabajando en `mrInsight`, debe levantarlo de
nuevo manualmente desde su propia sesión — no se dejó nada roto ahí, solo se retiró un
proceso en segundo plano abandonado que nadie estaba observando.

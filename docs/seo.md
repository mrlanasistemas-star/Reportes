# SEO — Reportes Mr Lana

Objetivo: que al buscar "reportes mr lana", "reportes mrlana", "mr lana reportes"
o "sistema de reportes mr lana" aparezca la página de inicio de sesión.

## Qué ya hace el código

- **Páginas indexables:** solo `/login` y `/forgot-password` (`config/seo.php`, clave
  `public_pages`). Llevan `index, follow`, canonical, Open Graph, Twitter card y
  JSON-LD (`Organization` "Mr Lana" + `WebSite`/`WebPage` "Reportes Mr Lana").
- **Todo lo demás** (páginas con sesión, API, documentación, descargas PDF/Excel,
  `/up`, 404, redirecciones) lleva `noindex, nofollow` en el HTML y en la cabecera
  `X-Robots-Tag` (middleware global `App\Http\Middleware\AddRobotsHeader`).
- Las metas se pintan **en el servidor** (`resources/views/app.blade.php`, con los
  datos de `App\Support\Seo`). El login también tiene un bloque `<noscript>` con un
  `<h1>` y un texto, para los buscadores que no ejecutan JavaScript.
- `/robots.txt` y `/sitemap.xml` se generan por ruta (`SeoController`). Las URLs
  absolutas salen de `APP_URL`.
- `public/manifest.webmanifest` usa el nombre "Reportes Mr Lana".

## Requisitos en el servidor (VPS)

1. **`APP_URL` debe ser el dominio real con https**, por ejemplo
   `APP_URL=https://reportes.mrlana.com`. Si se queda como `http://localhost:8000`,
   el canonical, el sitemap y el robots.txt apuntarán a localhost y Google no
   indexará nada. Después de cambiarlo, corre `php artisan config:cache`.
2. **HTTPS obligatorio** (certificado válido, por ejemplo Let's Encrypt) y
   redirección de http a https.
3. **Dominio propio**, siempre el mismo. No publiques el sitio también en una IP
   o en otro subdominio (contenido duplicado).
4. No debe existir un archivo `public/robots.txt`: taparía la ruta dinámica.

## Lo que tienes que hacer tú (no se puede desde el código)

1. **Google Search Console** (https://search.google.com/search-console):
   - Agrega la propiedad con el prefijo de URL exacto de `APP_URL`.
   - Elige verificar con **etiqueta HTML**. Copia solo el valor de `content` y
     ponlo en `.env`: `GOOGLE_SITE_VERIFICATION=el_codigo`. Luego corre
     `php artisan config:cache`. La meta `google-site-verification` aparecerá
     sola en el HTML; después pulsa "Verificar".
2. **Envía el sitemap:** en Search Console, Sitemaps, escribe `sitemap.xml`.
3. **Solicita la indexación:** en Inspección de URLs, pega la URL de `/login` y
   pulsa "Solicitar indexación".
4. **Google Business Profile** (https://business.google.com) con el mismo nombre
   "Mr Lana" y el enlace al dominio. Así Google relaciona la marca con el sitio.
5. **Enlaces externos:** pon el enlace al sistema donde sea posible, por ejemplo
   en el sitio principal de Mr Lana o en sus redes sociales, con el texto
   "Reportes Mr Lana".
6. Opcional: da de alta el sitio también en Bing Webmaster Tools (puede importar
   los datos de Search Console).

## Expectativas

- Google tarda **días o semanas** en rastrear e indexar un sitio nuevo. Que salga
  el primero **no se puede garantizar**: depende de Google, de la competencia por
  esas palabras y de los enlaces que apunten al dominio.
- Solo se va a indexar el login. Es un sistema interno, así que no hay más
  contenido público que mostrar.
- Search Console, en Páginas, mostrará las rutas privadas como "Excluida por la
  etiqueta noindex" o "Bloqueada por robots.txt". Es lo esperado.

## SSR de Inertia (opcional, no está activado)

El login es una SPA: sin JavaScript, `#app` llega vacío. Google sí ejecuta
JavaScript, y las metas, el título y el `<noscript>` ya están en el HTML del
servidor, así que el SSR no hace falta para indexar. Activarlo
(`resources/js/ssr.ts`, `npm run build:ssr`, `php artisan inertia:start-ssr`)
exige un **proceso Node permanente** en el VPS (supervisor/systemd). Por eso no
se activa por defecto.

## Cambiar textos

Los títulos, las descripciones y los textos del `<noscript>` están en
`config/seo.php`. El nombre "Reportes Mr Lana" se forma con `APP_NAME` + "Mr Lana".
También se puede fijar con `SEO_APP_TITLE`, pero entonces hay que ajustar la regla
`brandTitle` de `resources/js/app.ts` para que coincida.

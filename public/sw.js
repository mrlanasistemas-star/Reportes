// Service worker mínimo — su único propósito es cumplir el requisito de
// instalabilidad (PWA) para poder ofrecer el botón "Descargar aplicación"
// y recibir notificaciones push (23-nov-2026).
// A propósito NO cachea nada: este proyecto se despliega seguido y una
// caché agresiva serviría versiones viejas del sitio.
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Passthrough intencional: deja que todas las peticiones vayan a red.
});

// ── Notificaciones push (Web Push + VAPID, ver docs/notificaciones-push.md) ──
// El servidor envía JSON: { title, body, icon, badge, tag, data: { url } }.
self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Reportería MR LANA';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/logoMrLana.png',
        badge: payload.badge || '/logoMrLana.png',
        tag: payload.tag || undefined,
        renotify: Boolean(payload.tag),
        lang: 'es-MX',
        data: { url: (payload.data && payload.data.url) || '/dashboard' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Clic en la notificación: enfoca una pestaña/app ya abierta del sitio y la
// lleva a la URL del aviso; si no hay ninguna, abre una nueva.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(
        (event.notification.data && event.notification.data.url) || '/dashboard',
        self.location.origin,
    );

    // Solo URLs del propio sitio.
    const url = target.origin === self.location.origin ? target.href : self.location.origin + '/dashboard';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                for (const client of clients) {
                    if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                        return client.focus().then((focused) =>
                            focused && 'navigate' in focused ? focused.navigate(url) : self.clients.openWindow(url),
                        );
                    }
                }

                return self.clients.openWindow(url);
            }),
    );
});

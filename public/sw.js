// Service worker mínimo — su único propósito es cumplir el requisito de
// instalabilidad (PWA) para poder ofrecer el botón "Descargar aplicación".
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

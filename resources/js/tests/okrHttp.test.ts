import { afterEach, describe, expect, it, vi } from 'vitest';
import { okrRequest } from '../lib/okrHttp';

// Prioridad 11: cada botón recibe un mensaje que dice qué pasó y qué hacer,
// nunca "Recarga la página" ni el texto crudo del framework.
function respond(status: number, body: unknown) {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc' });
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })));
}

afterEach(() => vi.unstubAllGlobals());

describe('okrRequest', () => {
    it('usa el mensaje de validación del backend (causa conocida)', async () => {
        respond(422, { message: 'x', errors: { activate: ['No puedes activar el OKR de David Gamboa Cano porque falta capturar su línea base.'] } });
        const r = await okrRequest('/okr/1/activate');
        expect(r.ok).toBe(false);
        expect(!r.ok && r.message).toBe('No puedes activar el OKR de David Gamboa Cano porque falta capturar su línea base.');
    });

    it('en error técnico muestra el mensaje con la referencia para Sistemas', async () => {
        respond(500, { message: 'No pudimos completar la operación por un error técnico. Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: ERR-ABCD1234.', reference: 'ERR-ABCD1234' });
        const r = await okrRequest('/okr/1');
        expect(!r.ok && r.message).toContain('ERR-ABCD1234');
    });

    it('respeta el 403 escrito para el usuario y nunca muestra "Recarga la página"', async () => {
        respond(403, { message: 'Tu rol no tiene permiso para activar OKR. Pide a un administrador que lo habilite.' });
        const forbidden = await okrRequest('/okr/1/activate');
        expect(!forbidden.ok && forbidden.message).toContain('Tu rol no tiene permiso');

        for (const status of [404, 405, 419]) {
            respond(status, { message: 'The route could not be found.' });
            const r = await okrRequest('/x');
            expect(!r.ok && r.message).not.toMatch(/recarga la página|could not be found/i);
        }
    });

    it('sin conexión explica que revise su red', async () => {
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal('fetch', vi.fn(async () => {
 throw new TypeError('network'); 
}));
        const r = await okrRequest('/okr');
        expect(r.status).toBe(0);
        expect(!r.ok && r.message).toContain('Revisa tu red');
    });

    it('una ruta no registrada en el servidor se explica con el método y la ruta exactos (4.4)', async () => {
        respond(404, { message: 'The route okr/2/activation-check could not be found.' });
        const r = await okrRequest('/okr/2/activation-check');
        expect(!r.ok && r.message).toContain('la ruta GET /okr/2/activation-check no está registrada');
        expect(!r.ok && r.message).not.toMatch(/could not be found/i);
    });

    it('un 404 con mensaje propio del backend (archivo faltante) se muestra tal cual', async () => {
        respond(404, { message: 'No encontramos el PDF de CC-2026-000004 en el almacenamiento. El registro y su folio se conservan.' });
        const r = await okrRequest('/okr/commitment-letters/4/download');
        expect(!r.ok && r.message).toContain('No encontramos el PDF de CC-2026-000004');
    });
});

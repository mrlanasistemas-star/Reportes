import { afterEach, describe, expect, it, vi } from 'vitest';
import { isDownloadUrl } from '../lib/downloadOverlay';

// La pantalla de carga solo debe aparecer en descargas reales de PDF/Excel/archivos,
// nunca en una navegación normal ni en "Ver" un reporte.
const ORIGIN = 'https://reportes.example.com';

function url(path: string): URL {
    return new URL(path, ORIGIN);
}

describe('isDownloadUrl', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('reconoce las descargas del sistema', () => {
        vi.stubGlobal('window', { location: { origin: ORIGIN, href: `${ORIGIN}/` } });

        for (const path of [
            '/reportes-mensuales/21/radiografia.xlsx',
            '/reportes-mensuales/21/radiografia.pdf',
            '/reportes-mensuales/21/export-filtrado.pdf?branch=3',
            '/reportes-mensuales/21/consolidado.csv',
            '/reportes-mensuales/runs/9/excel',
            '/reportes-mensuales/runs/9/pdf',
            '/historico-general/4/descargar',
            '/okr/evidences/7/download',
            '/guia-sistema/pdf',
            '/usuarios/contrasenas-masivas/descargar',
            '/usuarios/importar/credenciales',
            '/efectividad-cobranza/calculadora/abc/exportar/pdf',
        ]) {
            expect(isDownloadUrl(url(path)), path).toBe(true);
        }
    });

    it('ignora navegaciones normales y otros dominios', () => {
        vi.stubGlobal('window', { location: { origin: ORIGIN, href: `${ORIGIN}/` } });

        for (const path of ['/reportes-mensuales', '/reportes-mensuales/runs/9/ver', '/dashboard', '/usuarios?seccion=activos']) {
            expect(isDownloadUrl(url(path)), path).toBe(false);
        }

        expect(isDownloadUrl(new URL('https://otro.com/archivo.pdf'))).toBe(false);
    });
});

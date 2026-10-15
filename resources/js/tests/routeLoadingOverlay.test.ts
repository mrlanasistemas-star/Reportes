import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn() } }));
vi.mock('@/components/feedback/Toast', () => ({ toast: vi.fn() }));

const { isExcludedFromGlobalOverlay } = await import('../lib/routeLoadingOverlay');
const visit = (path: string, extra: Record<string, unknown> = {}) => ({ url: new URL(`http://x${path}`), method: 'get', ...extra });

// Prioridad 12: la pantalla completa solo cubre navegaciones; recargas parciales de
// una tarjeta, peticiones asíncronas, precargas y el módulo OKR usan su propio indicador.
describe('isExcludedFromGlobalOverlay', () => {
    it('una navegación normal sí muestra la pantalla de carga', () => {
        expect(isExcludedFromGlobalOverlay(visit('/reportes-mensuales'))).toBe(false);
    });

    it('recarga parcial (only), asíncrona o precarga no bloquean la aplicación', () => {
        expect(isExcludedFromGlobalOverlay(visit('/reportes-mensuales', { only: ['comparativo'] }))).toBe(true);
        expect(isExcludedFromGlobalOverlay(visit('/dashboard', { async: true }))).toBe(true);
        expect(isExcludedFromGlobalOverlay(visit('/dashboard', { prefetch: true }))).toBe(true);
    });

    it('el módulo OKR usa indicadores locales', () => {
        expect(isExcludedFromGlobalOverlay(visit('/okr/5/activate', { method: 'post' }))).toBe(true);
    });
});

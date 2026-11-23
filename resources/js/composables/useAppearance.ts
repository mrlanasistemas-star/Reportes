/**
 * Manejo del tema claro/oscuro de la aplicación.
 *
 * La preferencia del usuario ('light' | 'dark' | 'system') se guarda en
 * localStorage y en la cookie `appearance` (que el servidor lee para pintar la
 * primera carga sin parpadeo). Por defecto se usa modo claro.
 */
import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

/** Valor que devuelve useAppearance(). */
export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

/**
 * Aplica el tema al documento: agrega/quita la clase `dark` en <html> y ajusta
 * `color-scheme` para que los controles nativos usen el mismo tema.
 * @param value preferencia; 'system' se resuelve con prefers-color-scheme.
 */
export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    const dark =
        value === 'system'
            ? window.matchMedia('(prefers-color-scheme: dark)').matches
            : value === 'dark';

    document.documentElement.classList.toggle('dark', dark);
    // Controles nativos (scrollbars, selects, date pickers) en el mismo tema.
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}

/**
 * Guarda una cookie accesible en todo el sitio (path=/, SameSite=Lax).
 * @param days vigencia en días (1 año por defecto).
 */
const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

/** MediaQueryList de prefers-color-scheme: dark (null fuera del navegador). */
const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

/**
 * Lee la preferencia guardada (localStorage y, de respaldo, la cookie).
 * @returns 'light' | 'dark' | 'system', o null si no hay preferencia válida.
 */
const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    // Preferencia guardada: localStorage y, si se borró, la cookie que usa el
    // servidor para pintar la primera carga — así nunca se contradicen (antes,
    // sin localStorage, la página volvía a claro aunque la cookie dijera oscuro).
    let stored: string | null = null;

    try {
        stored = localStorage.getItem('appearance');
    } catch {
        stored = null;
    }

    stored ??=
        document.cookie.match(/(?:^|; )appearance=(light|dark|system)/)?.[1] ??
        null;

    return stored === 'light' || stored === 'dark' || stored === 'system'
        ? stored
        : null;
};

/** true si el sistema operativo/navegador prefiere modo oscuro. */
const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

/** Reaplica el tema cuando cambia la preferencia del sistema (relevante si el usuario eligió 'system'). */
const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'light');
};

/**
 * Aplica el tema guardado al cargar la página (se llama una vez desde app.ts)
 * y escucha cambios del tema del sistema.
 */
export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Fase 9 de la auditoría (15-oct-2026): modo claro por defecto — nunca
    // "system" para quien jamás eligió nada. Si ya hay una preferencia
    // guardada (claro, oscuro o "seguir sistema" elegido a propósito), esa
    // siempre gana.
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'light');

    // Escucha cambios del tema del sistema.
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

/** Estado compartido (singleton de módulo) con la preferencia actual. */
const appearance = ref<Appearance>('light');

/**
 * Composable para leer y cambiar la apariencia desde componentes (p. ej.
 * AppearanceTabs). Al montarse sincroniza el estado con la preferencia guardada.
 */
export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        const savedAppearance = getStoredAppearance();

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    /** Tema efectivo: resuelve 'system' a 'light' o 'dark'. */
    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    /** Cambia la preferencia, la persiste (localStorage + cookie) y la aplica. */
    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Persistencia del lado del cliente.
        // (puede fallar en ventana privada o con almacenamiento bloqueado: la cookie basta).
        try {
            localStorage.setItem('appearance', value);
        } catch {
            /* sin almacenamiento local */
        }

        // Cookie para que el servidor pinte la primera carga con el tema correcto.
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}

/** Tipos de navegación: migas de pan y elementos del menú. */
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

/** Miga de pan: título y destino (string o ruta Wayfinder). */
export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

/** Elemento del menú principal / barra inferior. */
export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /**
     * Roles permitidos para ver este ítem (cierre 04-oct-2026, Parte 1).
     * Omitido = visible para cualquier rol. Esto es solo UX — la
     * autorización real vive en el backend (EnsureModuleAccess,
     * EnsureOkrAccessEnabled, Gates), nunca únicamente aquí.
     */
    roles?: Array<'admin' | 'gerencial' | 'colaborador'>;
    /** Módulo configurable por rol (14-oct-2026) — visible solo si viene en auth.modules. */
    module?: string;
    /** Sección del menú lateral (Fase 8.4): agrupa los módulos para ubicarlos rápido. */
    group?: 'Operación' | 'Análisis' | 'Administración' | 'Ayuda y cuenta';
    /** Nombre corto para la barra inferior en móvil (evita textos cortados). */
    shortTitle?: string;
};

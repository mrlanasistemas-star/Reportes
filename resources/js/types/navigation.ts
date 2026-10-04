import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /**
     * Roles permitidos para ver este ítem (cierre 04-oct-2026, Parte 1).
     * Omitido = visible para cualquier rol. Esto es solo UX — la
     * autorización real vive en el backend (EnsureReporteriaAccess,
     * EnsureOkrAccessEnabled, Gates), nunca únicamente aquí.
     */
    roles?: Array<'admin' | 'gerencial' | 'colaborador'>;
};

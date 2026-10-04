import {
    BookOpen,
    CalendarRange,
    FileSpreadsheet,
    FolderOpen,
    LayoutGrid,
    Settings,
    ShieldCheck,
    Target,
    Users,
} from 'lucide-vue-next';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

// Fuente única de los módulos del sistema — la usan AppSidebar.vue (menú de
// escritorio) y MobileBottomNav.vue (barra inferior en móvil) para que no se
// desincronicen entre sí.
//
// `roles` (cierre 04-oct-2026, Parte 1) es solo UX — oculta lo que el backend
// ya rechazaría (EnsureReporteriaAccess: Reportería financiero global es
// admin/gerencial únicamente; "Usuarios y accesos" es admin únicamente, ver
// Gate 'okr.admin'). Nunca es la autorización real.
export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
        roles: ['admin', 'gerencial'],
    },
    {
        title: 'Carga de archivos',
        href: '/historico-general',
        icon: FolderOpen,
        roles: ['admin', 'gerencial'],
    },
    {
        title: 'Periodos',
        href: '/periodos',
        icon: CalendarRange,
        roles: ['admin', 'gerencial'],
    },
    {
        title: 'Colaboradores',
        href: '/asignaciones-empleado-sucursal',
        icon: Users,
        roles: ['admin', 'gerencial'],
    },
    {
        title: 'Reportes mensuales',
        href: '/reportes-mensuales',
        icon: FileSpreadsheet,
        roles: ['admin', 'gerencial'],
    },
    {
        title: 'OKR',
        href: '/okr',
        icon: Target,
    },
    {
        title: 'Guía del sistema',
        href: '/guia-sistema',
        icon: BookOpen,
    },
    {
        title: 'Configuración',
        href: '/settings/profile',
        icon: Settings,
    },
    {
        title: 'Usuarios y accesos',
        href: '/okr/responsibles',
        icon: ShieldCheck,
        roles: ['admin'],
    },
];

/** Filtra por rol — ver docstring de `roles` arriba. */
export function visibleMainNavItems(role: string | null | undefined): NavItem[] {
    return mainNavItems.filter((item) => !item.roles || item.roles.includes(role as never));
}

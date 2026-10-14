import {
    BookOpen,
    CalendarRange,
    FileSpreadsheet,
    FolderOpen,
    LayoutGrid,
    Settings,
    ShieldCheck,
    Target,
    TrendingUp,
    Users,
} from 'lucide-vue-next';
import { dashboard } from '@/routes';
import type { Auth, NavItem } from '@/types';

// Fuente única de los módulos del sistema — la usan AppSidebar.vue (menú de
// escritorio) y MobileBottomNav.vue (barra inferior en móvil) para que no se
// desincronicen entre sí.
//
// `module` (14-oct-2026) oculta lo que el backend ya rechazaría
// (EnsureModuleAccess): qué módulos ve cada rol se configura en "Usuarios y
// accesos" y llega como auth.modules. `roles` queda solo para "Usuarios y accesos"
// (admin, Gate 'okr.admin'). Nunca es la autorización real.
export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
        module: 'dashboard',
    },
    {
        title: 'Carga de archivos',
        href: '/historico-general',
        icon: FolderOpen,
        module: 'carga',
    },
    {
        title: 'Periodos',
        href: '/periodos',
        icon: CalendarRange,
        module: 'periodos',
    },
    {
        title: 'Colaboradores',
        href: '/asignaciones-empleado-sucursal',
        icon: Users,
        module: 'colaboradores',
    },
    {
        title: 'Reportes mensuales',
        href: '/reportes-mensuales',
        icon: FileSpreadsheet,
        module: 'reportes',
    },
    {
        title: 'Efectividad de Cobranza',
        href: '/efectividad-cobranza',
        icon: TrendingUp,
        module: 'efectividad',
    },
    {
        title: 'OKR',
        href: '/okr',
        icon: Target,
        module: 'okr',
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

/** Filtra por módulos permitidos y rol — ver docstring arriba. */
export function visibleMainNavItems(auth: Pick<Auth, 'user' | 'modules'> | null | undefined): NavItem[] {
    const role = auth?.user?.role;
    const modules = auth?.modules ?? [];

    return mainNavItems.filter(
        (item) => (!item.roles || item.roles.includes(role as never)) && (!item.module || modules.includes(item.module)),
    );
}

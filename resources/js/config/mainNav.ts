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
    UserCog,
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
        group: 'Análisis',
        href: dashboard(),
        icon: LayoutGrid,
        module: 'dashboard',
    },
    {
        title: 'Carga de archivos',
        shortTitle: 'Carga',
        group: 'Operación',
        href: '/historico-general',
        icon: FolderOpen,
        module: 'carga',
    },
    {
        title: 'Periodos',
        group: 'Operación',
        href: '/periodos',
        icon: CalendarRange,
        module: 'periodos',
    },
    {
        title: 'Colaboradores',
        group: 'Operación',
        href: '/asignaciones-empleado-sucursal',
        icon: Users,
        module: 'colaboradores',
    },
    {
        title: 'Reportes mensuales',
        shortTitle: 'Reportes',
        group: 'Análisis',
        href: '/reportes-mensuales',
        icon: FileSpreadsheet,
        module: 'reportes',
    },
    {
        title: 'Efectividad de Cobranza',
        shortTitle: 'Efectividad',
        group: 'Análisis',
        href: '/efectividad-cobranza',
        icon: TrendingUp,
        module: 'efectividad',
    },
    {
        title: 'OKR',
        group: 'Análisis',
        href: '/okr',
        icon: Target,
        module: 'okr',
    },
    {
        title: 'Guía del sistema',
        shortTitle: 'Guía',
        group: 'Ayuda y cuenta',
        href: '/guia-sistema',
        icon: BookOpen,
    },
    {
        title: 'Configuración',
        shortTitle: 'Ajustes',
        group: 'Ayuda y cuenta',
        href: '/settings/profile',
        icon: Settings,
    },
    // Administración (prioridad 8): dos módulos propios, delegables por rol.
    {
        title: 'Usuarios',
        group: 'Administración',
        href: '/usuarios',
        icon: UserCog,
        module: 'usuarios',
    },
    {
        title: 'Roles y permisos',
        shortTitle: 'Roles',
        group: 'Administración',
        href: '/roles',
        icon: ShieldCheck,
        module: 'roles',
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

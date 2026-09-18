import {
    BookOpen,
    CalendarRange,
    FileSpreadsheet,
    FolderOpen,
    LayoutGrid,
    Settings,
    Target,
    Users,
} from 'lucide-vue-next';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

// Fuente única de los módulos del sistema — la usan AppSidebar.vue (menú de
// escritorio) y MobileBottomNav.vue (barra inferior en móvil) para que no se
// desincronicen entre sí.
export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Carga de archivos',
        href: '/historico-general',
        icon: FolderOpen,
    },
    {
        title: 'Periodos',
        href: '/periodos',
        icon: CalendarRange,
    },
    {
        title: 'Colaboradores',
        href: '/asignaciones-empleado-sucursal',
        icon: Users,
    },
    {
        title: 'Reportes mensuales',
        href: '/reportes-mensuales',
        icon: FileSpreadsheet,
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
];

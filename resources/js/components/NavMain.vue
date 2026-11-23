<script setup lang="ts">
/**
 * NavMain — lista de módulos del menú lateral agrupada por sección.
 *
 * La usa AppSidebar. Agrupa los módulos por su campo `group` en el orden
 * Análisis, Operación, Administración, Ayuda y cuenta (los grupos no previstos
 * van al final) y marca como activo el módulo de la sección actual.
 *
 * Props:
 * - items: módulos ya filtrados por permisos (visibleMainNavItems).
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = defineProps<{
    items: NavItem[];
}>();

const { currentUrl } = useCurrentUrl();

// Activo = misma sección (primer segmento de la ruta): así "Configuración"
// sigue marcada en /settings/security y "Reportes mensuales" en la vista previa.
/** Devuelve el primer segmento de una ruta o URL (p. ej. "settings" de "/settings/security"). */
const firstSegment = (path: string): string => {
    try {
        path = new URL(path, 'http://x').pathname;
    } catch {
        // ruta relativa: se usa tal cual
    }

    return path.split('/').filter(Boolean)[0] ?? '';
};

/** Indica si el módulo pertenece a la misma sección que la URL actual. */
const isActive = (href: NavItem['href']): boolean =>
    firstSegment(toUrl(href)) === firstSegment(currentUrl.value);

// Fase 8.4 (15-oct-2026): los módulos se agrupan por sección (solo los que el
// rol ya puede ver — el filtrado sigue en visibleMainNavItems). Un grupo sin
// módulos visibles no se pinta.
const GROUP_ORDER: NonNullable<NavItem['group']>[] = [
    'Análisis',
    'Operación',
    'Administración',
    'Ayuda y cuenta',
];

/** Grupos a pintar, en orden, cada uno con sus módulos; omite grupos vacíos. */
const groups = computed(() => {
    const byGroup = new Map<string, NavItem[]>();

    for (const item of props.items) {
        const key = item.group ?? 'Módulos';
        byGroup.set(key, [...(byGroup.get(key) ?? []), item]);
    }

    const ordered = [
        ...GROUP_ORDER,
        ...[...byGroup.keys()].filter((k) => !GROUP_ORDER.includes(k as never)),
    ];

    return ordered
        .filter((key) => byGroup.has(key))
        .map((key) => ({ label: key, items: byGroup.get(key)! }));
});
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.label" class="px-2 py-1">
        <SidebarGroupLabel
            class="text-[11px] font-semibold tracking-wide text-sidebar-foreground/55 uppercase"
        >
            {{ group.label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem
                v-for="item in group.items"
                :key="item.title"
                :data-tour="`nav-${item.title.toLowerCase()}`"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item.href)"
                    :tooltip="item.title"
                    class="relative h-9 text-sidebar-foreground/80 data-[active=true]:font-semibold data-[active=true]:text-sidebar-accent-foreground data-[active=true]:before:absolute data-[active=true]:before:inset-y-1.5 data-[active=true]:before:left-0 data-[active=true]:before:w-[3px] data-[active=true]:before:rounded-full data-[active=true]:before:bg-sidebar-primary data-[active=true]:[&>svg]:text-sidebar-primary"
                >
                    <Link
                        :href="item.href"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>

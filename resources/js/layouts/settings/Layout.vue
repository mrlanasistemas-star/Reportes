<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Palette, ShieldCheck, UserRound } from 'lucide-vue-next';
import type { Component } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

// Fase 8.12 (15-oct-2026): presentación propia — encabezado de página, menú
// lateral con icono + descripción (pestañas horizontales en móvil) y el
// contenido directo, sin tres tarjetas anidadas una dentro de otra.
type SettingsNavItem = NavItem & { description: string; iconCmp: Component };

const sidebarNavItems: SettingsNavItem[] = [
    { title: 'Perfil', description: 'Nombre y correo', href: editProfile(), iconCmp: UserRound },
    { title: 'Seguridad', description: 'Contraseña y verificación en dos pasos', href: editSecurity(), iconCmp: ShieldCheck },
    { title: 'Apariencia', description: 'Tema claro u oscuro', href: editAppearance(), iconCmp: Palette },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:py-8">
        <header class="mb-6 lg:mb-8">
            <p class="app-kicker">Tu cuenta</p>
            <h1 class="app-title mt-1">Configuración</h1>
            <p class="app-subtitle mt-1 max-w-2xl">
                Administra tu información, la seguridad de tu acceso y cómo se ve el sistema. Estos cambios solo afectan a tu usuario.
            </p>
        </header>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
            <nav aria-label="Secciones de configuración" class="min-w-0 lg:sticky lg:top-6">
                <ul class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:gap-1 lg:overflow-visible lg:px-0 lg:pb-0">
                    <li v-for="item in sidebarNavItems" :key="toUrl(item.href)" class="shrink-0">
                        <Link
                            :href="item.href"
                            :aria-current="isCurrentOrParentUrl(item.href) ? 'page' : undefined"
                            :class="[
                                'group flex items-center gap-3 rounded-xl border px-3 py-2.5 text-sm transition-colors',
                                isCurrentOrParentUrl(item.href)
                                    ? 'border-primary/25 bg-accent text-accent-foreground shadow-soft-xs'
                                    : 'border-transparent text-muted-foreground hover:border-border hover:bg-card hover:text-foreground',
                            ]"
                        >
                            <span
                                :class="[
                                    'flex size-8 shrink-0 items-center justify-center rounded-lg border',
                                    isCurrentOrParentUrl(item.href) ? 'border-primary/20 bg-card text-primary' : 'bg-card',
                                ]"
                            >
                                <component :is="item.iconCmp" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold whitespace-nowrap">{{ item.title }}</span>
                                <span class="hidden text-xs text-muted-foreground lg:block">{{ item.description }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </nav>

            <main class="min-w-0 space-y-6">
                <slot />
            </main>
        </div>
    </div>
</template>

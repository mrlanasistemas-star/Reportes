<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Menu } from 'lucide-vue-next'
import { useSidebar } from '@/components/ui/sidebar'
import { useCurrentUrl } from '@/composables/useCurrentUrl'
import { mainNavItems } from '@/config/mainNav'

const { setOpenMobile } = useSidebar()
const { isCurrentUrl } = useCurrentUrl()

// Solo 4 accesos directos abajo (Dashboard, Carga de archivos, Reportes,
// OKR) — el resto del menú (Periodos, Colaboradores, Guía, Configuración)
// vive detrás de "Más", que abre el mismo panel deslizable que ya arma
// AppSidebar.vue en modo móvil.
const quickItems = [mainNavItems[0], mainNavItems[1], mainNavItems[4], mainNavItems[5]]
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-sidebar-border/70 bg-sidebar/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_-16px_rgba(0,0,0,0.25)] backdrop-blur-lg md:hidden"
        role="navigation"
        aria-label="Navegación principal"
    >
        <div class="grid grid-cols-5">
            <Link
                v-for="item in quickItems"
                :key="item.title"
                :href="item.href"
                class="flex flex-col items-center justify-center gap-1 py-2.5 text-[10px] font-semibold text-sidebar-foreground/60 transition-colors duration-200 active:scale-95"
                :class="isCurrentUrl(item.href) ? 'text-indigo-500' : 'hover:text-sidebar-foreground'"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-xl transition-colors duration-200"
                    :class="isCurrentUrl(item.href) ? 'bg-indigo-500/10' : ''"
                >
                    <component :is="item.icon" class="size-5" />
                </span>
                <span class="max-w-[4.5rem] truncate">{{ item.title }}</span>
            </Link>

            <button
                type="button"
                class="flex flex-col items-center justify-center gap-1 py-2.5 text-[10px] font-semibold text-sidebar-foreground/60 transition-colors duration-200 hover:text-sidebar-foreground active:scale-95"
                aria-label="Ver todo el menú"
                @click="setOpenMobile(true)"
            >
                <span class="flex size-9 items-center justify-center rounded-xl">
                    <Menu class="size-5" />
                </span>
                <span>Más</span>
            </button>
        </div>
    </nav>
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { Menu } from 'lucide-vue-next'
import { computed } from 'vue'
import { useSidebar } from '@/components/ui/sidebar'
import { useCurrentUrl } from '@/composables/useCurrentUrl'
import { visibleMainNavItems } from '@/config/mainNav'

const { setOpenMobile } = useSidebar()
const { isCurrentUrl } = useCurrentUrl()
const page = usePage()

// Solo 4 accesos directos abajo — el resto del menú vive detrás de "Más",
// que abre el mismo panel deslizable que ya arma AppSidebar.vue en modo
// móvil. Cierre 04-oct-2026 (Parte 1): la curaduría ya no asume índices
// fijos — un colaborador no ve Dashboard/Carga/Reportes (son
// Reportería financiero global, roles admin/gerencial), así que se rellena
// con lo que SÍ le queda visible (OKR, Guía, Configuración) en vez de dejar
// huecos o enlaces a algo que el backend le rechazaría.
const quickItems = computed(() => {
    const visible = visibleMainNavItems(page.props.auth.user?.role)
    const preferredTitles = ['Dashboard', 'Carga de archivos', 'Reportes mensuales', 'OKR']
    const preferred = preferredTitles.map((t) => visible.find((i) => i.title === t)).filter((i) => !!i)
    const rest = visible.filter((i) => !preferred.includes(i))

    return [...preferred, ...rest].slice(0, 4)
})
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

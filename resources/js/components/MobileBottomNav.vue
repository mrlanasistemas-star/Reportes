<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { Menu } from 'lucide-vue-next'
import { computed } from 'vue'
import { useSidebar } from '@/components/ui/sidebar'
import { useCurrentUrl } from '@/composables/useCurrentUrl'
import { visibleMainNavItems } from '@/config/mainNav'

const { setOpenMobile, openMobile } = useSidebar()
const { isCurrentOrParentUrl } = useCurrentUrl()
const page = usePage()

// Solo 4 accesos directos abajo — el resto del menú vive detrás de "Más",
// que abre el mismo panel deslizable que ya arma AppSidebar.vue en modo
// móvil. Cierre 04-oct-2026 (Parte 1): la curaduría ya no asume índices
// fijos — un colaborador no ve Dashboard/Carga/Reportes (son
// Reportería financiero global, roles admin/gerencial), así que se rellena
// con lo que SÍ le queda visible (OKR, Guía, Configuración) en vez de dejar
// huecos o enlaces a algo que el backend le rechazaría.
const quickItems = computed(() => {
    const visible = visibleMainNavItems(page.props.auth)
    const preferredTitles = ['Dashboard', 'Carga de archivos', 'Reportes mensuales', 'OKR']
    const preferred = preferredTitles.map((t) => visible.find((i) => i.title === t)).filter((i) => !!i)
    const rest = visible.filter((i) => !preferred.includes(i))

    return [...preferred, ...rest].slice(0, 4)
})

// El número de columnas se adapta a los accesos visibles (+1 para "Más"): así
// un rol con pocos módulos no deja huecos y "Más" siempre queda a la derecha.
const columns = computed(() => quickItems.value.length + 1)
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-sidebar-border/70 bg-sidebar/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_28px_-14px_rgba(0,0,0,0.35)] backdrop-blur-xl md:hidden"
        role="navigation"
        aria-label="Navegación principal"
    >
        <div class="grid" :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }">
            <Link
                v-for="item in quickItems"
                :key="item.title"
                :href="item.href"
                class="group relative flex min-h-[3.5rem] flex-col items-center justify-center gap-1 py-2 text-[11px] font-semibold transition-colors duration-200 active:scale-[0.97]"
                :class="isCurrentOrParentUrl(item.href) ? 'text-primary' : 'text-sidebar-foreground/60 hover:text-sidebar-foreground'"
                :aria-current="isCurrentOrParentUrl(item.href) ? 'page' : undefined"
            >
                <!-- Indicador superior activo tipo app -->
                <span
                    class="absolute top-0 h-0.5 w-8 rounded-full bg-primary transition-opacity duration-200"
                    :class="isCurrentOrParentUrl(item.href) ? 'opacity-100' : 'opacity-0'"
                    aria-hidden="true"
                />
                <span
                    class="flex size-9 items-center justify-center rounded-2xl transition-all duration-200"
                    :class="isCurrentOrParentUrl(item.href) ? 'bg-primary/12 scale-105' : 'group-hover:bg-sidebar-accent/60'"
                >
                    <component :is="item.icon" class="size-5" />
                </span>
                <span class="max-w-[5rem] truncate">{{ item.shortTitle ?? item.title }}</span>
            </Link>

            <button
                type="button"
                class="group relative flex min-h-[3.5rem] flex-col items-center justify-center gap-1 py-2 text-[11px] font-semibold transition-colors duration-200 active:scale-[0.97]"
                :class="openMobile ? 'text-primary' : 'text-sidebar-foreground/60 hover:text-sidebar-foreground'"
                aria-label="Ver todo el menú"
                :aria-expanded="openMobile"
                @click="setOpenMobile(true)"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-2xl transition-all duration-200"
                    :class="openMobile ? 'bg-primary/12 scale-105' : 'group-hover:bg-sidebar-accent/60'"
                >
                    <Menu class="size-5" />
                </span>
                <span>Más</span>
            </button>
        </div>
    </nav>
</template>

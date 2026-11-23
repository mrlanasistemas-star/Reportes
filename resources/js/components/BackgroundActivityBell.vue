<script setup lang="ts">
/**
 * BackgroundActivityBell — campana de notificaciones en la barra superior.
 *
 * La usa AppSidebarHeader. Muestra un globo con el número de notificaciones
 * sin leer (prop compartida `backgroundActivity` de Inertia) y, al abrirse,
 * consulta la lista de notificaciones internas; permite abrir cada una
 * (marcándola como leída), marcar todas como leídas e ir a la configuración de
 * notificaciones. Sin props ni emits.
 */
import { router, usePage } from '@inertiajs/vue3';
import {
    Ban,
    Bell,
    CheckCheck,
    CheckCircle2,
    Info,
    OctagonX,
    PauseCircle,
    Settings2,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { csrfToken, formatDateTime } from '@/components/efectividad/format';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { edit as notificationSettings } from '@/routes/notification-settings';
import {
    index as notificationsIndex,
    read as readRoute,
    readAll as readAllRoute,
} from '@/routes/notifications';

/**
 * Indicador de trabajos en segundo plano + notificaciones internas
 * persistentes (tabla `notifications`). Los conteos llegan como prop
 * compartida en cada navegación; el detalle se consulta al abrir. No hay
 * sondeo periódico: el endpoint de notificaciones no devuelve `active_count`
 * y recargar la prop compartida implicaría repetir la petición de la página
 * actual, así que el contador se actualiza al navegar o al abrir el panel.
 */
type NotificationItem = {
    id: string;
    data: {
        kind: 'success' | 'blocked' | 'failed' | 'cancelled' | 'info';
        title: string;
        run_id: number | null;
        period_label: string | null;
        branch_label: string | null;
        url: string;
    };
    read_at: string | null;
    created_at: string | null;
};

const page = usePage();
/** Conteos compartidos por Inertia en cada navegación (activos y sin leer). */
const shared = computed(
    () =>
        (page.props.backgroundActivity as {
            active_count: number;
            unread_count: number;
        } | null) ?? { active_count: 0, unread_count: 0 },
);

/** Notificaciones sin leer; se actualiza con la prop compartida y con cada consulta. */
const unreadCount = ref(shared.value.unread_count);
/** Notificaciones cargadas al abrir el panel. */
const items = ref<NotificationItem[]>([]);
const open = ref(false);
const loading = ref(false);

/** Sincroniza el contador local cuando llega una nueva prop compartida. */
watch(shared, (value) => {
    unreadCount.value = value.unread_count;
});

/**
 * Consulta las notificaciones del usuario y actualiza la lista y el contador.
 * Evita consultas simultáneas; los errores se ignoran en silencio.
 */
async function load() {
    if (loading.value) {
        return;
    }

    loading.value = true;

    try {
        const res = await fetch(notificationsIndex().url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!res.ok) {
            return;
        }

        const body = (await res.json()) as {
            unread_count: number;
            items: NotificationItem[];
        };
        items.value = body.items;
        unreadCount.value = body.unread_count;
    } catch {
        // Silencioso: el indicador es secundario; el estado real vive en BD.
    } finally {
        loading.value = false;
    }
}

/** Al abrir el panel se recarga la lista de notificaciones. */
watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

/**
 * Envía un POST JSON con token CSRF (marcar leída / marcar todas).
 *
 * @param url Ruta a la que se envía la petición.
 */
async function post(url: string) {
    await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
}

/**
 * Marca la notificación como leída (si no lo estaba), cierra el panel y navega
 * a la URL asociada.
 *
 * @param item Notificación seleccionada.
 */
async function openNotification(item: NotificationItem) {
    if (!item.read_at) {
        await post(readRoute(item.id).url);
    }

    open.value = false;
    router.visit(item.data.url);
}

/** Marca todas las notificaciones como leídas en el servidor y en la lista local. */
async function markAllRead() {
    await post(readAllRoute().url);
    items.value = items.value.map((i) => ({
        ...i,
        read_at: i.read_at ?? new Date().toISOString(),
    }));
    unreadCount.value = 0;
}

/** Icono por tipo de notificación. */
const kindIcon = {
    success: CheckCircle2,
    blocked: PauseCircle,
    failed: OctagonX,
    cancelled: Ban,
    info: Info,
} as const;
/** Color del icono por tipo de notificación. */
const kindTone = {
    success: 'text-emerald-600',
    blocked: 'text-amber-600',
    failed: 'text-destructive',
    cancelled: 'text-muted-foreground',
    info: 'text-primary',
} as const;
// 23-nov-2026 — avisos de Reportería pueden no traer sucursal: no mostrar "· ·".
/**
 * Arma el subtítulo "periodo · sucursal · fecha", omitiendo las partes vacías.
 *
 * @param item Notificación a describir.
 */
function subtitle(item: NotificationItem): string {
    return [
        item.data.period_label,
        item.data.branch_label,
        formatDateTime(item.created_at),
    ]
        .filter((part) => part)
        .join(' · ');
}

/** Cierra el panel y abre la configuración de notificaciones. */
function openSettings() {
    open.value = false;
    router.visit(notificationSettings().url);
}
/** Número mostrado en el globo de la campana (se trunca a "9+" en la plantilla). */
const badgeCount = computed(() => unreadCount.value);
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="`Notificaciones (${unreadCount} sin leer)`"
            >
                <Bell />
                <span
                    v-if="badgeCount > 0"
                    class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-4 font-semibold text-primary-foreground"
                >
                    {{ badgeCount > 9 ? '9+' : badgeCount }}
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[min(22rem,calc(100vw-2rem))] p-0">
            <div class="flex items-center justify-between px-3 pt-3 pb-1">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Notificaciones
                </p>
                <Button
                    v-if="unreadCount > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-3.5" />
                    Marcar leídas
                </Button>
            </div>
            <p
                v-if="loading && !items.length"
                class="px-3 pb-3 text-sm text-muted-foreground"
            >
                Cargando…
            </p>
            <p
                v-else-if="!items.length"
                class="px-3 pb-3 text-sm text-muted-foreground"
            >
                No tienes notificaciones.
            </p>
            <ul v-else class="max-h-80 overflow-y-auto pb-1">
                <li v-for="item in items" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full items-start gap-2.5 px-3 py-2 text-left hover:bg-muted"
                        @click="openNotification(item)"
                    >
                        <component
                            :is="kindIcon[item.data.kind] ?? Bell"
                            class="mt-0.5 size-4 shrink-0"
                            :class="kindTone[item.data.kind]"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm"
                                :class="
                                    item.read_at
                                        ? 'text-muted-foreground'
                                        : 'font-medium'
                                "
                                >{{ item.data.title }}</span
                            >
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ subtitle(item) }}
                            </span>
                        </span>
                        <span
                            v-if="!item.read_at"
                            class="mt-1.5 size-2 shrink-0 rounded-full bg-primary"
                            aria-label="Sin leer"
                        />
                    </button>
                </li>
            </ul>
            <div class="border-t px-2 py-1.5">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8 w-full justify-start px-2 text-xs text-muted-foreground"
                    @click="openSettings"
                >
                    <Settings2 class="size-3.5" />
                    Configurar notificaciones del teléfono y correo
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>

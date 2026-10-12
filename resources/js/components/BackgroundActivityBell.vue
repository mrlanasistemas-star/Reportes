<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    CheckCheck,
    CheckCircle2,
    Loader2,
    OctagonX,
    PauseCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { csrfToken, formatDateTime } from '@/components/efectividad/format';
import RunStatusBadge from '@/components/efectividad/RunStatusBadge.vue';
import type { RunSummary } from '@/components/efectividad/types';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import runs from '@/routes/efectividad-cobranza/runs';
import {
    index as notificationsIndex,
    read as readRoute,
    readAll as readAllRoute,
} from '@/routes/notifications';

/**
 * Indicador de trabajos en segundo plano + notificaciones internas
 * persistentes (tabla `notifications`). Los conteos llegan como prop
 * compartida en cada navegación; el detalle se consulta al abrir. Mientras
 * haya trabajos activos se refresca cada 15 s para detectar cuándo terminan.
 */
type NotificationItem = {
    id: string;
    data: {
        kind: 'success' | 'blocked' | 'failed';
        title: string;
        run_id: number;
        period_label: string | null;
        branch_label: string | null;
        url: string;
    };
    read_at: string | null;
    created_at: string | null;
};

const page = usePage();
const shared = computed(
    () =>
        (page.props.backgroundActivity as {
            active_count: number;
            unread_count: number;
        } | null) ?? { active_count: 0, unread_count: 0 },
);

const activeCount = ref(shared.value.active_count);
const unreadCount = ref(shared.value.unread_count);
const items = ref<NotificationItem[]>([]);
const active = ref<RunSummary[]>([]);
const open = ref(false);
const loading = ref(false);

watch(shared, (value) => {
    activeCount.value = value.active_count;
    unreadCount.value = value.unread_count;
});

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
            active: RunSummary[];
        };
        const finishedSomething = body.active.length < activeCount.value;
        items.value = body.items;
        active.value = body.active;
        unreadCount.value = body.unread_count;
        activeCount.value = body.active.length;

        if (finishedSomething && !open.value) {
            // Un trabajo terminó mientras el usuario estaba en otra página: refresca
            // los conteos compartidos (y la página actual si muestra ese módulo).
            router.reload({ only: ['backgroundActivity'] });
        }
    } catch {
        // Silencioso: el indicador es secundario; el estado real vive en BD.
    } finally {
        loading.value = false;
    }
}

let timer: ReturnType<typeof setInterval> | null = null;
watch(
    activeCount,
    (count) => {
        if (count > 0 && !timer) {
            timer = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    void load();
                }
            }, 15000);
        } else if (count === 0 && timer) {
            clearInterval(timer);
            timer = null;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => timer && clearInterval(timer));

watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

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

async function openNotification(item: NotificationItem) {
    if (!item.read_at) {
        await post(readRoute(item.id).url);
    }

    open.value = false;
    router.visit(item.data.url);
}

async function markAllRead() {
    await post(readAllRoute().url);
    items.value = items.value.map((i) => ({
        ...i,
        read_at: i.read_at ?? new Date().toISOString(),
    }));
    unreadCount.value = 0;
}

const kindIcon = {
    success: CheckCircle2,
    blocked: PauseCircle,
    failed: OctagonX,
} as const;
const kindTone = {
    success: 'text-emerald-600',
    blocked: 'text-amber-600',
    failed: 'text-destructive',
} as const;
const badgeCount = computed(() => unreadCount.value + activeCount.value);
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="`Notificaciones (${unreadCount} sin leer, ${activeCount} trabajos en proceso)`"
            >
                <Loader2 v-if="activeCount > 0" class="animate-spin" />
                <Bell v-else />
                <span
                    v-if="badgeCount > 0"
                    class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-4 font-semibold text-primary-foreground"
                >
                    {{ badgeCount > 9 ? '9+' : badgeCount }}
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[min(22rem,calc(100vw-2rem))] p-0">
            <div v-if="active.length" class="border-b p-3">
                <p
                    class="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    En segundo plano
                </p>
                <ul class="space-y-2">
                    <li v-for="run in active" :key="run.id">
                        <Link
                            :href="runs.show(run.id).url"
                            class="block rounded-md p-1.5 hover:bg-muted"
                            @click="open = false"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <p class="truncate text-sm font-medium">
                                    Efectividad · {{ run.period_label }}
                                </p>
                                <RunStatusBadge
                                    :state="run.display_state"
                                    :label="run.display_label"
                                />
                            </div>
                            <p class="truncate text-xs text-muted-foreground">
                                #{{ run.id }} · {{ run.branch_label }}
                                <template v-if="run.current_stage">
                                    · Paso {{ run.current_stage.position }}/{{
                                        run.stage_count
                                    }}
                                    {{ run.current_stage.label }}</template
                                >
                            </p>
                        </Link>
                    </li>
                </ul>
            </div>

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
                                {{ item.data.period_label }} ·
                                {{ item.data.branch_label }} ·
                                {{ formatDateTime(item.created_at) }}
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
        </PopoverContent>
    </Popover>
</template>

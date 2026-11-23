<script setup lang="ts">
/**
 * UsersGrid — cuadrícula paginada de tarjetas de usuario (UserCard).
 *
 * Se usa en pages/usuarios/Index.
 *
 * Props:
 * - users: usuarios ya filtrados a mostrar.
 * - resendingId: id del usuario cuya invitación se está reenviando.
 * - selectable: habilita la selección masiva.
 *
 * Emits: reenvía hacia la página las acciones de cada tarjeta (enable, revoke,
 * toggleBlocked, reset, resend, changeRole, linkEmployee, editScope).
 *
 * v-model:
 * - selected: ids seleccionados para acciones masivas. Se recorta automáticamente
 *   a los usuarios visibles cuando cambian los filtros.
 */
// Cuadrícula del módulo Usuarios (reemplaza la tabla): una tarjeta por usuario,
// selección masiva para el restablecimiento de contraseñas y paginación en
// cliente (24 por página) para que cientos de usuarios no carguen de golpe.
import { computed, ref, watch } from 'vue';
import AppEmptyState from '@/components/app/AppEmptyState.vue';
import AppPagination from '@/components/navigation/AppPagination.vue';
import UserCard from './UserCard.vue';
import type { UserRow } from './users';

/** Tarjetas por página. */
const PAGE_SIZE = 24;

const props = defineProps<{
    users: UserRow[];
    resendingId: number | null;
    selectable?: boolean;
}>();
const emit = defineEmits<{
    enable: [UserRow];
    revoke: [UserRow];
    toggleBlocked: [UserRow];
    reset: [UserRow];
    resend: [UserRow];
    changeRole: [UserRow];
    linkEmployee: [UserRow];
    editScope: [UserRow];
}>();
// Ids seleccionados para acciones masivas (solo usuarios cuya contraseña se puede restablecer).
const selected = defineModel<number[]>('selected', { default: () => [] });

/** Página actual (empieza en 1). */
const page = ref(1);
/** Última página según el total de usuarios (mínimo 1). */
const lastPage = computed(() =>
    Math.max(1, Math.ceil(props.users.length / PAGE_SIZE)),
);
/** Usuarios de la página actual. */
const pageUsers = computed(() =>
    props.users.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE),
);
/** Texto "Mostrando X–Y de N" (vacío si no hay usuarios). */
const rangeLabel = computed(() => {
    if (props.users.length === 0) {
        return '';
    }

    const from = (page.value - 1) * PAGE_SIZE + 1;
    const to = Math.min(page.value * PAGE_SIZE, props.users.length);

    return `Mostrando ${from}–${to} de ${props.users.length}`;
});

// Al cambiar la búsqueda/filtros/sección la lista cambia: si la página quedó
// fuera de rango se vuelve a la primera (nunca una página vacía).
watch(
    () => props.users,
    () => {
        if (page.value > lastPage.value) {
            page.value = 1;
        }
    },
);

/** Cambia a la página indicada por AppPagination. */
function changePage(value: number) {
    page.value = value;
}

// «Todos los visibles» = todos los usuarios que pasan los filtros actuales (en todas las páginas).
const selectableIds = computed(() =>
    props.users.filter((u) => u.can.reset_password).map((u) => u.id),
);
/** Conjunto de ids seleccionados, para búsquedas rápidas. */
const selectedSet = computed(() => new Set(selected.value));

// Al cambiar búsqueda/filtros/sección la selección se recorta a los usuarios que
// siguen visibles y seleccionables: así BulkPasswordBar nunca actúa sobre usuarios
// que el administrador ya no está viendo. (Declarado después de selectableIds.)
watch(selectableIds, (ids) => {
    const visible = new Set(ids);
    const kept = selected.value.filter((id) => visible.has(id));

    if (kept.length !== selected.value.length) {
        selected.value = kept;
    }
});
/** Todos los seleccionables visibles están marcados. */
const allSelected = computed(
    () =>
        selectableIds.value.length > 0 &&
        selectableIds.value.every((id) => selectedSet.value.has(id)),
);
/** Hay algunos (no todos) marcados: estado indeterminado de la casilla general. */
const someSelected = computed(
    () =>
        !allSelected.value &&
        selectableIds.value.some((id) => selectedSet.value.has(id)),
);

/** Marca todos los visibles seleccionables o, si ya lo están, los desmarca (conserva los demás). */
function toggleAll() {
    const visible = new Set(selectableIds.value);
    selected.value = allSelected.value
        ? selected.value.filter((id) => !visible.has(id))
        : [...new Set([...selected.value, ...selectableIds.value])];
}

/** Marca o desmarca un usuario. */
function toggleOne(id: number) {
    selected.value = selectedSet.value.has(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id];
}
</script>

<template>
    <section class="space-y-3" aria-label="Usuarios">
        <!-- Barra superior: seleccionar todos y rango mostrado -->
        <div
            v-if="users.length > 0"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
        >
            <label
                v-if="selectable"
                class="inline-flex cursor-pointer items-center gap-2 font-semibold text-foreground"
                :class="
                    selectableIds.length === 0
                        ? 'cursor-not-allowed opacity-60'
                        : ''
                "
            >
                <input
                    type="checkbox"
                    class="size-4 accent-primary"
                    :checked="allSelected"
                    :indeterminate="someSelected"
                    :disabled="selectableIds.length === 0"
                    aria-label="Seleccionar todos los usuarios visibles"
                    @change="toggleAll"
                />
                Seleccionar todos los visibles
                <span class="text-xs font-normal text-muted-foreground"
                    >({{ selectableIds.length }} con los filtros actuales)</span
                >
            </label>
            <span v-else />
            <span class="text-xs text-muted-foreground tabular-nums">{{
                rangeLabel
            }}</span>
        </div>

        <!-- TransitionGroup (27-oct-2026): al filtrar/paginar las tarjetas nuevas
             entran con fade, las que salen se quitan al instante y el resto se
             reacomoda deslizándose (FLIP, solo transform). Ver .app-list-* en app.css. -->
        <TransitionGroup
            v-if="users.length > 0"
            tag="div"
            name="app-list"
            class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            <UserCard
                v-for="u in pageUsers"
                :key="u.id"
                :user="u"
                :resending-id="resendingId"
                :selectable="selectable"
                :selected="selectedSet.has(u.id)"
                @toggle-select="toggleOne"
                @enable="emit('enable', $event)"
                @revoke="emit('revoke', $event)"
                @toggle-blocked="emit('toggleBlocked', $event)"
                @reset="emit('reset', $event)"
                @resend="emit('resend', $event)"
                @change-role="emit('changeRole', $event)"
                @link-employee="emit('linkEmployee', $event)"
                @edit-scope="emit('editScope', $event)"
            />
        </TransitionGroup>
        <div v-else class="app-card">
            <AppEmptyState
                title="Sin resultados"
                message="Ningún usuario coincide con la búsqueda o los filtros."
            />
        </div>

        <AppPagination
            v-if="lastPage > 1"
            :current-page="page"
            :last-page="lastPage"
            @change="changePage"
        />
    </section>
</template>

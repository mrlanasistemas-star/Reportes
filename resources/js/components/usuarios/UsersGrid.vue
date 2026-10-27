<script setup lang="ts">
// Cuadrícula del módulo Usuarios (reemplaza la tabla): una tarjeta por usuario,
// selección masiva para el restablecimiento de contraseñas y paginación en
// cliente (24 por página) para que cientos de usuarios no carguen de golpe.
import { computed, ref, watch } from 'vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import AppPagination from '@/components/navigation/AppPagination.vue'
import UserCard from './UserCard.vue'
import type { UserRow } from './users'

const PAGE_SIZE = 24

const props = defineProps<{ users: UserRow[]; resendingId: number | null; selectable?: boolean }>()
const emit = defineEmits<{
    enable: [UserRow]; revoke: [UserRow]; toggleBlocked: [UserRow]; reset: [UserRow]
    resend: [UserRow]; changeRole: [UserRow]; linkEmployee: [UserRow]; editScope: [UserRow]
}>()
// Ids seleccionados para acciones masivas (solo usuarios cuya contraseña se puede restablecer).
const selected = defineModel<number[]>('selected', { default: () => [] })

const page = ref(1)
const lastPage = computed(() => Math.max(1, Math.ceil(props.users.length / PAGE_SIZE)))
const pageUsers = computed(() => props.users.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE))
const rangeLabel = computed(() => {
    if (props.users.length === 0) {
        return ''
    }

    const from = (page.value - 1) * PAGE_SIZE + 1
    const to = Math.min(page.value * PAGE_SIZE, props.users.length)

    return `Mostrando ${from}–${to} de ${props.users.length}`
})

// Al cambiar la búsqueda/filtros/sección la lista cambia: si la página quedó
// fuera de rango se vuelve a la primera (nunca una página vacía).
watch(() => props.users, () => {
    if (page.value > lastPage.value) {
        page.value = 1
    }
})

function changePage(value: number) {
    page.value = value
}

// «Todos los visibles» = todos los usuarios que pasan los filtros actuales (en todas las páginas).
const selectableIds = computed(() => props.users.filter((u) => u.can.reset_password).map((u) => u.id))
const selectedSet = computed(() => new Set(selected.value))
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selectedSet.value.has(id)))
const someSelected = computed(() => !allSelected.value && selectableIds.value.some((id) => selectedSet.value.has(id)))

function toggleAll() {
    const visible = new Set(selectableIds.value)
    selected.value = allSelected.value
        ? selected.value.filter((id) => !visible.has(id))
        : [...new Set([...selected.value, ...selectableIds.value])]
}

function toggleOne(id: number) {
    selected.value = selectedSet.value.has(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]
}
</script>

<template>
    <section class="space-y-3" aria-label="Usuarios">
        <div v-if="users.length > 0" class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <label v-if="selectable" class="inline-flex cursor-pointer items-center gap-2 font-semibold text-foreground" :class="selectableIds.length === 0 ? 'cursor-not-allowed opacity-60' : ''">
                <input
                    type="checkbox" class="size-4 accent-primary" :checked="allSelected" :indeterminate="someSelected"
                    :disabled="selectableIds.length === 0" aria-label="Seleccionar todos los usuarios visibles" @change="toggleAll"
                >
                Seleccionar todos los visibles
                <span class="text-xs font-normal text-muted-foreground">({{ selectableIds.length }} con los filtros actuales)</span>
            </label>
            <span v-else />
            <span class="text-xs text-muted-foreground tabular-nums">{{ rangeLabel }}</span>
        </div>

        <!-- TransitionGroup (27-oct-2026): al filtrar/paginar las tarjetas nuevas
             entran con fade, las que salen se quitan al instante y el resto se
             reacomoda deslizándose (FLIP, solo transform). Ver .app-list-* en app.css. -->
        <TransitionGroup v-if="users.length > 0" tag="div" name="app-list" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <UserCard
                v-for="u in pageUsers" :key="u.id" :user="u" :resending-id="resendingId" :selectable="selectable" :selected="selectedSet.has(u.id)"
                @toggle-select="toggleOne"
                @enable="emit('enable', $event)" @revoke="emit('revoke', $event)" @toggle-blocked="emit('toggleBlocked', $event)"
                @reset="emit('reset', $event)" @resend="emit('resend', $event)" @change-role="emit('changeRole', $event)"
                @link-employee="emit('linkEmployee', $event)" @edit-scope="emit('editScope', $event)"
            />
        </TransitionGroup>
        <div v-else class="app-card">
            <AppEmptyState title="Sin resultados" message="Ningún usuario coincide con la búsqueda o los filtros." />
        </div>

        <AppPagination v-if="lastPage > 1" :current-page="page" :last-page="lastPage" @change="changePage" />
    </section>
</template>

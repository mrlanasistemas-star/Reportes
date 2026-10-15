<script setup lang="ts">
// Historial de acciones de administración de usuarios y roles (bitácora okr_audit_logs).
import { History, ShieldCheck, User } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { formatDateTime } from './users'
import type { AuditEntry } from './users'

const props = defineProps<{ entries: AuditEntry[] }>()
const query = ref('')

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase()

    return q === '' ? props.entries : props.entries.filter((e) => `${e.actor} ${e.target} ${e.label} ${e.detail ?? ''}`.toLowerCase().includes(q))
})
</script>

<template>
    <section class="app-card space-y-3 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><History class="size-4 text-primary" /> Historial de acciones</h2>
            <input v-model="query" type="search" placeholder="Buscar en el historial…" class="app-input h-9 w-full text-sm sm:w-64" aria-label="Buscar en el historial">
        </div>
        <ol v-if="filtered.length" class="divide-y divide-border">
            <li v-for="e in filtered" :key="e.id" class="flex items-start gap-3 py-2.5">
                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <ShieldCheck v-if="e.type === 'role'" class="size-3.5" /><User v-else class="size-3.5" />
                </span>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="text-foreground"><strong>{{ e.actor }}</strong> · {{ e.label }} <strong>{{ e.target }}</strong></p>
                    <p v-if="e.old_value || e.new_value" class="text-xs text-muted-foreground">{{ e.old_value ?? '—' }} → {{ e.new_value ?? '—' }}</p>
                    <p v-if="e.detail" class="text-xs text-muted-foreground">{{ e.detail }}</p>
                </div>
                <time class="shrink-0 text-[11px] text-muted-foreground">{{ formatDateTime(e.at) }}</time>
            </li>
        </ol>
        <AppEmptyState v-else title="Sin movimientos" message="Aquí aparecerán altas, cambios de rol, accesos e invitaciones." />
    </section>
</template>

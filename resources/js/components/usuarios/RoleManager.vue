<script setup lang="ts">
// Listado de roles (sistema y personalizados): resumen de permisos, usuarios
// asignados y acciones editar / eliminar / ver matriz.
import { router } from '@inertiajs/vue3'
import { Crown, Grid3x3, Lock, Pencil, ShieldCheck, Trash2, Users } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { Button } from '@/components/ui/button'
import { roleTone } from './roles'
import type { RoleInfo } from './roles'

defineProps<{ roles: RoleInfo[]; selectedKey: string | null }>()
const emit = defineEmits<{ edit: [RoleInfo]; select: [RoleInfo] }>()

function remove(role: RoleInfo) {
    if (!role.destroy_url) {
        return
    }

    if (role.users.length > 0) {
        Swal.fire({ icon: 'info', title: 'Este rol está en uso', text: `${role.users.length} usuario(s) tienen «${role.name}». Cámbialos de rol antes de eliminarlo.`, confirmButtonColor: '#4f46e5' })

        return
    }

    Swal.fire({
        icon: 'warning', title: `¿Eliminar el rol «${role.name}»?`, text: 'Se borran también sus permisos. Esta acción no se puede deshacer.',
        showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#e11d48',
    }).then((r) => {
        if (r.isConfirmed) {
            router.delete(role.destroy_url as string, { preserveScroll: true })
        }
    })
}
</script>

<template>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="role in roles" :key="role.key"
            class="relative flex flex-col overflow-hidden rounded-2xl border bg-card p-5 shadow-soft-xs transition hover:shadow-soft-md"
            :class="selectedKey === role.key ? 'border-primary ring-2 ring-primary/20' : 'border-border'"
            :aria-current="selectedKey === role.key ? 'true' : undefined"
        >
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-sm" :class="roleTone(role)">
                    <Crown v-if="role.key === 'admin'" class="size-5" /><ShieldCheck v-else class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="flex flex-wrap items-center gap-1.5 text-base font-bold text-foreground">
                        {{ role.name }}
                        <span v-if="role.is_system" class="inline-flex items-center gap-0.5 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-bold uppercase text-muted-foreground"><Lock class="size-2.5" /> Sistema</span>
                        <span v-else class="rounded-full bg-info-soft px-1.5 py-0.5 text-[10px] font-bold uppercase text-info">Personalizado</span>
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ role.description || 'Sin descripción' }}</p>
                </div>
            </div>

            <p class="mt-3 text-sm text-foreground">{{ role.key === 'admin' ? 'Acceso total: todos los módulos y acciones. No se configura.' : role.summary }}</p>

            <details class="mt-3 rounded-xl bg-muted/40 px-3 py-2 text-xs">
                <summary class="flex cursor-pointer items-center gap-1.5 font-semibold text-foreground"><Users class="size-3.5" /> {{ role.users.length }} usuario(s) asignado(s)</summary>
                <ul v-if="role.users.length" class="mt-2 max-h-36 space-y-1 overflow-y-auto">
                    <li v-for="u in role.users" :key="u.id" class="flex items-center justify-between gap-2">
                        <span class="truncate text-foreground">{{ u.name }}</span>
                        <span v-if="u.blocked" class="shrink-0 rounded-full bg-rose-500/10 px-1.5 text-[10px] font-semibold text-rose-700 dark:text-rose-300">Bloqueado</span>
                    </li>
                </ul>
                <p v-else class="mt-2 text-muted-foreground">Nadie tiene este rol todavía.</p>
            </details>

            <div class="mt-auto flex flex-wrap gap-2 pt-4">
                <Button type="button" variant="outline" size="sm" class="h-8 gap-1.5 rounded-lg" @click="emit('select', role)"><Grid3x3 class="size-3.5" /> {{ selectedKey === role.key ? 'Viendo permisos' : 'Ver permisos' }}</Button>
                <Button v-if="role.can_edit" type="button" variant="outline" size="sm" class="h-8 gap-1.5 rounded-lg" @click="emit('edit', role)"><Pencil class="size-3.5" /> Editar</Button>
                <Button v-if="role.can_delete" type="button" variant="outline" size="sm" class="h-8 gap-1.5 rounded-lg text-rose-600 hover:bg-rose-500/10 hover:text-rose-700" @click="remove(role)"><Trash2 class="size-3.5" /> Eliminar</Button>
            </div>
        </article>
    </div>
</template>

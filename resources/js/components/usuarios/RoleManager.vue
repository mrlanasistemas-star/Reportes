<script setup lang="ts">
// Roles y permisos (16-oct-2026): una tarjeta por rol — sistema y
// personalizados — con sus módulos, acciones y usuarios. Crear, editar y
// eliminar (solo personalizados sin usuarios) sin salir de la pantalla.
import { router } from '@inertiajs/vue3'
import { Crown, Lock, Pencil, Plus, ShieldCheck, Trash2, Users } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref } from 'vue'
import { Button } from '@/components/ui/button'
import RoleFormDialog from './RoleFormDialog.vue'
import { roleTone } from './roles'
import type { ActionOption, ModuleOption, RoleInfo } from './roles'

const props = defineProps<{ roles: RoleInfo[]; modules: ModuleOption[]; actions: ActionOption[] }>()

const dialogOpen = ref(false)
const editing = ref<RoleInfo | null>(null)

function moduleLabel(key: string): string {
    return props.modules.find((m) => m.key === key)?.label ?? key
}

function actionCount(role: RoleInfo): number {
    return Object.values(role.actions ?? {}).reduce((n, list) => n + list.length, 0)
}

function create() {
    editing.value = null
    dialogOpen.value = true
}

function edit(role: RoleInfo) {
    editing.value = role
    dialogOpen.value = true
}

function remove(role: RoleInfo) {
    if (role.users_count > 0) {
        Swal.fire({ icon: 'info', title: 'Este rol está en uso', text: `${role.users_count} usuario(s) tienen «${role.name}». Cámbialos de rol antes de eliminarlo.` })

        return
    }

    Swal.fire({
        icon: 'warning', title: `¿Eliminar el rol «${role.name}»?`, text: 'Se borran también sus permisos. Esta acción no se puede deshacer.',
        showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#e11d48',
    }).then((r) => {
        if (r.isConfirmed) {
            router.delete(`/okr/responsibles/roles/${role.key}`, { preserveScroll: true })
        }
    })
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-lg font-black tracking-tight text-foreground"><ShieldCheck class="size-5 text-violet-600" /> Roles y permisos</h2>
                <p class="text-xs text-muted-foreground">Crea los roles que necesites, define qué módulos ven y qué acciones sensibles pueden hacer. Después asígnalos al crear usuarios.</p>
            </div>
            <Button type="button" class="h-10 gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-white shadow-md hover:shadow-lg" @click="create">
                <Plus class="size-4" /> Nuevo rol
            </Button>
        </div>

        <TransitionGroup tag="div" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" enter-active-class="transition duration-300" enter-from-class="opacity-0 scale-95" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <article v-for="role in roles" :key="role.key" class="group relative flex flex-col overflow-hidden rounded-3xl border border-border bg-card p-5 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl">
                <div class="pointer-events-none absolute -right-10 -top-10 size-28 rounded-full bg-gradient-to-br opacity-10 blur-2xl" :class="roleTone(role)" />
                <div class="flex items-start gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-white shadow-md" :class="roleTone(role)">
                        <Crown v-if="role.key === 'admin'" class="size-5" />
                        <ShieldCheck v-else class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-1.5 text-base font-black text-foreground">
                            {{ role.name }}
                            <span v-if="role.is_system" class="inline-flex items-center gap-0.5 rounded-full bg-muted px-1.5 py-0.5 text-[9px] font-bold uppercase text-muted-foreground"><Lock class="size-2.5" /> Sistema</span>
                            <span v-else class="rounded-full bg-violet-500/10 px-1.5 py-0.5 text-[9px] font-bold uppercase text-violet-700 dark:text-violet-300">Personalizado</span>
                        </p>
                        <p class="text-[11px] text-muted-foreground">{{ role.description || 'Sin descripción' }}</p>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px]">
                    <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground"><Users class="size-3" /> {{ role.users_count }} usuario(s)</span>
                    <span v-if="role.key !== 'admin'" class="rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground">Nivel {{ role.base_level }}</span>
                    <span v-if="role.key !== 'admin'" class="rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground">{{ actionCount(role) }} acción(es) sensible(s)</span>
                </div>

                <div class="mt-3 flex flex-wrap gap-1">
                    <template v-if="role.key === 'admin'">
                        <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-700 dark:text-indigo-300">Todos los módulos y acciones</span>
                    </template>
                    <template v-else-if="role.modules.length">
                        <span v-for="m in role.modules" :key="m" class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">{{ moduleLabel(m) }}</span>
                    </template>
                    <span v-else class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-300">Sin módulos</span>
                </div>

                <div v-if="role.key !== 'admin'" class="mt-auto flex gap-2 pt-4">
                    <Button type="button" variant="outline" size="sm" class="h-8 gap-1.5 rounded-lg" @click="edit(role)"><Pencil class="size-3.5" /> Editar</Button>
                    <Button v-if="!role.is_system" type="button" variant="outline" size="sm" class="h-8 gap-1.5 rounded-lg text-rose-600 hover:bg-rose-500/10 hover:text-rose-700" @click="remove(role)"><Trash2 class="size-3.5" /> Eliminar</Button>
                </div>
                <p v-else class="mt-auto pt-4 text-[11px] text-muted-foreground">Acceso total — no se configura.</p>
            </article>
        </TransitionGroup>

        <RoleFormDialog v-model:open="dialogOpen" :role="editing" :modules="modules" :actions="actions" />
    </section>
</template>

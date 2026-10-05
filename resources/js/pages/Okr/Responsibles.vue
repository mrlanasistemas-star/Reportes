<script setup lang="ts">
import { Link, useForm, router } from '@inertiajs/vue3'
import { ArrowLeft, Plus, UserPlus, Users } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref, watch } from 'vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import TextField from '@/components/forms/TextField.vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import { Button } from '@/components/ui/button'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

type Responsible = {
    id: number; name: string; email: string; role: string; status: 'active' | 'pending'; objectives_count: number
    employee_id: number | null; employee_name: string | null; employee_is_active: boolean | null; current_branch: string | null
}
type LinkableEmployee = { id: number; full_name: string; is_active: boolean; linked_user_id: number | null }

const props = defineProps<{
    responsibles: Responsible[]
    employees: LinkableEmployee[]
    temp_password?: string | null
    current_user_id: number
    admin_count: number
}>()

// Contraseña temporal — se muestra UNA SOLA VEZ (ver ResponsibleController::index()).
watch(() => props.temp_password, (pwd) => {
    if (!pwd) {
return
}

    Swal.fire({
        icon: 'success', title: 'Responsable agregado',
        html: `Contraseña temporal (cópiala ahora, no se volverá a mostrar):<br><code style="font-size:1.1em">${pwd}</code><br><br>Debe usar "¿Olvidaste tu contraseña?" en el login para entrar la primera vez.`,
        confirmButtonColor: '#4f46e5',
    })
}, { immediate: true })

const showForm = ref(false)
// D7 del cierre (17-sep-2026): esta pantalla NUNCA puede crear un admin — el rol
// siempre es 'colaborador' (el backend también lo fuerza, ver ResponsibleController::store()).
const form = useForm({ name: '', email: '' })

function submit() {
    form.post('/okr/responsibles', {
        onSuccess: () => {
 form.reset(); showForm.value = false 
},
    })
}

function enableAccess(responsible: { id: number; name: string }) {
    Swal.fire({
        icon: 'question', title: `¿Habilitar acceso para ${responsible.name}?`,
        text: 'Confirma que ya verificaste la identidad de esta persona fuera del sistema.',
        showCancelButton: true, confirmButtonText: 'Habilitar', cancelButtonText: 'Cancelar', confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (r.isConfirmed) {
router.post(`/okr/responsibles/${responsible.id}/enable-access`)
}
    })
}

function disableAccess(responsible: { id: number; name: string }) {
    Swal.fire({
        icon: 'warning', title: `¿Quitar acceso a ${responsible.name}?`,
        text: 'No podrá entrar al módulo OKR hasta que se le vuelva a habilitar.',
        showCancelButton: true, confirmButtonText: 'Quitar acceso', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626',
    }).then((r) => {
        if (r.isConfirmed) {
router.post(`/okr/responsibles/${responsible.id}/disable-access`)
}
    })
}

// Candados espejo de los del backend (updateRole()) — se ocultan aquí para que
// nadie intente algo que el servidor rechazará sin ningún mensaje visible
// (este repo no comparte flash de sesión a Inertia globalmente).
function canChangeRole(responsible: { id: number; role: string }): boolean {
    if (responsible.id === props.current_user_id) {
return false
}

    if (responsible.role === 'admin' && props.admin_count <= 1) {
return false
}

    return true
}

// 2 del cierre (05-oct-2026) — vincular/desvincular el Employee real de este
// User. Opciones = empleados SIN vínculo (linked_user_id === null) + el que
// ya pertenece a ESTA fila (para poder verlo seleccionado / desvincularlo) —
// nunca un empleado ya vinculado a OTRO usuario.
function linkableEmployeesFor(responsible: Responsible): LinkableEmployee[] {
    return props.employees.filter((e) => e.linked_user_id === null || e.linked_user_id === responsible.id)
}

function editEmployeeLink(responsible: Responsible) {
    const options = linkableEmployeesFor(responsible)
    const optionsHtml = ['<option value="">— Sin vincular —</option>']
        .concat(options.map((e) => `<option value="${e.id}" ${e.id === responsible.employee_id ? 'selected' : ''}>${e.full_name}${e.is_active ? '' : ' (inactivo)'}</option>`))
        .join('')

    Swal.fire({
        title: `Colaborador vinculado — ${responsible.name}`,
        html: `<select id="employee-link-select" class="swal2-select" style="display:block;width:100%;">${optionsHtml}</select>`,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#4f46e5',
        preConfirm: () => {
            const select = document.getElementById('employee-link-select') as HTMLSelectElement

            return select.value ? Number(select.value) : null
        },
    }).then((r) => {
        if (r.isConfirmed) {
            router.put(`/okr/responsibles/${responsible.id}/employee`, { employee_id: r.value })
        }
    })
}

const roleLabels: Record<string, string> = { admin: 'Administrador', gerencial: 'Gerencial', colaborador: 'Colaborador' }
const roleOptions = ['admin', 'gerencial', 'colaborador'] as const

function changeRole(responsible: { id: number; name: string; role: string }, newRole: string) {
    if (newRole === responsible.role) {
return
}

    Swal.fire({
        icon: 'question', title: `¿Cambiar a ${responsible.name} a ${roleLabels[newRole]}?`,
        showCancelButton: true, confirmButtonText: 'Cambiar rol', cancelButtonText: 'Cancelar', confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (r.isConfirmed) {
router.put(`/okr/responsibles/${responsible.id}/role`, { role: newRole })
}
    })
}
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
            <AppPageHeader title="Usuarios y accesos" subtitle="Roles y acceso de cada persona en el sistema: Administrador, Gerencial o Colaborador. Agrega nuevas o revisa cuántos objetivos OKR tiene cada quien.">
                <template #actions>
                    <Button type="button" class="app-btn app-btn-primary h-11 gap-2" @click="showForm = !showForm">
                        <Plus class="size-4" /> Agregar usuario
                    </Button>
                </template>
            </AppPageHeader>
        </div>

        <div v-if="showForm" class="app-card animate-in fade-in slide-in-from-top-2 space-y-4 p-5 duration-200">
            <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                <UserPlus class="size-4 text-primary" /> Nuevo usuario
                <OkrHelpTooltip text="Se crea sin acceso todavía — un administrador debe habilitarlo explícitamente después de confirmar la identidad de la persona." />
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <TextField v-model="form.name" label="Nombre completo" placeholder="Ej. Ana López" :error="form.errors.name" />
                <TextField v-model="form.email" type="email" label="Correo" placeholder="ana@empresa.com" :error="form.errors.email" />
            </div>
            <p class="text-xs text-muted-foreground">Se crea como Colaborador. Para Administrador o Gerencial, cambia el rol después de crearlo.</p>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" class="h-10" @click="showForm = false">Cancelar</Button>
                <Button type="button" class="h-10" :disabled="form.processing" @click="submit">Guardar usuario</Button>
            </div>
        </div>

        <div class="app-table-wrap">
            <div class="app-table-toolbar">
                <div class="flex items-center gap-2">
                    <Users class="size-4 text-primary" />
                    <h2 class="text-sm font-bold text-foreground">{{ responsibles.length }} usuario(s)</h2>
                </div>
            </div>

            <div class="app-table-content">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Nombre</th>
                            <th class="px-4 py-3 text-left font-semibold">Correo</th>
                            <th class="px-4 py-3 text-left font-semibold">Rol</th>
                            <th class="px-4 py-3 text-left font-semibold">Acceso</th>
                            <th class="px-4 py-3 text-left font-semibold">Colaborador vinculado</th>
                            <th class="px-4 py-3 text-left font-semibold">Sucursal actual</th>
                            <th class="px-4 py-3 text-left font-semibold">OKR a cargo</th>
                            <th class="px-4 py-3 text-left font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in responsibles" :key="r.id" class="border-t border-border transition hover:bg-muted/30">
                            <td class="px-4 py-3 font-semibold text-foreground">{{ r.name }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ r.email }}</td>
                            <td class="px-4 py-3">
                                <select
                                    class="rounded-full border-0 px-2.5 py-1 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-primary/30"
                                    :class="[
                                        r.role === 'admin' ? 'bg-primary/10 text-primary' : r.role === 'gerencial' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-muted text-muted-foreground',
                                        canChangeRole(r) ? 'cursor-pointer' : 'cursor-default opacity-80',
                                    ]"
                                    :disabled="!canChangeRole(r)"
                                    :title="canChangeRole(r) ? 'Cambiar el rol' : (r.id === current_user_id ? 'No puedes cambiar tu propio rol' : 'Es el único administrador — no se puede quitar')"
                                    :value="r.role"
                                    @change="changeRole(r, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option v-for="opt in roleOptions" :key="opt" :value="opt">{{ roleLabels[opt] }}</option>
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="r.status === 'active' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'">
                                    {{ r.status === 'active' ? 'Activo' : 'Pendiente' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" class="text-left text-xs font-semibold hover:underline" :class="r.employee_name ? 'text-foreground' : 'text-muted-foreground italic'" @click="editEmployeeLink(r)">
                                    {{ r.employee_name ?? 'Sin vincular' }}
                                    <span v-if="r.employee_name && r.employee_is_active === false" class="text-rose-600">(inactivo)</span>
                                </button>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ r.current_branch ?? '—' }}</td>
                            <td class="px-4 py-3 font-semibold tabular-nums text-foreground">{{ r.objectives_count }}</td>
                            <td class="px-4 py-3">
                                <button v-if="r.status === 'pending'" type="button" class="text-xs font-bold text-primary hover:underline" @click="enableAccess(r)">Habilitar acceso</button>
                                <button v-else-if="r.id !== current_user_id" type="button" class="text-xs font-bold text-rose-600 hover:underline" @click="disableAccess(r)">Quitar acceso</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState v-if="responsibles.length === 0" title="Sin usuarios" message="Agrega el primero para poder asignarle un rol y un OKR." />
            </div>
        </div>
    </div>
</template>

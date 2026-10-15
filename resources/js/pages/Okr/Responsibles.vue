<script setup lang="ts">
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import { ArrowLeft, Ban, KeyRound, Mail, MailWarning, Plus, RotateCw, UserCheck, UserPlus, Users } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import TextField from '@/components/forms/TextField.vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import RoleActionPermissionCard from '@/components/okr/RoleActionPermissionCard.vue'
import RoleModuleAccessCard from '@/components/okr/RoleModuleAccessCard.vue'
import { Button } from '@/components/ui/button'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

type Responsible = {
    id: number; name: string; email: string; role: string; status: 'active' | 'pending'; disabled: boolean; objectives_count: number
    employee_id: number | null; employee_name: string | null; employee_is_active: boolean | null; current_branch: string | null
    welcome_email_sent_at: string | null; welcome_email_error: string | null
}
type LinkableEmployee = { id: number; full_name: string; is_active: boolean; linked_user_id: number | null }

const props = defineProps<{
    responsibles: Responsible[]
    employees: LinkableEmployee[]
    temp_password?: string | null
    reset_password?: { name: string; email: string; password: string } | null
    current_user_id: number
    admin_count: number
    module_catalog: { key: string; label: string; description: string }[]
    module_access: Record<string, string[]>
    action_catalog: { module: string; action: string; label: string }[]
    action_permissions: Record<string, Record<string, string[]>>
}>()

function resendWelcomeEmail(r: Responsible) {
    router.post(`/okr/responsibles/${r.id}/resend-welcome-email`, {}, { preserveScroll: true })
}

// Esta pantalla no tenía NINGÚN consumidor de flash (success/warning/error) —
// las acciones bloqueadas por el backend (ej. "no puedes quitar el único
// administrador") no mostraban nada visible al usuario (15-oct-2026).
// temp_password/reset_password ya tienen su propio diálogo (arriba) — este
// watcher no debe duplicarlo cuando 'success' viaja junto con cualquiera de los dos.
const page = usePage()
watch(() => (page.props as any).flash, (flash) => {
    if (flash?.error) {
        Swal.fire({ icon: 'error', title: 'No se pudo completar', text: flash.error })
    } else if (flash?.warning) {
        Swal.fire({ icon: 'warning', title: flash.success ?? 'Atención', text: flash.warning })
    } else if (flash?.success && !props.temp_password && !props.reset_password) {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: flash.success, timer: 3000, showConfirmButton: false })
    }
}, { deep: true })

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

// Contraseña restablecida por un admin — también UNA SOLA VEZ (session()->pull()).
watch(() => props.reset_password, (data) => {
    if (!data) {
        return
    }

    Swal.fire({
        icon: 'success',
        title: 'Contraseña restablecida',
        html: `<p style="margin-bottom:.5rem">Nueva contraseña de <b>${escapeHtml(data.name)}</b> (${escapeHtml(data.email)}).</p>
<p style="margin-bottom:.75rem;color:#b45309">Cópiala ahora: no se volverá a mostrar.</p>
<code style="display:block;font-size:1.25em;letter-spacing:.05em;padding:.6rem;border-radius:.5rem;background:#f1f5f9;user-select:all">${escapeHtml(data.password)}</code>
<p style="margin-top:.75rem;font-size:.85em;color:#64748b">Sus sesiones abiertas se cerraron. Puede cambiarla después en Configuración › Seguridad.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Copiar contraseña',
        cancelButtonText: 'Cerrar',
        confirmButtonColor: '#4f46e5',
        allowOutsideClick: false,
        preConfirm: async () => {
            try {
                await navigator.clipboard.writeText(data.password)
            } catch {
                Swal.showValidationMessage('No se pudo copiar automáticamente; selecciónala y cópiala a mano.')

                return false
            }

            return true
        },
    }).then((r) => {
        if (r.isConfirmed) {
            Swal.fire({ icon: 'success', title: 'Copiada al portapapeles', timer: 1600, showConfirmButton: false })
        }
    })
}, { immediate: true })

function escapeHtml(v: string): string {
    return v.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)
}

// Candado espejo del backend (toggleDisabled()): nunca el último admin habilitado.
const enabledAdmins = computed(() => props.responsibles.filter((u) => u.role === 'admin' && !u.disabled).length)

function canToggleDisabled(u: Responsible): boolean {
    return u.id !== props.current_user_id && (u.disabled || u.role !== 'admin' || enabledAdmins.value > 1)
}

function resetPassword(u: Responsible) {
    Swal.fire({
        icon: 'warning', title: `¿Restablecer la contraseña de ${u.name}?`,
        text: 'Se generará una contraseña nueva que verás una sola vez para copiarla. La actual dejará de funcionar y se cerrarán sus sesiones abiertas.',
        showCancelButton: true, confirmButtonText: 'Generar contraseña', cancelButtonText: 'Cancelar', confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (r.isConfirmed) {
            router.post(`/okr/responsibles/${u.id}/reset-password`, {}, { preserveScroll: true })
        }
    })
}

function toggleDisabled(u: Responsible) {
    Swal.fire({
        icon: u.disabled ? 'question' : 'warning',
        title: u.disabled ? `¿Habilitar la cuenta de ${u.name}?` : `¿Deshabilitar la cuenta de ${u.name}?`,
        text: u.disabled
            ? 'Podrá volver a iniciar sesión con su contraseña.'
            : 'No podrá iniciar sesión en ningún módulo y se cerrarán sus sesiones abiertas. Puedes revertirlo cuando quieras.',
        showCancelButton: true,
        confirmButtonText: u.disabled ? 'Habilitar cuenta' : 'Deshabilitar cuenta',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: u.disabled ? '#4f46e5' : '#dc2626',
    }).then((r) => {
        if (r.isConfirmed) {
            router.post(`/okr/responsibles/${u.id}/toggle-disabled`, {}, { preserveScroll: true })
        }
    })
}

const showForm = ref(false)
const showPassword = ref(false)
// D7 del cierre (17-sep-2026): esta pantalla NUNCA puede crear un admin — el rol
// siempre es 'colaborador' (el backend también lo fuerza, ver ResponsibleController::store()).
// password_mode (15-oct-2026, sección 10): 'auto' conserva el botón de generar
// contraseña automática que ya existía; 'manual' permite escribirla a mano.
const form = useForm({ name: '', email: '', password_mode: 'auto' as 'auto' | 'manual', password: '', password_confirmation: '' })

function submit() {
    form.post('/okr/responsibles', {
        onSuccess: () => {
            form.reset()
            showForm.value = false
        },
    })
}

// 05-oct-2026: acceso habilitado/deshabilitado y colaborador activo/inactivo
// son el MISMO estado acoplado (ver ResponsibleController::enableAccess/
// disableAccess) — el diálogo lo dice explícitamente solo cuando hay un
// Employee vinculado, para no confundir a un admin/gerencial sin colaborador.
function enableAccess(responsible: Responsible) {
    const employeeNote = responsible.employee_name ? ` Su colaborador vinculado (${responsible.employee_name}) también se reactivará.` : ''
    Swal.fire({
        icon: 'question', title: `¿Habilitar acceso para ${responsible.name}?`,
        text: `Confirma que ya verificaste la identidad de esta persona fuera del sistema.${employeeNote}`,
        showCancelButton: true, confirmButtonText: 'Habilitar', cancelButtonText: 'Cancelar', confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (r.isConfirmed) {
router.post(`/okr/responsibles/${responsible.id}/enable-access`)
}
    })
}

function disableAccess(responsible: Responsible) {
    const employeeNote = responsible.employee_name ? ` Su colaborador vinculado (${responsible.employee_name}) también quedará marcado como inactivo.` : ''
    Swal.fire({
        icon: 'warning', title: `¿Quitar acceso a ${responsible.name}?`,
        text: `No podrá entrar al módulo OKR hasta que se le vuelva a habilitar.${employeeNote}`,
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
                    <Button type="button" data-tour="usuarios-add" class="app-btn app-btn-primary h-11 gap-2" @click="showForm = !showForm">
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

            <div class="space-y-2">
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Contraseña inicial</p>
                <div class="flex gap-2">
                    <button type="button" class="flex-1 rounded-xl border px-3 py-2 text-left text-xs font-semibold transition" :class="form.password_mode === 'auto' ? 'border-primary/50 bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'" @click="form.password_mode = 'auto'">
                        Generar automáticamente
                        <span class="block text-[11px] font-normal text-muted-foreground">Se te muestra una sola vez para copiarla.</span>
                    </button>
                    <button type="button" class="flex-1 rounded-xl border px-3 py-2 text-left text-xs font-semibold transition" :class="form.password_mode === 'manual' ? 'border-primary/50 bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'" @click="form.password_mode = 'manual'">
                        Escribirla yo mismo
                        <span class="block text-[11px] font-normal text-muted-foreground">Tú la defines y la entregas.</span>
                    </button>
                </div>
                <div v-if="form.password_mode === 'manual'" class="grid gap-3 sm:grid-cols-2">
                    <div class="relative">
                        <TextField v-model="form.password" :type="showPassword ? 'text' : 'password'" label="Contraseña" placeholder="Mínimo 8 caracteres" :error="form.errors.password" />
                        <button type="button" class="absolute right-2 top-8 text-[11px] font-semibold text-primary hover:underline" @click="showPassword = !showPassword">{{ showPassword ? 'Ocultar' : 'Mostrar' }}</button>
                    </div>
                    <TextField v-model="form.password_confirmation" :type="showPassword ? 'text' : 'password'" label="Confirmar contraseña" placeholder="Repite la contraseña" />
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                Se crea como Colaborador. Para Administrador o Gerencial, cambia el rol después de crearlo.
                Independientemente de la contraseña, se le envía un correo con un enlace propio para entrar la primera vez.
            </p>
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
                            <th class="px-4 py-3 text-left font-semibold">Correo de bienvenida</th>
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
                                <span v-if="r.disabled" class="inline-flex items-center rounded-full bg-rose-500/10 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-300">Deshabilitado</span>
                                <span v-else class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="r.status === 'active' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'">
                                    {{ r.status === 'active' ? 'Activo' : 'Pendiente' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="r.welcome_email_sent_at" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300" :title="r.welcome_email_sent_at"><Mail class="size-3.5" /> Enviado</span>
                                <span v-else-if="r.welcome_email_error" class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-300" :title="r.welcome_email_error"><MailWarning class="size-3.5" /> No se pudo enviar</span>
                                <span v-else class="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground">Pendiente</span>
                                <button type="button" class="ml-1.5 inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground" title="Reenviar correo de bienvenida" :aria-label="`Reenviar correo de bienvenida a ${r.name}`" @click="resendWelcomeEmail(r)">
                                    <RotateCw class="size-3.5" />
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" class="text-left text-xs font-semibold hover:underline" :class="r.employee_name ? 'text-foreground' : 'text-muted-foreground italic'" @click="editEmployeeLink(r)">
                                    {{ r.employee_name ?? 'Sin vincular' }}
                                    <span v-if="r.employee_name && r.employee_is_active === false" class="text-rose-600">(inactivo)</span>
                                </button>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ r.current_branch ?? '—' }}</td>
                            <td class="px-4 py-3 font-semibold tabular-nums text-foreground">{{ r.objectives_count }}</td>
                            <td class="space-y-1 px-4 py-3">
                                <button v-if="r.status === 'pending'" type="button" class="text-xs font-bold text-primary hover:underline" @click="enableAccess(r)">Habilitar acceso</button>
                                <button v-else-if="r.id !== current_user_id" type="button" class="text-xs font-bold text-rose-600 hover:underline" @click="disableAccess(r)">Quitar acceso</button>
                                <div v-if="r.id !== current_user_id" class="flex items-center gap-1">
                                    <button type="button" class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground" title="Restablecer contraseña" :aria-label="`Restablecer contraseña de ${r.name}`" @click="resetPassword(r)">
                                        <KeyRound class="size-4" />
                                    </button>
                                    <button
                                        v-if="canToggleDisabled(r)"
                                        type="button"
                                        class="inline-flex size-7 items-center justify-center rounded-md transition hover:bg-muted"
                                        :class="r.disabled ? 'text-emerald-600' : 'text-rose-600'"
                                        :title="r.disabled ? 'Habilitar cuenta' : 'Deshabilitar cuenta'"
                                        :aria-label="`${r.disabled ? 'Habilitar' : 'Deshabilitar'} cuenta de ${r.name}`"
                                        @click="toggleDisabled(r)"
                                    >
                                        <UserCheck v-if="r.disabled" class="size-4" />
                                        <Ban v-else class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState v-if="responsibles.length === 0" title="Sin usuarios" message="Agrega el primero para poder asignarle un rol y un OKR." />
            </div>
        </div>

        <RoleModuleAccessCard :modules="module_catalog" :access="module_access" />
        <RoleActionPermissionCard :actions="action_catalog" :permissions="action_permissions" />
    </div>
</template>

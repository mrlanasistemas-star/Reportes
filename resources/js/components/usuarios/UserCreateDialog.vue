<script setup lang="ts">
// Alta de usuario (prioridad 8): nombre, correo, rol (incluye personalizados),
// colaborador vinculado (define su sucursal/alcance), y cómo obtiene su
// contraseña: invitación por correo, generada (se muestra una vez) o manual.
import { useForm } from '@inertiajs/vue3'
import { Check, Loader2, Mail, KeyRound, Sparkles, UserPlus } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import BranchScopePicker from './BranchScopePicker.vue'
import PasswordFields from './PasswordFields.vue'
import type { BranchOption, BranchScopeMode, LinkableEmployee, UserRoleOption } from './users'

const props = defineProps<{
    storeUrl: string
    roles: UserRoleOption[]
    employees: LinkableEmployee[]
    canInvite: boolean
    canManageAccess: boolean
    invitationExpiresMinutes: number
    rolesUrl: string | null
    /** Fase 6.4 — sucursales que el actor puede conceder y si puede conceder «Todas». */
    scopeBranches: BranchOption[]
    canGrantAllBranches: boolean
}>()
const open = defineModel<boolean>('open', { default: false })

type Mode = 'invite' | 'auto' | 'manual'
const form = useForm({
    name: '', email: '', role: 'colaborador', employee_id: null as number | null,
    password_mode: 'invite' as Mode, password: '', password_confirmation: '',
    send_invitation: true, enable_access: false,
    branch_scope: 'all' as BranchScopeMode, branch_ids: [] as number[],
})

const assignable = computed(() => props.roles.filter((r) => r.assignable))
// Una entrada por PERSONA (ya viene deduplicada del backend por identidad
// canónica). El secundario muestra y permite buscar por NOI · sucursal · puesto.
const freeEmployees = computed(() => props.employees
    .filter((e) => e.linked_user_id === null)
    .map((e) => ({
        id: e.id,
        nombre: e.full_name,
        codigo: [e.employee_code ? `NOI ${e.employee_code}` : null, e.current_branch, e.position, e.is_active ? null : 'inactivo']
            .filter(Boolean)
            .join(' · '),
    })))
const selectedRole = computed(() => props.roles.find((r) => r.key === form.role))

const modes = computed(() => [
    { key: 'invite' as Mode, icon: Mail, title: 'Enviar por correo', hint: 'Recibe sus credenciales (contraseña temporal) por correo; también la verás aquí una vez.', disabled: !props.canInvite },
    { key: 'auto' as Mode, icon: Sparkles, title: 'Generar contraseña', hint: 'Se te muestra una sola vez para que la entregues.', disabled: false },
    { key: 'manual' as Mode, icon: KeyRound, title: 'Escribir contraseña', hint: 'Tú la defines (con confirmación) y la entregas.', disabled: false },
])

watch(open, (v) => {
    if (v) {
        form.reset()
        form.clearErrors()
        form.password_mode = props.canInvite ? 'invite' : 'auto'
        form.role = assignable.value.find((r) => r.key === 'colaborador')?.key ?? assignable.value[0]?.key ?? 'colaborador'
        // Restringido: por defecto sus propias sucursales (nunca «todas»).
        form.branch_scope = props.canGrantAllBranches ? 'all' : 'branches'
        form.branch_ids = props.canGrantAllBranches ? [] : props.scopeBranches.map((b) => b.id)
    }
})

const canSubmit = computed(() => form.name.trim() !== '' && form.email.trim() !== ''
    && (form.password_mode !== 'manual' || (form.password.length >= 8 && form.password === form.password_confirmation))
    && (form.branch_scope === 'all' || form.branch_ids.length > 0))

function submit() {
    form.transform((d) => ({ ...d, send_invitation: d.password_mode === 'invite' ? true : d.send_invitation && props.canInvite }))
        .post(props.storeUrl, { preserveScroll: true, onSuccess: () => {
            open.value = false
        } })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-2xl overflow-y-auto p-0 sm:max-w-2xl">
            <div class="border-b border-border bg-card px-6 py-5">
                <DialogTitle class="flex items-center gap-2 text-lg font-semibold text-foreground"><UserPlus class="size-5 text-primary" aria-hidden="true" /> Nuevo usuario</DialogTitle>
                <DialogDescription class="mt-1 text-sm text-muted-foreground">Crea la cuenta con su rol. Puedes activar su acceso ahora o después, desde la tabla.</DialogDescription>
            </div>

            <div class="space-y-5 p-6">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-xs font-bold text-foreground">Nombre completo</span>
                        <input v-model="form.name" maxlength="191" placeholder="Ej. Ana López" class="app-input h-10 text-sm">
                        <span v-if="form.errors.name" class="app-field-error" role="alert">{{ form.errors.name }}</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-bold text-foreground">Correo</span>
                        <input v-model="form.email" type="email" maxlength="191" placeholder="ana@empresa.com" class="app-input h-10 text-sm">
                        <span v-if="form.errors.email" class="app-field-error" role="alert">{{ form.errors.email }}</span>
                    </label>
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-foreground">Rol</p>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="r in assignable" :key="r.key" type="button"
                            class="flex items-start gap-2 rounded-xl border p-2.5 text-left transition"
                            :class="form.role === r.key ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/30'"
                            @click="form.role = r.key"
                        >
                            <Check class="mt-0.5 size-3.5 shrink-0" :class="form.role === r.key ? 'text-primary' : 'text-transparent'" />
                            <span class="min-w-0">
                                <span class="block text-xs font-bold text-foreground">{{ r.name }}</span>
                                <span class="block truncate text-[11px] text-muted-foreground">{{ r.modules_count }} módulo(s) · nivel {{ r.base_level }}</span>
                            </span>
                        </button>
                    </div>
                    <p v-if="form.errors.role" class="app-field-error" role="alert">{{ form.errors.role }}</p>
                    <p class="text-[11px] text-muted-foreground">
                        Administrador no se asigna aquí: se cambia después desde la tabla.
                        <a v-if="rolesUrl" :href="rolesUrl" class="font-semibold text-primary hover:underline">¿Falta un rol? Créalo en Roles y permisos.</a>
                    </p>
                </div>

                <div class="space-y-2">
                    <SearchableSelect
                        v-model="form.employee_id" :options="freeEmployees" label="Colaborador vinculado (opcional)"
                        placeholder="Sin vincular" search-placeholder="Buscar colaborador…" allow-null null-label="Sin vincular"
                        :error="form.errors.employee_id"
                    />
                    <p class="rounded-xl bg-muted/50 px-3 py-2 text-[11px] leading-4 text-muted-foreground">
                        <strong class="text-foreground">Alcance:</strong>
                        {{ selectedRole?.base_level === 'gerencial' ? 'nivel gerencial — ve la información de las sucursales que elijas abajo en los módulos de su rol.' : 'nivel colaborador — en OKR solo ve lo propio (sus OKR y documentos); en otros módulos, las sucursales que elijas abajo.' }}
                    </p>
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-foreground">Alcance por sucursal</p>
                    <BranchScopePicker
                        v-model:mode="form.branch_scope" v-model:branch-ids="form.branch_ids" :branches="scopeBranches" :can-grant-all="canGrantAllBranches"
                        :error="form.errors.branch_ids || form.errors.branch_scope"
                    />
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-foreground">Contraseña inicial</p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="m in modes" :key="m.key" type="button" :disabled="m.disabled"
                            class="rounded-xl border p-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                            :class="form.password_mode === m.key ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/30'"
                            @click="form.password_mode = m.key"
                        >
                            <span class="flex items-center gap-1.5 text-xs font-bold text-foreground"><component :is="m.icon" class="size-3.5 text-primary" /> {{ m.title }}</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-muted-foreground">{{ m.hint }}</span>
                        </button>
                    </div>
                    <PasswordFields v-if="form.password_mode === 'manual'" v-model:password="form.password" v-model:confirmation="form.password_confirmation" :error="form.errors.password" />
                    <label v-if="form.password_mode !== 'invite' && canInvite" class="flex cursor-pointer items-center gap-2 text-xs text-foreground">
                        <input v-model="form.send_invitation" type="checkbox" class="accent-primary">
                        Enviar también el correo de bienvenida con sus credenciales (contraseña temporal)
                    </label>
                </div>

                <label v-if="canManageAccess" class="flex cursor-pointer items-start gap-2 rounded-xl border border-border p-3 text-xs text-foreground">
                    <input v-model="form.enable_access" type="checkbox" class="mt-0.5 accent-primary">
                    <span><strong>Activar su acceso de inmediato.</strong> Hazlo solo si ya confirmaste la identidad de la persona; si no, quedará como «pendiente de activación».</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <Button type="button" variant="outline" class="h-10 rounded-xl" @click="open = false">Cancelar</Button>
                <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="form.processing || !canSubmit" @click="submit">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" /> Crear usuario
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>

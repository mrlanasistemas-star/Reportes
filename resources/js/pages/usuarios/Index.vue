<script setup lang="ts">
// Módulo USUARIOS (prioridad 8/10) — página orquestadora: secciones, filtros y
// diálogos viven en components/usuarios/*. Las acciones usan las URLs que
// genera el backend y el backend vuelve a autorizar cada una.
import { Head, router } from '@inertiajs/vue3'
import { FileSpreadsheet, Plus, ShieldCheck } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import { Button } from '@/components/ui/button'
import BranchScopeDialog from '@/components/usuarios/BranchScopeDialog.vue'
import BulkPasswordBar from '@/components/usuarios/BulkPasswordBar.vue'
import ResetPasswordDialog from '@/components/usuarios/ResetPasswordDialog.vue'
import { useAdminFlash } from '@/components/usuarios/useAdminFlash'
import UserAuditLog from '@/components/usuarios/UserAuditLog.vue'
import UserCreateDialog from '@/components/usuarios/UserCreateDialog.vue'
import UserFilters from '@/components/usuarios/UserFilters.vue'
import UserImportDialog from '@/components/usuarios/UserImportDialog.vue'
import type { AccessState, AuditEntry, BranchOption, LinkableEmployee, UserDataScope, UserRoleOption, UserRow } from '@/components/usuarios/users'
import UsersTable from '@/components/usuarios/UsersTable.vue'
import UserSummaryCards from '@/components/usuarios/UserSummaryCards.vue'
import { useUserActions } from '@/components/usuarios/useUserActions'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    users: UserRow[]
    employees: LinkableEmployee[]
    roles: UserRoleOption[]
    audit: AuditEntry[]
    current_user_id: number
    enabled_admin_count: number
    invitation_expires_minutes: number
    store_url: string
    roles_url: string | null
    scope_branches: BranchOption[]
    actor_scope: UserDataScope
    environment: string
    import_urls: { preview: string; confirm: string; credentials: string } | null
    can: { create: boolean; invite: boolean; manage_access: boolean; import: boolean; bulk_reset_password: boolean }
    bulk_password_urls: { assign: string; download: string }
    bulk_password_result?: { updated: number; skipped: number } | null
    temp_password?: { name: string; email: string; password: string } | null
    reset_password?: { name: string; email: string; password: string } | null
    invitation_result?: { sent: boolean; message: string; email: string } | null
    import_preview?: ImportPreview | null
    import_result?: ImportResult | null
}>()

type ImportPreviewRow = { noi: string | null; name: string; branch_name: string; branch_id: number | null; position: string; email: string; role: string; status: 'create' | 'needs_review' | 'exists' | 'error'; observations: string[] }
type ImportPreview = { environment: string; original_name: string; summary: Record<string, number>; rows: ImportPreviewRow[] }
type ImportResultRow = { name: string; branch: string; position: string; email: string; creation: string; access: string; invitation: string; observations: string }
type ImportResult = { summary: Record<string, number>; rows: ImportResultRow[]; has_credentials: boolean }

type Section = 'resumen' | 'activos' | 'pendientes' | 'invitaciones' | 'administracion' | 'historial'
const SECTIONS: { key: Section; label: string }[] = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'activos', label: 'Activos' },
    { key: 'pendientes', label: 'Accesos pendientes' },
    { key: 'invitaciones', label: 'Invitaciones' },
    { key: 'administracion', label: 'Administración' },
    { key: 'historial', label: 'Historial' },
]

function readSection(): Section {
    const value = new URLSearchParams(window.location.search).get('seccion') as Section | null

    return SECTIONS.some((s) => s.key === value) ? (value as Section) : 'resumen'
}

const section = ref<Section>(readSection())

function setSection(value: Section) {
    section.value = value
    const url = new URL(window.location.href)
    url.searchParams.set('seccion', value)
    window.history.replaceState(window.history.state, '', url)
}

const search = ref('')
const roleFilter = ref('')
const accessFilter = ref<'' | AccessState>('')
const branchFilter = ref('')

const branches = computed(() => [...new Set(props.users.map((u) => u.current_branch).filter((b): b is string => !!b))].sort())

function inSection(u: UserRow, key: Section): boolean {
    switch (key) {
        case 'activos': return u.access === 'enabled'
        case 'pendientes': return u.access === 'pending'
        // Estado de ENTREGA de la invitación únicamente (pendiente o fallida). No se
        // mezcla con `last_login_at`: que el correo fuera aceptado por el servidor y que
        // la persona haya entrado alguna vez son cosas distintas (una invitación aceptada
        // de alguien que aún no inicia sesión NO es un problema de envío).
        case 'invitaciones': return u.invitation.status !== 'accepted'
        case 'administracion': return u.role === 'admin' || u.access === 'blocked'
        default: return true
    }
}

const sectionUsers = computed(() => props.users.filter((u) => inSection(u, section.value)))

const visibleUsers = computed(() => {
    const q = search.value.trim().toLowerCase()

    return sectionUsers.value.filter((u) => (q === '' || `${u.name} ${u.email} ${u.employee_name ?? ''}`.toLowerCase().includes(q))
        && (roleFilter.value === '' || u.role === roleFilter.value)
        && (accessFilter.value === '' || u.access === accessFilter.value)
        && (branchFilter.value === '' || (branchFilter.value === '__none__' ? !u.current_branch : u.current_branch === branchFilter.value)))
})

const sectionCount = (key: Section) => (key === 'historial' ? props.audit.length : props.users.filter((u) => inSection(u, key)).length)

const actions = useUserActions()
const createOpen = ref(false)
const importOpen = ref(false)

// La vista previa o el resultado llegan como props (flash de sesión): abrir el
// diálogo de importación cuando aparezcan, para continuar el flujo en su paso.
watch(() => [props.import_preview, props.import_result] as const, ([p, r]) => {
    if (p || r) {
        importOpen.value = true
    }
}, { immediate: true })
const resetOpen = ref(false)
const resetTarget = ref<UserRow | null>(null)
const scopeOpen = ref(false)
const scopeTarget = ref<UserRow | null>(null)

function openScope(u: UserRow) {
    scopeTarget.value = u
    scopeOpen.value = true
}

function openReset(u: UserRow) {
    resetTarget.value = u
    resetOpen.value = true
}

async function resend(u: UserRow) {
    const invitation = await actions.resendInvitation(u)

    if (invitation) {
        router.reload({ only: ['users', 'audit'] })
    }
}

useAdminFlash(() => !!props.temp_password || !!props.reset_password || !!props.invitation_result || !!props.bulk_password_result)

// Resultado del alta: contraseña generada (una sola vez) y/o estado REAL de la invitación.
watch(() => [props.temp_password, props.invitation_result] as const, ([pwd, inv]) => {
    if (!pwd && !inv) {
        return
    }

    if (pwd) {
        // La contraseña va primero (una sola vez); el estado de la invitación se anota debajo.
        actions.showGeneratedPassword('Usuario creado', pwd, inv?.message)

        return
    }

    if (inv) {
        Swal.fire({ icon: inv.sent ? 'success' : 'warning', title: 'Usuario creado', text: inv.message, confirmButtonColor: '#4f46e5' })
    }
}, { immediate: true })

// Selección para la asignación masiva: se depuran ids que ya no existen tras recargar.
const selectedIds = ref<number[]>([])
watch(() => props.users, (users) => {
    const ids = new Set(users.filter((u) => u.can.reset_password).map((u) => u.id))
    selectedIds.value = selectedIds.value.filter((id) => ids.has(id))
})

// Resultado de la asignación masiva: el Excel se descarga UNA sola vez.
watch(() => props.bulk_password_result, (result) => {
    if (!result) {
        return
    }

    const skippedNote = result.skipped > 0 ? `<p style="margin-top:.5rem;font-size:.9em;color:#475569">${result.skipped} seleccionado(s) se omitieron (tu propia cuenta o usuarios que tu rol no administra).</p>` : ''
    Swal.fire({
        icon: 'success',
        title: `Contraseñas asignadas: ${result.updated}`,
        html: `<p>Descarga ahora el Excel con nombre, usuario, contraseña, correo y sucursal.</p>
<p style="margin-top:.5rem;color:#b45309">Solo se puede descargar una vez; si lo pierdes, tendrás que volver a asignarlas.</p>${skippedNote}`,
        confirmButtonText: 'Descargar Excel', showCancelButton: true, cancelButtonText: 'Cerrar', confirmButtonColor: '#4f46e5', allowOutsideClick: false,
    }).then((r) => {
        if (r.isConfirmed) {
            window.location.href = props.bulk_password_urls.download
        }
    })
}, { immediate: true })

watch(() => props.reset_password, (data) => {
    if (data) {
        actions.showGeneratedPassword('Contraseña restablecida', data)
    }
}, { immediate: true })
</script>

<template>
    <Head title="Usuarios" />
    <div class="w-full space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        <AppPageHeader kicker="Administración" title="Usuarios" subtitle="Quién puede entrar al sistema, con qué rol y en qué estado está su acceso. Administrar el acceso nunca cambia la situación laboral del colaborador.">
            <template #actions>
                <a v-if="roles_url" :href="roles_url" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-4 text-sm font-semibold text-foreground shadow-soft-xs transition hover:bg-muted">
                    <ShieldCheck class="size-4" /> Roles y permisos
                </a>
                <Button v-if="can.import && import_urls" type="button" variant="outline" class="h-10 gap-2 px-4" @click="importOpen = true">
                    <FileSpreadsheet class="size-4" /> Importar usuarios
                </Button>
                <Button v-if="can.create" type="button" data-tour="usuarios-add" class="h-10 gap-2 px-4" @click="createOpen = true">
                    <Plus class="size-4" /> Nuevo usuario
                </Button>
            </template>
        </AppPageHeader>

        <nav class="scrollbar-none flex gap-1 overflow-x-auto border-b border-border" aria-label="Secciones de usuarios" role="tablist">
            <button
                v-for="s in SECTIONS" :key="s.key" type="button"
                role="tab" :aria-selected="section === s.key"
                class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors"
                :class="section === s.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'"
                @click="setSection(s.key)"
            >
                {{ s.label }} <span class="rounded-full px-1.5 py-0.5 text-[11px] tabular-nums" :class="section === s.key ? 'bg-primary/10 text-primary' : 'bg-muted'">{{ sectionCount(s.key) }}</span>
            </button>
        </nav>

        <UserAuditLog v-if="section === 'historial'" :entries="audit" />

        <template v-else>
            <UserSummaryCards v-if="section === 'resumen'" :users="users" />
            <UserFilters v-model:search="search" v-model:role="roleFilter" v-model:access="accessFilter" v-model:branch="branchFilter" :roles="roles" :branches="branches" />
            <BulkPasswordBar v-if="can.bulk_reset_password" v-model:selected="selectedIds" :assign-url="bulk_password_urls.assign" />
            <UsersTable
                v-model:selected="selectedIds" :selectable="can.bulk_reset_password"
                :users="visibleUsers" :resending-id="actions.resendingId.value"
                @enable="actions.enableAccess" @revoke="actions.revokeAccess" @toggle-blocked="actions.toggleBlocked"
                @reset="openReset" @resend="resend" @edit-scope="openScope"
                @change-role="(u) => actions.changeRole(u, roles)" @link-employee="(u) => actions.linkEmployee(u, employees)"
            />
            <p class="app-help">
                «Aceptada por el servidor de correo» significa que el servidor tomó el mensaje para enviarlo; no confirma que la persona lo haya recibido.
                El correo de bienvenida entrega una contraseña temporal; si no llegó, puedes reenviarla (se genera una nueva).
            </p>
        </template>

        <UserCreateDialog
            v-model:open="createOpen" :store-url="store_url" :roles="roles" :employees="employees" :can-invite="can.invite"
            :can-manage-access="can.manage_access" :invitation-expires-minutes="invitation_expires_minutes" :roles-url="roles_url"
            :scope-branches="scope_branches" :can-grant-all-branches="actor_scope.general"
        />
        <BranchScopeDialog v-model:open="scopeOpen" :user="scopeTarget" :branches="scope_branches" :can-grant-all="actor_scope.general" />
        <ResetPasswordDialog v-model:open="resetOpen" :user="resetTarget" />
        <UserImportDialog
            v-if="can.import && import_urls"
            v-model:open="importOpen" :urls="import_urls" :environment="environment"
            :preview="import_preview ?? null" :result="import_result ?? null" :can-invite="can.invite"
        />
    </div>
</template>

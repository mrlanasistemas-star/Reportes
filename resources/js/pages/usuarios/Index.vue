<script setup lang="ts">
// Módulo USUARIOS (prioridad 8/10) — página orquestadora: secciones, filtros y
// diálogos viven en components/usuarios/*. Las acciones usan las URLs que
// genera el backend y el backend vuelve a autorizar cada una.
import { Head, router } from '@inertiajs/vue3'
import { Plus, ShieldCheck } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import { Button } from '@/components/ui/button'
import ResetPasswordDialog from '@/components/usuarios/ResetPasswordDialog.vue'
import { useAdminFlash } from '@/components/usuarios/useAdminFlash'
import UserAuditLog from '@/components/usuarios/UserAuditLog.vue'
import UserCreateDialog from '@/components/usuarios/UserCreateDialog.vue'
import UserFilters from '@/components/usuarios/UserFilters.vue'
import type { AccessState, AuditEntry, LinkableEmployee, UserRoleOption, UserRow } from '@/components/usuarios/users'
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
    can: { create: boolean; invite: boolean; manage_access: boolean }
    temp_password?: { name: string; email: string; password: string } | null
    reset_password?: { name: string; email: string; password: string } | null
    invitation_result?: { sent: boolean; message: string; email: string } | null
}>()

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
        // Invitación sin confirmar por el servidor de correo, o que nunca se usó para entrar.
        case 'invitaciones': return u.invitation.status !== 'accepted' || !u.last_login_at
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
const resetOpen = ref(false)
const resetTarget = ref<UserRow | null>(null)

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

useAdminFlash(() => !!props.temp_password || !!props.reset_password || !!props.invitation_result)

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

watch(() => props.reset_password, (data) => {
    if (data) {
        actions.showGeneratedPassword('Contraseña restablecida', data)
    }
}, { immediate: true })
</script>

<template>
    <Head title="Usuarios" />
    <div class="w-full space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        <AppPageHeader title="Usuarios" subtitle="Quién puede entrar al sistema, con qué rol y en qué estado está su acceso. Administrar el acceso nunca cambia la situación laboral del colaborador.">
            <template #actions>
                <a v-if="roles_url" :href="roles_url" class="inline-flex h-11 items-center gap-2 rounded-xl border border-border px-4 text-sm font-semibold text-foreground transition hover:bg-muted">
                    <ShieldCheck class="size-4" /> Roles y permisos
                </a>
                <Button v-if="can.create" type="button" data-tour="usuarios-add" class="app-btn app-btn-primary h-11 gap-2" @click="createOpen = true">
                    <Plus class="size-4" /> Nuevo usuario
                </Button>
            </template>
        </AppPageHeader>

        <nav class="flex gap-1 overflow-x-auto rounded-2xl border border-border bg-muted/40 p-1" aria-label="Secciones de usuarios">
            <button
                v-for="s in SECTIONS" :key="s.key" type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-bold transition"
                :class="section === s.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                @click="setSection(s.key)"
            >
                {{ s.label }} <span class="rounded-full bg-muted px-1.5 text-[10px] tabular-nums">{{ sectionCount(s.key) }}</span>
            </button>
        </nav>

        <UserAuditLog v-if="section === 'historial'" :entries="audit" />

        <template v-else>
            <UserSummaryCards v-if="section === 'resumen'" :users="users" />
            <UserFilters v-model:search="search" v-model:role="roleFilter" v-model:access="accessFilter" v-model:branch="branchFilter" :roles="roles" :branches="branches" />
            <UsersTable
                :users="visibleUsers" :resending-id="actions.resendingId.value"
                @enable="actions.enableAccess" @revoke="actions.revokeAccess" @toggle-blocked="actions.toggleBlocked"
                @reset="openReset" @resend="resend"
                @change-role="(u) => actions.changeRole(u, roles)" @link-employee="(u) => actions.linkEmployee(u, employees)"
            />
            <p class="text-[11px] text-muted-foreground">
                «Aceptada por el servidor de correo» significa que el servidor tomó el mensaje para enviarlo; no confirma que la persona lo haya recibido.
                El enlace de la invitación vence en {{ invitation_expires_minutes }} minutos; si venció, reenvíala.
            </p>
        </template>

        <UserCreateDialog
            v-model:open="createOpen" :store-url="store_url" :roles="roles" :employees="employees" :can-invite="can.invite"
            :can-manage-access="can.manage_access" :invitation-expires-minutes="invitation_expires_minutes" :roles-url="roles_url"
        />
        <ResetPasswordDialog v-model:open="resetOpen" :user="resetTarget" />
    </div>
</template>

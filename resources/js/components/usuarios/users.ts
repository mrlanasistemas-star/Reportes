// Tipos y utilidades del módulo Usuarios (prioridad 8/10).
export type AccessState = 'enabled' | 'pending' | 'blocked'
export type InvitationStatus = 'pending' | 'accepted' | 'failed'

export type Invitation = {
    status: InvitationStatus
    sent_at: string | null
    error: string | null
    attempts: number
    last_attempt_at: string | null
}

export type UserRow = {
    id: number
    name: string
    email: string
    role: string
    role_name: string
    access: AccessState
    access_enabled_at: string | null
    disabled_at: string | null
    last_login_at: string | null
    created_at: string | null
    invitation: Invitation
    objectives_count: number
    employee_id: number | null
    employee_name: string | null
    employee_is_active: boolean | null
    current_branch: string | null
    /** Fase 6.4 — alcance por sucursal (DataScopeService::describe). */
    data_scope: UserDataScope
    is_self: boolean
    is_last_admin: boolean
    urls: {
        enable_access: string
        disable_access: string
        toggle_disabled: string
        reset_password: string
        resend_invitation: string
        update_role: string
        link_employee: string
        update_branch_scope: string
    }
    can: { manage_access: boolean; reset_password: boolean; invite: boolean; change_role: boolean; link_employee: boolean; change_branch_scope: boolean }
}

export type BranchScopeMode = 'all' | 'branches'
export type BranchOption = { id: number; name: string }
export type UserDataScope = { mode: 'admin' | 'all' | 'branches' | 'legacy'; general: boolean; branch_ids: number[]; label: string }

export type UserRoleOption = {
    key: string
    name: string
    description: string | null
    base_level: string
    is_system: boolean
    modules_count: number
    assignable: boolean
    grantable: boolean
}

export type LinkableEmployee = {
    id: number
    full_name: string
    /** Número de empleado / clave (NOI o código de directorio). */
    employee_code: string | null
    position: string | null
    is_active: boolean
    current_branch: string | null
    linked_user_id: number | null
    /** Nº de registros históricos que esta identidad canónica consolida. */
    records: number
}

export type AuditEntry = {
    id: number
    type: 'user' | 'role'
    action: string
    label: string
    actor: string
    target: string
    old_value: string | null
    new_value: string | null
    detail: string | null
    at: string
}

export const ACCESS_META: Record<AccessState, { label: string; hint: string; tone: string }> = {
    enabled: { label: 'Habilitado', hint: 'Puede iniciar sesión y usar los módulos de su rol.', tone: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' },
    pending: { label: 'Pendiente de activación', hint: 'Puede iniciar sesión, pero el módulo OKR queda bloqueado hasta que se active su acceso.', tone: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' },
    blocked: { label: 'Bloqueado', hint: 'No puede iniciar sesión en ningún módulo.', tone: 'bg-rose-500/10 text-rose-700 dark:text-rose-300' },
}

export const INVITATION_META: Record<InvitationStatus, { label: string; tone: string }> = {
    pending: { label: 'Invitación pendiente', tone: 'bg-muted text-muted-foreground' },
    accepted: { label: 'Aceptada por el servidor de correo', tone: 'bg-sky-500/10 text-sky-700 dark:text-sky-300' },
    failed: { label: 'Error de envío', tone: 'bg-rose-500/10 text-rose-700 dark:text-rose-300' },
}

export function formatDateTime(value: string | null): string {
    if (!value) {
        return '—'
    }

    const date = new Date(value.replace(' ', 'T'))

    if (Number.isNaN(date.getTime())) {
        return value
    }

    return date.toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export function escapeHtml(v: string): string {
    return v.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)
}

/** Contraseña aleatoria legible (sin caracteres ambiguos) para el botón "Generar". */
export function generatePassword(length = 14): string {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    const bytes = new Uint32Array(length)
    crypto.getRandomValues(bytes)

    return Array.from(bytes, (b) => chars[b % chars.length]).join('')
}

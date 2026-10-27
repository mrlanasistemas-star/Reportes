<script setup lang="ts">
// Tarjeta de un usuario (módulo Usuarios): rol y alcance, estado de acceso,
// invitación, colaborador vinculado, último acceso y menú de acciones — solo
// las que el rol del actor permite (el backend vuelve a validar cada una).
import { Ban, Building2, Clock, KeyRound, Link2, Loader2, MailWarning, MapPinned, MoreHorizontal, RotateCw, ShieldCheck, ShieldOff, UserCheck, UserCog } from 'lucide-vue-next'
import { computed } from 'vue'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { ACCESS_META, INVITATION_META, formatDateTime } from './users'
import type { UserRow } from './users'

const props = defineProps<{ user: UserRow; resendingId: number | null; selectable?: boolean; selected?: boolean }>()
const emit = defineEmits<{
    toggleSelect: [number]
    enable: [UserRow]; revoke: [UserRow]; toggleBlocked: [UserRow]; reset: [UserRow]
    resend: [UserRow]; changeRole: [UserRow]; linkEmployee: [UserRow]; editScope: [UserRow]
}>()

const u = computed(() => props.user)

const initials = computed(() => u.value.name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('') || '?')

const hasActions = computed(() => {
    const c = u.value.can

    return c.manage_access || c.reset_password || c.change_role || c.link_employee || c.invite || c.change_branch_scope
})

const noActionsHint = computed(() => (u.value.is_self
    ? 'Tu propia cuenta se administra en Configuración.'
    : u.value.is_last_admin ? 'Es el único administrador habilitado.' : 'Tu rol no permite modificar este usuario.'))
</script>

<template>
    <article
        class="app-card app-hover-lift flex h-full flex-col gap-4 p-4 hover:border-primary/30"
        :class="selected ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/20' : ''"
    >
        <!-- Encabezado: selección, identidad y acciones -->
        <header class="flex items-start gap-3">
            <input
                v-if="selectable"
                type="checkbox" class="mt-2.5 size-4 shrink-0 accent-primary" :checked="selected" :disabled="!u.can.reset_password"
                :title="u.can.reset_password ? '' : 'No puedes cambiar la contraseña de este usuario.'" :aria-label="`Seleccionar a ${u.name}`"
                @change="emit('toggleSelect', u.id)"
            >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-black" :class="u.role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-muted text-foreground'" aria-hidden="true">
                {{ initials }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-1.5 font-semibold text-foreground">
                    <span class="truncate" :title="u.name">{{ u.name }}</span>
                    <span v-if="u.is_self" class="shrink-0 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-bold text-primary">Tú</span>
                </p>
                <p class="truncate text-xs text-muted-foreground" :title="u.email">{{ u.email }}</p>
            </div>
            <DropdownMenu v-if="hasActions">
                <DropdownMenuTrigger as-child>
                    <button type="button" class="-mr-1 inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground" :aria-label="`Acciones para ${u.name}`">
                        <MoreHorizontal class="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-60">
                    <template v-if="u.can.manage_access">
                        <DropdownMenuItem v-if="u.access === 'pending'" @click="emit('enable', u)"><UserCheck class="size-4" /> Activar acceso</DropdownMenuItem>
                        <DropdownMenuItem v-if="u.access === 'enabled'" @click="emit('revoke', u)"><ShieldOff class="size-4" /> Revocar activación</DropdownMenuItem>
                        <DropdownMenuItem @click="emit('toggleBlocked', u)"><Ban class="size-4" /> {{ u.access === 'blocked' ? 'Desbloquear cuenta' : 'Bloquear cuenta' }}</DropdownMenuItem>
                    </template>
                    <DropdownMenuItem v-if="u.can.reset_password" @click="emit('reset', u)"><KeyRound class="size-4" /> Restablecer contraseña</DropdownMenuItem>
                    <DropdownMenuItem v-if="u.can.invite" @click="emit('resend', u)"><RotateCw class="size-4" /> Reenviar invitación</DropdownMenuItem>
                    <DropdownMenuSeparator v-if="u.can.change_role || u.can.link_employee || u.can.change_branch_scope" />
                    <DropdownMenuItem v-if="u.can.change_role" @click="emit('changeRole', u)"><UserCog class="size-4" /> Cambiar rol</DropdownMenuItem>
                    <DropdownMenuItem v-if="u.can.change_branch_scope" @click="emit('editScope', u)"><MapPinned class="size-4" /> Alcance por sucursal</DropdownMenuItem>
                    <DropdownMenuItem v-if="u.can.link_employee" @click="emit('linkEmployee', u)"><Link2 class="size-4" /> Vincular colaborador</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <span v-else class="mt-1.5 shrink-0 text-[11px] text-muted-foreground" :title="noActionsHint">—</span>
        </header>

        <!-- Rol y estado de acceso -->
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold" :class="u.role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-muted text-foreground'">
                <ShieldCheck v-if="u.role === 'admin'" class="size-3.5" /> {{ u.role_name }}
            </span>
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="ACCESS_META[u.access].tone" :title="ACCESS_META[u.access].hint">
                {{ ACCESS_META[u.access].label }}
            </span>
        </div>
        <p
            class="-mt-2 flex items-center gap-1 text-[11px] text-muted-foreground"
            :title="u.data_scope.mode === 'legacy' ? 'Alcance por defecto de su nivel (usuario previo a la asignación por sucursal). Un administrador puede restringirlo.' : 'Alcance por sucursal'"
        >
            <MapPinned class="size-3 shrink-0" /> <span class="truncate">{{ u.data_scope.label }}</span>
        </p>

        <!-- Detalle -->
        <div class="mt-auto space-y-3 border-t border-border pt-3 text-xs">
            <div>
                <span class="sr-only">Invitación</span>
                <p class="flex items-center gap-1.5">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="INVITATION_META[u.invitation.status].tone">
                        <MailWarning v-if="u.invitation.status === 'failed'" class="size-3.5" /> {{ INVITATION_META[u.invitation.status].label }}
                    </span>
                    <button
                        v-if="u.can.invite" type="button" :disabled="resendingId === u.id"
                        class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:opacity-50"
                        :title="u.invitation.status === 'failed' ? 'Reintentar envío' : 'Reenviar invitación'" :aria-label="`Reenviar invitación a ${u.name}`"
                        @click="emit('resend', u)"
                    >
                        <Loader2 v-if="resendingId === u.id" class="size-3.5 animate-spin" /><RotateCw v-else class="size-3.5" />
                    </button>
                </p>
                <p v-if="u.invitation.status === 'failed' && u.invitation.error" class="mt-1 text-[11px] leading-4 text-rose-600">{{ u.invitation.error }}</p>
                <p v-else-if="u.invitation.sent_at" class="mt-1 text-[11px] text-muted-foreground">Enviada: {{ formatDateTime(u.invitation.sent_at) }}</p>
            </div>
            <div class="flex items-start gap-2">
                <Building2 class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                <div class="min-w-0">
                    <span class="sr-only">Colaborador · sucursal</span>
                    <p class="truncate" :class="u.employee_name ? 'font-semibold text-foreground' : 'italic text-muted-foreground'" :title="u.employee_name ?? undefined">{{ u.employee_name ?? 'Sin vincular' }}</p>
                    <p class="text-muted-foreground">{{ u.current_branch ?? '—' }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2 text-muted-foreground">
                <Clock class="size-3.5 shrink-0" />
                <p>Último acceso: {{ u.last_login_at ? formatDateTime(u.last_login_at) : 'Nunca' }}</p>
            </div>
        </div>
    </article>
</template>

<script setup lang="ts">
// Tabla del módulo Usuarios: estado de acceso, invitación, colaborador,
// último acceso y menú de acciones (solo las que el rol del actor permite;
// el backend vuelve a validar cada una).
import { Ban, KeyRound, Link2, Loader2, MailWarning, MapPinned, MoreHorizontal, RotateCw, ShieldCheck, ShieldOff, UserCheck, UserCog } from 'lucide-vue-next'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { ACCESS_META, INVITATION_META, formatDateTime } from './users'
import type { UserRow } from './users'

defineProps<{ users: UserRow[]; resendingId: number | null }>()
const emit = defineEmits<{
    enable: [UserRow]; revoke: [UserRow]; toggleBlocked: [UserRow]; reset: [UserRow]
    resend: [UserRow]; changeRole: [UserRow]; linkEmployee: [UserRow]; editScope: [UserRow]
}>()

function hasActions(u: UserRow): boolean {
    return u.can.manage_access || u.can.reset_password || u.can.change_role || u.can.link_employee || u.can.invite || u.can.change_branch_scope
}
</script>

<template>
    <div class="app-table-wrap">
        <div class="app-table-content">
            <table class="w-full min-w-[880px] text-sm">
                <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Usuario</th>
                        <th class="px-4 py-3 text-left font-semibold">Rol</th>
                        <th class="px-4 py-3 text-left font-semibold">Acceso</th>
                        <th class="px-4 py-3 text-left font-semibold">Invitación</th>
                        <th class="px-4 py-3 text-left font-semibold">Colaborador · sucursal</th>
                        <th class="px-4 py-3 text-left font-semibold">Último acceso</th>
                        <th class="px-4 py-3 text-right font-semibold"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id" class="border-t border-border transition hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <p class="font-semibold text-foreground">{{ u.name }} <span v-if="u.is_self" class="ml-1 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-bold text-primary">Tú</span></p>
                            <p class="text-xs text-muted-foreground">{{ u.email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold" :class="u.role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-muted text-foreground'">
                                <ShieldCheck v-if="u.role === 'admin'" class="size-3.5" /> {{ u.role_name }}
                            </span>
                            <p class="mt-1 flex max-w-[14rem] items-center gap-1 text-[11px] text-muted-foreground" :title="u.data_scope.mode === 'legacy' ? 'Alcance por defecto de su nivel (usuario previo a la asignación por sucursal). Un administrador puede restringirlo.' : 'Alcance por sucursal'">
                                <MapPinned class="size-3 shrink-0" /> <span class="truncate">{{ u.data_scope.label }}</span>
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="ACCESS_META[u.access].tone" :title="ACCESS_META[u.access].hint">{{ ACCESS_META[u.access].label }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
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
                            </div>
                            <p v-if="u.invitation.status === 'failed' && u.invitation.error" class="mt-1 max-w-[16rem] text-[11px] leading-4 text-rose-600">{{ u.invitation.error }}</p>
                            <p v-else-if="u.invitation.sent_at" class="mt-1 text-[11px] text-muted-foreground">{{ formatDateTime(u.invitation.sent_at) }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <p :class="u.employee_name ? 'font-semibold text-foreground' : 'italic text-muted-foreground'">{{ u.employee_name ?? 'Sin vincular' }}</p>
                            <p class="text-muted-foreground">{{ u.current_branch ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ u.last_login_at ? formatDateTime(u.last_login_at) : 'Nunca' }}</td>
                        <td class="px-4 py-3 text-right">
                            <DropdownMenu v-if="hasActions(u)">
                                <DropdownMenuTrigger as-child>
                                    <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground" :aria-label="`Acciones para ${u.name}`">
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
                            <span v-else class="text-[11px] text-muted-foreground" :title="u.is_self ? 'Tu propia cuenta se administra en Configuración.' : u.is_last_admin ? 'Es el único administrador habilitado.' : 'Tu rol no permite modificar este usuario.'">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <AppEmptyState v-if="users.length === 0" title="Sin resultados" message="Ningún usuario coincide con la búsqueda o los filtros." />
        </div>
    </div>
</template>

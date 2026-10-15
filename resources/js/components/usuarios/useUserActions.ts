// Acciones por fila del módulo Usuarios (prioridad 8/10): confirmación con
// SweetAlert2 y petición a la URL que generó el BACKEND (route()), nunca una
// ruta armada a mano — así funcionan aunque la app viva en una subcarpeta.
import { router } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import { ref } from 'vue'
import { okrRequest } from '@/lib/okrHttp'
import type { Invitation, LinkableEmployee, UserRoleOption, UserRow } from './users'
import { escapeHtml } from './users'

const CONFIRM = '#4f46e5'
const DANGER = '#dc2626'

export function useUserActions() {
    const resendingId = ref<number | null>(null)

    async function confirm(opts: { icon: 'question' | 'warning'; title: string; text: string; confirm: string; danger?: boolean }): Promise<boolean> {
        const r = await Swal.fire({
            icon: opts.icon, title: opts.title, text: opts.text, showCancelButton: true,
            confirmButtonText: opts.confirm, cancelButtonText: 'Cancelar', confirmButtonColor: opts.danger ? DANGER : CONFIRM,
        })

        return r.isConfirmed
    }

    async function enableAccess(u: UserRow) {
        if (await confirm({ icon: 'question', title: `¿Activar el acceso de ${u.name}?`, text: 'Confirma que ya verificaste la identidad de esta persona. Su información de colaborador no cambia.', confirm: 'Activar acceso' })) {
            router.post(u.urls.enable_access, {}, { preserveScroll: true })
        }
    }

    async function revokeAccess(u: UserRow) {
        if (await confirm({ icon: 'warning', title: `¿Revocar la activación de ${u.name}?`, text: 'Volverá a «pendiente de activación»: no podrá entrar a OKR hasta que se active de nuevo. No cambia su situación laboral ni sus datos de colaborador.', confirm: 'Revocar activación', danger: true })) {
            router.post(u.urls.disable_access, {}, { preserveScroll: true })
        }
    }

    async function toggleBlocked(u: UserRow) {
        const blocked = u.access === 'blocked'
        const ok = await confirm(blocked
            ? { icon: 'question', title: `¿Desbloquear la cuenta de ${u.name}?`, text: 'Podrá volver a iniciar sesión con su contraseña.', confirm: 'Desbloquear' }
            : { icon: 'warning', title: `¿Bloquear la cuenta de ${u.name}?`, text: 'No podrá iniciar sesión en ningún módulo y se cerrarán sus sesiones abiertas. Su información de colaborador no cambia. Puedes revertirlo cuando quieras.', confirm: 'Bloquear cuenta', danger: true })

        if (ok) {
            router.post(u.urls.toggle_disabled, {}, { preserveScroll: true })
        }
    }

    async function changeRole(u: UserRow, roles: UserRoleOption[]) {
        const options = Object.fromEntries(roles.filter((r) => r.grantable && r.key !== u.role).map((r) => [r.key, r.name]))

        if (Object.keys(options).length === 0) {
            Swal.fire({ icon: 'info', title: 'Sin roles disponibles', text: 'No hay otro rol que puedas asignar a este usuario.', confirmButtonColor: CONFIRM })

            return
        }

        const r = await Swal.fire({
            title: `Cambiar el rol de ${u.name}`, input: 'select', inputOptions: options, inputPlaceholder: 'Elige el nuevo rol',
            text: `Rol actual: ${u.role_name}. El cambio aplica en su siguiente acción.`,
            showCancelButton: true, confirmButtonText: 'Cambiar rol', cancelButtonText: 'Cancelar', confirmButtonColor: CONFIRM,
            inputValidator: (v) => (v ? null : 'Elige un rol.'),
        })

        if (r.isConfirmed && r.value) {
            router.put(u.urls.update_role, { role: r.value }, { preserveScroll: true })
        }
    }

    async function linkEmployee(u: UserRow, employees: LinkableEmployee[]) {
        const options: Record<string, string> = { '': '— Sin vincular —' }
        employees.filter((e) => e.linked_user_id === null || e.linked_user_id === u.id)
            .forEach((e) => {
                options[String(e.id)] = `${e.full_name}${e.is_active ? '' : ' (inactivo)'}`
            })

        const r = await Swal.fire({
            title: `Colaborador vinculado — ${u.name}`, input: 'select', inputOptions: options, inputValue: u.employee_id ? String(u.employee_id) : '',
            text: 'Define la sucursal y los OKR propios del usuario. No modifica los datos del colaborador.',
            showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar', confirmButtonColor: CONFIRM,
        })

        if (r.isConfirmed) {
            router.put(u.urls.link_employee, { employee_id: r.value ? Number(r.value) : null }, { preserveScroll: true })
        }
    }

    /** Reenvío por fetch (spinner en el botón). Devuelve el estado nuevo de la invitación. */
    async function resendInvitation(u: UserRow): Promise<Invitation | null> {
        resendingId.value = u.id
        const res = await okrRequest<{ sent: boolean; message: string; invitation: Invitation }>(u.urls.resend_invitation, { method: 'POST' })
        resendingId.value = null

        if (res.ok) {
            Swal.fire({ icon: 'success', title: 'Invitación enviada al servidor de correo', text: res.data.message, confirmButtonColor: CONFIRM })

            return res.data.invitation
        }

        Swal.fire({
            icon: 'error', title: 'No se envió la invitación',
            text: res.data?.message ?? res.message,
            footer: res.status === 422 ? 'Puedes reintentar en unos minutos.' : undefined,
            confirmButtonColor: CONFIRM,
        })

        return res.data?.invitation ?? null
    }

    function showGeneratedPassword(title: string, data: { name: string; email: string; password: string }, note?: string) {
        Swal.fire({
            icon: 'success', title,
            html: `<p style="margin-bottom:.5rem">Contraseña de <b>${escapeHtml(data.name)}</b> (${escapeHtml(data.email)}).</p>
<p style="margin-bottom:.75rem;color:#b45309">Cópiala ahora: no se volverá a mostrar.</p>
<code style="display:block;font-size:1.2em;letter-spacing:.05em;padding:.6rem;border-radius:.5rem;background:#f1f5f9;user-select:all">${escapeHtml(data.password)}</code>${note ? `<p style="margin-top:.75rem;font-size:.9em;color:#475569">${escapeHtml(note)}</p>` : ''}`,
            showCancelButton: true, confirmButtonText: 'Copiar contraseña', cancelButtonText: 'Cerrar', confirmButtonColor: CONFIRM, allowOutsideClick: false,
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
    }

    return { resendingId, enableAccess, revokeAccess, toggleBlocked, changeRole, linkEmployee, resendInvitation, showGeneratedPassword }
}

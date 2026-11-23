// Acciones por fila del módulo Usuarios (prioridad 8/10): confirmación con
// SweetAlert2 y petición a la URL que generó el BACKEND (route()), nunca una
// ruta armada a mano — así funcionan aunque la app viva en una subcarpeta.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dialogs, showDialog } from '@/components/feedback/ConfirmDialog';
import { okrRequest } from '@/lib/okrHttp';
import type { OkrResult } from '@/lib/okrHttp';
import type { Invitation, UserRow } from './users';
import { escapeHtml } from './users';

/**
 * Composable con las acciones por fila de la pantalla Usuarios: activar o
 * revocar acceso, bloquear/desbloquear, reenviar invitación y mostrar una
 * contraseña generada. `resendingId` indica qué fila está reenviando.
 */
export function useUserActions() {
    /** Id del usuario cuya invitación se está reenviando (spinner en su botón). */
    const resendingId = ref<number | null>(null);

    /** Pide confirmación con SweetAlert2 (variante peligro si `danger`) y devuelve si se aceptó. */
    async function confirm(opts: {
        icon: 'question' | 'warning';
        title: string;
        text: string;
        confirm: string;
        danger?: boolean;
    }): Promise<boolean> {
        const r = await showDialog(opts.danger ? 'danger' : 'confirm', {
            icon: opts.icon,
            title: opts.title,
            text: opts.text,
            confirmText: opts.confirm,
        });

        return r.isConfirmed;
    }

    /** Confirma y activa el acceso del usuario (POST a urls.enable_access). */
    async function enableAccess(u: UserRow) {
        if (
            await confirm({
                icon: 'question',
                title: `¿Activar el acceso de ${u.name}?`,
                text: 'Confirma que ya verificaste la identidad de esta persona. Su información de colaborador no cambia.',
                confirm: 'Activar acceso',
            })
        ) {
            router.post(u.urls.enable_access, {}, { preserveScroll: true });
        }
    }

    /** Confirma y revoca la activación: el usuario vuelve a «pendiente» (POST a urls.disable_access). */
    async function revokeAccess(u: UserRow) {
        if (
            await confirm({
                icon: 'warning',
                title: `¿Revocar la activación de ${u.name}?`,
                text: 'Volverá a «pendiente de activación»: no podrá entrar a OKR hasta que se active de nuevo. No cambia su situación laboral ni sus datos de colaborador.',
                confirm: 'Revocar activación',
                danger: true,
            })
        ) {
            router.post(u.urls.disable_access, {}, { preserveScroll: true });
        }
    }

    /** Confirma y bloquea o desbloquea la cuenta según su estado actual (POST a urls.toggle_disabled). */
    async function toggleBlocked(u: UserRow) {
        const blocked = u.access === 'blocked';
        const ok = await confirm(
            blocked
                ? {
                      icon: 'question',
                      title: `¿Desbloquear la cuenta de ${u.name}?`,
                      text: 'Podrá volver a iniciar sesión con su contraseña.',
                      confirm: 'Desbloquear',
                  }
                : {
                      icon: 'warning',
                      title: `¿Bloquear la cuenta de ${u.name}?`,
                      text: 'No podrá iniciar sesión en ningún módulo y se cerrarán sus sesiones abiertas. Su información de colaborador no cambia. Puedes revertirlo cuando quieras.',
                      confirm: 'Bloquear cuenta',
                      danger: true,
                  },
        );

        if (ok) {
            router.post(u.urls.toggle_disabled, {}, { preserveScroll: true });
        }
    }

    // Cambiar rol y vincular colaborador viven en RoleChangeDialog.vue y
    // LinkEmployeeDialog.vue (tarjetas seleccionables y buscador, no un select).

    /** Reenvío por fetch (spinner en el botón). Devuelve el estado nuevo de la invitación. */
    async function resendInvitation(u: UserRow): Promise<Invitation | null> {
        resendingId.value = u.id;
        let res: OkrResult<{
            sent: boolean;
            message: string;
            invitation: Invitation;
        }>;

        // okrRequest no lanza (convierte fallos de red en { ok: false }), pero el
        // finally garantiza que el indicador "Enviando…" nunca se quede encendido.
        try {
            res = await okrRequest<{
                sent: boolean;
                message: string;
                invitation: Invitation;
            }>(u.urls.resend_invitation, { method: 'POST' });
        } finally {
            resendingId.value = null;
        }

        if (res.ok) {
            dialogs.success({
                title: 'Invitación enviada al servidor de correo',
                text: res.data.message,
            });

            return res.data.invitation;
        }

        dialogs.error({
            title: 'No se envió la invitación',
            text: res.data?.message ?? res.message,
            footer:
                res.status === 422
                    ? 'Puedes reintentar en unos minutos.'
                    : undefined,
        });

        return res.data?.invitation ?? null;
    }

    /**
     * Muestra una sola vez la contraseña generada con botón para copiarla al
     * portapapeles; si la copia falla, avisa dentro del mismo diálogo.
     * @param title título del diálogo
     * @param data nombre, correo y contraseña del usuario
     * @param note nota opcional al pie
     */
    function showGeneratedPassword(
        title: string,
        data: { name: string; email: string; password: string },
        note?: string,
    ) {
        showDialog('success', {
            title,
            html: `<p style="margin-bottom:.5rem">Contraseña de <b>${escapeHtml(data.name)}</b> (${escapeHtml(data.email)}).</p>
<p style="margin-bottom:.75rem;color:var(--warning)">Cópiala ahora: no se volverá a mostrar.</p>
<code style="display:block;font-size:1.2em;letter-spacing:.05em;padding:.6rem;border-radius:.5rem;background:var(--muted);color:var(--foreground);user-select:all">${escapeHtml(data.password)}</code>${note ? `<p style="margin-top:.75rem;font-size:.9em;color:var(--muted-foreground)">${escapeHtml(note)}</p>` : ''}`,
            showCancelButton: true,
            confirmText: 'Copiar contraseña',
            cancelText: 'Cerrar',
            allowOutsideClick: false,
            preConfirm: async () => {
                try {
                    await navigator.clipboard.writeText(data.password);
                } catch {
                    dialogs.showValidationMessage(
                        'No se pudo copiar automáticamente; selecciónala y cópiala a mano.',
                    );

                    return false;
                }

                return true;
            },
        }).then((r) => {
            if (r.isConfirmed) {
                dialogs.success({
                    title: 'Copiada al portapapeles',
                    timer: 1600,
                    showConfirmButton: false,
                });
            }
        });
    }

    return {
        resendingId,
        enableAccess,
        revokeAccess,
        toggleBlocked,
        resendInvitation,
        showGeneratedPassword,
    };
}

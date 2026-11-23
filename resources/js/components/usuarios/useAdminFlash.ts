// Muestra con SweetAlert2 el resultado de las acciones de Usuarios / Roles
// (flash success/warning/error compartido por HandleInertiaRequests). Sin esto,
// un bloqueo del backend ("no puedes quitar al único administrador") no se veía.
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import {
    errorDialog,
    toastNotice,
    warningDialog,
} from '@/components/feedback/ConfirmDialog';

/** Mensajes flash que comparte el backend en cada respuesta. */
type Flash = {
    success?: string | null;
    warning?: string | null;
    error?: string | null;
};

/** `suppressSuccess` evita duplicar el toast cuando la pantalla ya muestra su propio diálogo. */
export function useAdminFlash(
    suppressSuccess: () => boolean = () => false,
): void {
    const page = usePage();

    watch(
        () => (page.props as { flash?: Flash }).flash,
        (flash) => {
            if (flash?.error) {
                errorDialog({
                    title: 'No se pudo completar',
                    text: flash.error,
                });
            } else if (flash?.warning) {
                warningDialog({ title: 'Atención', text: flash.warning });
            } else if (flash?.success && !suppressSuccess()) {
                toastNotice('success', flash.success, { timer: 3500 });
            }
        },
        { deep: true, immediate: true },
    );
}

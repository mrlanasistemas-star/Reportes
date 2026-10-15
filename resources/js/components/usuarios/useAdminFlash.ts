// Muestra con SweetAlert2 el resultado de las acciones de Usuarios / Roles
// (flash success/warning/error compartido por HandleInertiaRequests). Sin esto,
// un bloqueo del backend ("no puedes quitar al único administrador") no se veía.
import { usePage } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import { watch } from 'vue'

type Flash = { success?: string | null; warning?: string | null; error?: string | null }

/** `suppressSuccess` evita duplicar el toast cuando la pantalla ya muestra su propio diálogo. */
export function useAdminFlash(suppressSuccess: () => boolean = () => false): void {
    const page = usePage()

    watch(() => (page.props as { flash?: Flash }).flash, (flash) => {
        if (flash?.error) {
            Swal.fire({ icon: 'error', title: 'No se pudo completar', text: flash.error, confirmButtonColor: '#4f46e5' })
        } else if (flash?.warning) {
            Swal.fire({ icon: 'warning', title: 'Atención', text: flash.warning, confirmButtonColor: '#4f46e5' })
        } else if (flash?.success && !suppressSuccess()) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: flash.success, timer: 3500, showConfirmButton: false })
        }
    }, { deep: true, immediate: true })
}

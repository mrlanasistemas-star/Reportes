/**
 * Toast global de la app: instancia de SweetAlert2 en modo toast (esquina
 * superior derecha, 2.8 s, sin botón) con los colores del tema.
 *
 * Se usa a través del composable useToast y en algunas páginas directamente.
 */
import Swal from 'sweetalert2';

export const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2800,
    timerProgressBar: true,
    buttonsStyling: false,
    // Inline (gana al CSS que inyecta SweetAlert): tarjeta del tema, también en oscuro.
    background: 'var(--card)',
    color: 'var(--card-foreground)',
    customClass: {
        popup: 'rounded-2xl border border-border bg-card text-card-foreground shadow-xl',
        title: 'text-sm font-semibold',
    },
});

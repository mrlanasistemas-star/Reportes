/**
 * Puerta única para SweetAlert2 en la app: todas las confirmaciones, avisos,
 * éxitos y errores pasan por aquí para verse igual (tarjeta de la marca,
 * botones `app-btn`, modo oscuro) y comportarse igual (Cancelar a la
 * izquierda, Escape cierra, textos en español).
 *
 * Variantes:
 *  - confirm  → pregunta con botón principal verde (acción normal).
 *  - danger   → pregunta para acciones destructivas (eliminar, desactivar…), botón rojo.
 *  - info / success / error / warning → aviso de un solo botón “Entendido”.
 *
 * Nunca pases `confirmButtonColor`: el color sale de las clases de la marca.
 */
import type {
    SweetAlertIcon,
    SweetAlertOptions,
    SweetAlertResult,
} from 'sweetalert2';
import Swal from 'sweetalert2';

/** Variantes de diálogo disponibles (ver descripción del módulo). */
export type DialogVariant =
    | 'confirm'
    | 'danger'
    | 'info'
    | 'success'
    | 'error'
    | 'warning';

/** Opciones de SweetAlert2 + atajos en español para los textos de los botones. */
export type DialogOptions = SweetAlertOptions & {
    /** Texto del botón principal (atajo de `confirmButtonText`). */
    confirmText?: string;
    /** Texto del botón secundario (atajo de `cancelButtonText`). */
    cancelText?: string;
};

/** Clases de los botones del diálogo según su papel (principal, destructivo, secundario). */
const BUTTON = {
    primary: 'app-btn app-btn-primary min-w-[140px] h-11',
    danger: 'app-btn app-btn-danger min-w-[140px] h-11',
    secondary: 'app-btn app-btn-secondary min-w-[140px] h-11',
} as const;

/**
 * Devuelve las clases base del diálogo para una variante.
 *
 * Clases base. `app-swal-popup/title/text/actions` (app.css) ya resuelven
 * fondo, borde y modo oscuro con la especificidad necesaria frente al CSS que
 * inyecta SweetAlert; los botones usan las clases `app-btn` del sistema visual.
 */
function customClassFor(
    variant: DialogVariant,
): NonNullable<SweetAlertOptions['customClass']> {
    return {
        popup: 'app-swal-popup',
        title: 'app-swal-title',
        htmlContainer: 'app-swal-text',
        actions: 'app-swal-actions',
        // El icono rojo de `app-swal-icon` solo para acciones destructivas.
        ...(variant === 'danger' ? { icon: 'app-swal-icon' } : {}),
        confirmButton: variant === 'danger' ? BUTTON.danger : BUTTON.primary,
        cancelButton: BUTTON.secondary,
        denyButton: BUTTON.secondary,
        input: 'app-input',
        validationMessage: 'text-sm',
    };
}

/** Icono por defecto de cada variante. */
const DEFAULT_ICON: Record<DialogVariant, SweetAlertIcon> = {
    confirm: 'question',
    danger: 'warning',
    info: 'info',
    success: 'success',
    error: 'error',
    warning: 'warning',
};

/** Indica si la variante es una pregunta (muestra botón Cancelar) o un aviso de un solo botón. */
const IS_QUESTION: Record<DialogVariant, boolean> = {
    confirm: true,
    danger: true,
    info: false,
    success: false,
    error: false,
    warning: false,
};

/**
 * Base común (sin variante) — para casos especiales que ya arman sus propias
 * opciones (`input`, `preConfirm`, carga, etc.). Mismo estilo que las variantes.
 */
export const appSwal = Swal.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    background: 'var(--card)',
    color: 'var(--card-foreground)',
    customClass: customClassFor('confirm'),
});

/**
 * Abre un diálogo de la variante indicada con el estilo de la marca.
 *
 * @param variant Variante del diálogo (confirm, danger, info, success, error, warning).
 * @param options Opciones de SweetAlert2 más los atajos `confirmText` y `cancelText`.
 * @returns Promesa con el resultado de SweetAlert2 (isConfirmed, value, etc.).
 */
export function showDialog<T = any>(
    variant: DialogVariant,
    options: DialogOptions = {},
): Promise<SweetAlertResult<Awaited<T>>> {
    const { confirmText, cancelText, customClass, ...rest } = options;
    const question = IS_QUESTION[variant];
    const base = customClassFor(variant);
    const extra =
        typeof customClass === 'object' && customClass ? customClass : {};

    return Swal.fire({
        icon: DEFAULT_ICON[variant],
        showCancelButton: question,
        confirmButtonText:
            confirmText ?? (question ? 'Sí, continuar' : 'Entendido'),
        cancelButtonText: cancelText ?? 'Cancelar',
        reverseButtons: true,
        // En preguntas el foco inicial va a Cancelar si es destructiva (evita
        // confirmar con un Enter accidental).
        focusCancel: variant === 'danger',
        ...rest,
        buttonsStyling: false,
        background: 'var(--card)',
        color: 'var(--card-foreground)',
        customClass: { ...base, ...extra },
    } as SweetAlertOptions) as Promise<SweetAlertResult<Awaited<T>>>;
}

/**
 * Confirmación normal. Firma histórica conservada (`useConfirm()` la usa):
 * `{ title, text, icon, confirmText, cancelText }`.
 */
export function confirmDialog(options: DialogOptions = {}) {
    return showDialog('confirm', {
        title: '¿Estás seguro?',
        text: 'Esta acción puede modificar información.',
        icon: 'warning',
        ...options,
    });
}

/** Confirmación de acción destructiva (eliminar, desactivar, anular…). */
export function dangerDialog(options: DialogOptions = {}) {
    return showDialog('danger', {
        title: '¿Seguro que quieres continuar?',
        confirmText: 'Sí, eliminar',
        ...options,
    });
}

/** Aviso informativo de un solo botón. */
export const infoDialog = (options: DialogOptions = {}) =>
    showDialog('info', options);
/** Aviso de éxito de un solo botón. */
export const successDialog = (options: DialogOptions = {}) =>
    showDialog('success', options);
/** Aviso de error de un solo botón (título por defecto "No se pudo completar la acción"). */
export const errorDialog = (options: DialogOptions = {}) =>
    showDialog('error', {
        title: 'No se pudo completar la acción',
        ...options,
    });
/** Aviso de advertencia de un solo botón (título por defecto "Atención"). */
export const warningDialog = (options: DialogOptions = {}) =>
    showDialog('warning', { title: 'Atención', ...options });

/**
 * Aviso breve en la esquina superior derecha (no bloquea; se cierra a los 3 s).
 *
 * @param icon Icono de SweetAlert2 (success, error, info…).
 * @param title Texto del aviso.
 * @param options Opciones extra de SweetAlert2.
 */
export function toastNotice(
    icon: SweetAlertIcon,
    title: string,
    options: SweetAlertOptions = {},
) {
    return Swal.fire({
        toast: true,
        position: 'top-end',
        icon,
        title,
        timer: 3000,
        timerProgressBar: true,
        showConfirmButton: false,
        background: 'var(--card)',
        color: 'var(--card-foreground)',
        ...options,
    } as SweetAlertOptions);
}

/** Atajo agrupado: `dialogs.danger({...})`, `dialogs.toast('success', '…')`. */
export const dialogs = {
    show: showDialog,
    confirm: confirmDialog,
    danger: dangerDialog,
    info: infoDialog,
    success: successDialog,
    error: errorDialog,
    warning: warningDialog,
    toast: toastNotice,
    close: () => Swal.close(),
    showLoading: () => Swal.showLoading(),
    showValidationMessage: (message: string) =>
        Swal.showValidationMessage(message),
};

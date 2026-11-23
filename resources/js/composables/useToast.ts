import { toast } from '@/components/feedback/Toast';

/**
 * Atajos para mostrar toasts (SweetAlert2 en modo toast, components/feedback/Toast)
 * de éxito, error, información o advertencia con un título.
 */
export function useToast() {
    return {
        success(title: string) {
            return toast.fire({ icon: 'success', title });
        },
        error(title: string) {
            return toast.fire({ icon: 'error', title });
        },
        info(title: string) {
            return toast.fire({ icon: 'info', title });
        },
        warning(title: string) {
            return toast.fire({ icon: 'warning', title });
        },
    };
}

import { confirmDialog } from '@/components/feedback/ConfirmDialog';

/**
 * Expone el diálogo de confirmación global (components/feedback/ConfirmDialog)
 * como composable: `const { confirm } = useConfirm()`.
 */
export function useConfirm() {
    return {
        confirm: confirmDialog,
    };
}

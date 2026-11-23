/** Valor que devuelve useInitials(). */
export type UseInitialsReturn = {
    getInitials: (fullName?: string) => string;
};

/**
 * Iniciales de un nombre (primera letra del primer y último nombre) para avatares.
 * @returns '' si no hay nombre; una letra si es una sola palabra.
 */
export function getInitials(fullName?: string): string {
    if (!fullName) {
        return '';
    }

    const names = fullName.trim().split(' ');

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return names[0].charAt(0).toUpperCase();
    }

    return `${names[0].charAt(0)}${names[names.length - 1].charAt(0)}`.toUpperCase();
}

/** Composable que expone getInitials. */
export function useInitials(): UseInitialsReturn {
    return { getInitials };
}

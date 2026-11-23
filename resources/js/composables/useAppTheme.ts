/**
 * Utilidades mínimas para consultar/forzar el modo oscuro directamente sobre la
 * clase `dark` de <html>. No persiste la preferencia (para eso: useAppearance).
 */
export function useAppTheme() {
    /** true si <html> tiene la clase `dark`. */
    const isDark = () => document.documentElement.classList.contains('dark');

    /** Activa (true) o desactiva (false) el modo oscuro. */
    const setDark = (value: boolean) => {
        document.documentElement.classList.toggle('dark', value);
    };

    /** Alterna el modo oscuro. */
    const toggleDark = () => {
        document.documentElement.classList.toggle('dark');
    };

    return {
        isDark,
        setDark,
        toggleDark,
    };
}

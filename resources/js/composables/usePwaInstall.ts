/**
 * Instalación de la app como PWA: captura el evento beforeinstallprompt y
 * expone si ya está instalada y una función para mostrar el diálogo nativo.
 * Se importa por efecto secundario en app.ts para capturar el evento temprano.
 */
import { ref } from 'vue';

/** Evento no estándar beforeinstallprompt (Chromium). */
type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

// Estado a nivel de módulo: el evento "beforeinstallprompt" puede disparar
// antes de que cualquier componente lo monte, así que se captura una sola
// vez aquí y se comparte con quien llame al composable.
const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const isInstalled = ref(false);

/** Marca isInstalled si la app corre en modo standalone (instalada, incluido iOS). */
function checkInstalled() {
    isInstalled.value =
        window.matchMedia?.('(display-mode: standalone)').matches ||
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true;
}

if (typeof window !== 'undefined') {
    checkInstalled();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt.value = event as BeforeInstallPromptEvent;
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt.value = null;
        isInstalled.value = true;
    });
}

/** Composable que comparte el estado de instalación de la PWA. */
export function usePwaInstall() {
    /** Muestra el diálogo nativo de instalación (si el navegador lo ofreció) y espera la decisión. */
    async function promptInstall() {
        if (!deferredPrompt.value) {
            return;
        }

        await deferredPrompt.value.prompt();
        await deferredPrompt.value.userChoice;
        deferredPrompt.value = null;
    }

    return { deferredPrompt, isInstalled, promptInstall };
}

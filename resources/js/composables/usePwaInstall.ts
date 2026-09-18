import { ref } from 'vue';

type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

// Estado a nivel de módulo: el evento "beforeinstallprompt" puede disparar
// antes de que cualquier componente lo monte, así que se captura una sola
// vez aquí y se comparte con quien llame al composable.
const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const isInstalled = ref(false);

function checkInstalled() {
    isInstalled.value =
        window.matchMedia?.('(display-mode: standalone)').matches ||
        (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
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

export function usePwaInstall() {
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

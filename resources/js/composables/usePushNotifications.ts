import { computed, ref } from 'vue';
import { csrfToken } from '@/components/efectividad/format';
import { usePwaInstall } from '@/composables/usePwaInstall';
import {
    subscribe as subscribeRoute,
    test as testRoute,
    unsubscribe as unsubscribeRoute,
} from '@/routes/notification-settings';

/**
 * Web Push en ESTE dispositivo (23-nov-2026): detecta soporte, pide permiso
 * (solo cuando el usuario hace clic), suscribe/desuscribe el navegador con la
 * clave pública VAPID del servidor y avisa al backend. El service worker que
 * muestra el aviso es public/sw.js.
 */
export type PushState =
    | 'unsupported' // navegador sin Push API
    | 'ios-needs-install' // iPhone/iPad en Safari: requiere "Agregar a inicio"
    | 'denied' // el usuario bloqueó las notificaciones del sitio
    | 'off'
    | 'on';

/** Respuesta JSON de los endpoints de suscripción/prueba. */
type JsonResult = { ok: boolean; message?: string; devices_count?: number };

/** Convierte la clave VAPID (base64url) al Uint8Array que exige pushManager.subscribe. */
function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const output = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}

/** Detecta iPhone/iPad (incluye iPadOS que se presenta como MacIntel táctil). */
function isIos(): boolean {
    const ua = navigator.userAgent;

    return (
        /iPad|iPhone|iPod/.test(ua) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

/** true si el navegador soporta service worker, PushManager y Notification. */
function hasPushApi(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * Llama a un endpoint JSON del backend con CSRF.
 * @throws Error con el mensaje del servidor si la respuesta no es 2xx.
 */
async function send(
    url: string,
    method: 'POST' | 'DELETE',
    body?: unknown,
): Promise<JsonResult> {
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    let data: JsonResult = { ok: res.ok };

    try {
        data = (await res.json()) as JsonResult;
    } catch {
        // Respuesta sin JSON: se usa solo el estado HTTP.
    }

    if (!res.ok) {
        throw new Error(
            data.message || 'El servidor no pudo procesar la solicitud.',
        );
    }

    return data;
}

/** Devuelve el registro del service worker raíz; lo registra (public/sw.js) si no existe. */
async function registration(): Promise<ServiceWorkerRegistration> {
    const existing = await navigator.serviceWorker.getRegistration('/');

    if (existing) {
        return existing;
    }

    await navigator.serviceWorker.register('/sw.js');

    return navigator.serviceWorker.ready;
}

/**
 * Composable para activar/desactivar Web Push en este dispositivo.
 * Rutas: notification-settings.subscribe / unsubscribe / test.
 * @param publicKey getter de la clave pública VAPID del servidor (null si no está configurada).
 */
export function usePushNotifications(
    publicKey: () => string | null | undefined,
) {
    const { isInstalled } = usePwaInstall();
    const state = ref<PushState>('off');
    const busy = ref(false);
    const devicesCount = ref<number | null>(null);

    /** true si este dispositivo puede usar push (navegador compatible y, en iOS, app instalada). */
    const supported = computed(
        () =>
            state.value !== 'unsupported' &&
            state.value !== 'ios-needs-install',
    );

    /** Recalcula `state` según soporte, permiso del navegador y suscripción existente. */
    async function refresh(): Promise<void> {
        if (!hasPushApi()) {
            state.value =
                isIos() && !isInstalled.value
                    ? 'ios-needs-install'
                    : 'unsupported';

            return;
        }

        if (Notification.permission === 'denied') {
            state.value = 'denied';

            return;
        }

        try {
            const reg = await navigator.serviceWorker.getRegistration('/');
            const sub = reg ? await reg.pushManager.getSubscription() : null;
            state.value =
                sub && Notification.permission === 'granted' ? 'on' : 'off';
        } catch {
            state.value = 'off';
        }
    }

    /**
     * Pide permiso, suscribe el navegador con la clave VAPID y registra la
     * suscripción en el backend. Debe llamarse desde un clic: los navegadores
     * exigen gesto del usuario para pedir permiso.
     * @returns mensaje de éxito para mostrar al usuario.
     */
    async function enable(): Promise<string> {
        const key = publicKey();

        if (!key) {
            throw new Error(
                'El servidor aún no tiene configuradas las claves de notificaciones push.',
            );
        }

        busy.value = true;

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                state.value = permission === 'denied' ? 'denied' : 'off';

                throw new Error(
                    permission === 'denied'
                        ? 'Bloqueaste las notificaciones para este sitio. Actívalas en la configuración del navegador y vuelve a intentarlo.'
                        : 'No se otorgó el permiso de notificaciones.',
                );
            }

            const reg = await registration();
            const sub =
                (await reg.pushManager.getSubscription()) ??
                (await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(key),
                }));

            const json = sub.toJSON();
            const encodings = (
                PushManager as unknown as {
                    supportedContentEncodings?: string[];
                }
            ).supportedContentEncodings;

            const result = await send(subscribeRoute().url, 'POST', {
                endpoint: json.endpoint,
                keys: json.keys,
                content_encoding: encodings?.includes('aes128gcm')
                    ? 'aes128gcm'
                    : (encodings?.[0] ?? 'aes128gcm'),
            });

            devicesCount.value = result.devices_count ?? devicesCount.value;
            state.value = 'on';

            return (
                result.message ??
                'Notificaciones activadas en este dispositivo.'
            );
        } finally {
            busy.value = false;
        }
    }

    /** Da de baja la suscripción en el backend y en el navegador; devuelve el mensaje a mostrar. */
    async function disable(): Promise<string> {
        busy.value = true;

        try {
            const reg = await navigator.serviceWorker.getRegistration('/');
            const sub = reg ? await reg.pushManager.getSubscription() : null;
            let message = 'Notificaciones desactivadas en este dispositivo.';

            if (sub) {
                const result = await send(unsubscribeRoute().url, 'DELETE', {
                    endpoint: sub.endpoint,
                });
                devicesCount.value = result.devices_count ?? devicesCount.value;
                message = result.message ?? message;
                await sub.unsubscribe();
            }

            state.value = 'off';

            return message;
        } finally {
            busy.value = false;
        }
    }

    /** Pide al backend enviar una notificación de prueba a los dispositivos del usuario. */
    async function sendTest(): Promise<string> {
        busy.value = true;

        try {
            const result = await send(testRoute().url, 'POST');

            return result.message ?? 'Notificación de prueba enviada.';
        } finally {
            busy.value = false;
        }
    }

    return {
        state,
        busy,
        supported,
        devicesCount,
        refresh,
        enable,
        disable,
        sendTest,
    };
}

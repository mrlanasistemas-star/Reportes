<script setup lang="ts">
/**
 * PushNotificationToggle — control de notificaciones push del dispositivo actual.
 *
 * Se usa en pages/settings/Notifications. Muestra el estado (activas,
 * bloqueadas, iOS sin instalar, no compatible o desactivadas) y botones para
 * activar, desactivar o enviar una prueba.
 *
 * Props:
 * - configured: el servidor tiene configuradas las claves push.
 * - publicKey: clave pública VAPID para suscribirse (null si no hay).
 * - devicesCount: dispositivos con push activo en la cuenta (valor inicial).
 */
import { BellOff, BellRing, Send, Smartphone } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { usePushNotifications } from '@/composables/usePushNotifications';

/**
 * Activar / desactivar las notificaciones push en ESTE navegador o teléfono
 * (23-nov-2026). El permiso solo se pide tras el clic del usuario.
 */
const props = defineProps<{
    configured: boolean;
    publicKey: string | null;
    devicesCount: number;
}>();

/** Estado y acciones de push del navegador (usePushNotifications). */
const push = usePushNotifications(() => props.publicKey);

/** Al montar se consulta el estado real de la suscripción en este navegador. */
onMounted(() => {
    void push.refresh();
});

/** Dispositivos con push activo: el valor más reciente del composable o el de la prop. */
const devices = computed(() => push.devicesCount.value ?? props.devicesCount);

/** Texto y colores del aviso de estado según la configuración y el estado del permiso. */
const status = computed(() => {
    if (!props.configured) {
        return {
            tone: 'border-border bg-muted/40 text-muted-foreground',
            text: 'El administrador aún no habilita las notificaciones push en el servidor. Mientras tanto seguirás recibiendo los avisos en la campana y por correo.',
        };
    }

    switch (push.state.value) {
        case 'on':
            return {
                tone: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                text: 'Activas en este dispositivo. Recibirás un aviso aunque tengas la app cerrada.',
            };
        case 'denied':
            return {
                tone: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                text: 'Las notificaciones están bloqueadas para este sitio. Permítelas desde la configuración del navegador (icono del candado junto a la dirección) y vuelve a intentarlo.',
            };
        case 'ios-needs-install':
            return {
                tone: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
                text: 'En iPhone o iPad primero instala la app: en Safari toca Compartir y luego "Agregar a pantalla de inicio". Abre la app desde ese icono y activa aquí las notificaciones (requiere iOS 16.4 o posterior).',
            };
        case 'unsupported':
            return {
                tone: 'border-border bg-muted/40 text-muted-foreground',
                text: 'Este navegador no admite notificaciones push. Prueba con Chrome, Edge, Firefox o la app instalada.',
            };
        default:
            return {
                tone: 'border-border bg-muted/40 text-muted-foreground',
                text: 'Desactivadas en este dispositivo.',
            };
    }
});

/**
 * Ejecuta una acción de push mostrando un SweetAlert de carga y luego el
 * resultado (éxito con el mensaje devuelto o error).
 *
 * @param action Acción a ejecutar; devuelve el mensaje de éxito.
 * @param loadingTitle Título del aviso mientras se ejecuta.
 */
async function run(action: () => Promise<string>, loadingTitle: string) {
    Swal.fire({
        title: loadingTitle,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
    });

    try {
        const message = await action();
        await Swal.fire({
            icon: 'success',
            title: 'Listo',
            text: message,
            timer: 2600,
            showConfirmButton: false,
        });
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: 'No se pudo completar',
            text:
                error instanceof Error
                    ? error.message
                    : 'Ocurrió un error inesperado. Intenta de nuevo.',
        });
    }
}

/** Activa las notificaciones push en este dispositivo. */
const enable = () => run(push.enable, 'Activando notificaciones…');
/** Desactiva las notificaciones push en este dispositivo. */
const disable = () => run(push.disable, 'Desactivando notificaciones…');
/** Envía una notificación de prueba. */
const sendTest = () => run(push.sendTest, 'Enviando prueba…');
</script>

<template>
    <div class="space-y-4">
        <div
            :class="[
                'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                status.tone,
            ]"
        >
            <Smartphone class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>{{ status.text }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-if="configured && push.state.value === 'off'"
                :disabled="push.busy.value"
                @click="enable"
            >
                <BellRing class="size-4" aria-hidden="true" />
                Activar en este dispositivo
            </Button>
            <template v-if="configured && push.state.value === 'on'">
                <Button
                    variant="outline"
                    :disabled="push.busy.value"
                    @click="sendTest"
                >
                    <Send class="size-4" aria-hidden="true" />
                    Enviar prueba
                </Button>
                <Button
                    variant="ghost"
                    :disabled="push.busy.value"
                    @click="disable"
                >
                    <BellOff class="size-4" aria-hidden="true" />
                    Desactivar en este dispositivo
                </Button>
            </template>
        </div>

        <p v-if="configured" class="text-xs text-muted-foreground">
            Dispositivos con notificaciones activas en tu cuenta: {{ devices }}.
            Las notificaciones se activan por dispositivo: repite este paso en
            tu teléfono y en tu computadora.
        </p>
    </div>
</template>

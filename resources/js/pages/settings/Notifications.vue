<script setup lang="ts">
/**
 * Configuración > Notificaciones (NotificationSettingsController@edit).
 * Dos tarjetas: activar notificaciones push en este dispositivo y elegir por tipo
 * de aviso si se recibe correo y/o push.
 */
import { Head } from '@inertiajs/vue3';
import { BellRing, SlidersHorizontal } from 'lucide-vue-next';
import NotificationPreferencesTable from '@/components/settings/NotificationPreferencesTable.vue';
import type { NotificationPreferenceRow } from '@/components/settings/NotificationPreferencesTable.vue';
import PushNotificationToggle from '@/components/settings/PushNotificationToggle.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { edit } from '@/routes/notification-settings';

/** Migas de pan que recibe el layout. */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notificaciones', href: edit() }],
    },
});

/**
 * Props desde NotificationSettingsController:
 * - preferences: filas de preferencia por tipo de aviso (correo/push).
 * - push: si el servidor tiene llaves VAPID configuradas, la llave pública y
 *   cuántos dispositivos del usuario ya están suscritos.
 */
defineProps<{
    preferences: NotificationPreferenceRow[];
    push: {
        configured: boolean;
        public_key: string | null;
        devices_count: number;
    };
}>();
</script>

<template>
    <Head title="Notificaciones" />

    <h2 class="sr-only">Configuración de notificaciones</h2>

    <!-- Tarjeta: push en este dispositivo -->
    <SettingsCard
        :icon="BellRing"
        title="Notificaciones en este dispositivo"
        description="Recibe un aviso en tu teléfono o computadora cuando termine la lectura de archivos, cuando el reporte esté listo o cuando se cree un nuevo periodo."
    >
        <PushNotificationToggle
            :configured="push.configured"
            :public-key="push.public_key"
            :devices-count="push.devices_count"
        />
    </SettingsCard>

    <!-- Tarjeta: preferencias por tipo de aviso -->
    <SettingsCard
        :icon="SlidersHorizontal"
        title="Qué avisos recibir"
        description="Elige por tipo de aviso si quieres correo y notificación push. La campana del sistema siempre conserva el historial."
    >
        <NotificationPreferencesTable :preferences="preferences" />
    </SettingsCard>
</template>

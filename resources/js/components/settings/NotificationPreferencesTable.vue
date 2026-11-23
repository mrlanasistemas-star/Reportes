<script setup lang="ts">
/**
 * NotificationPreferencesTable — tabla de preferencias de notificación por tipo de aviso.
 *
 * Se usa en pages/settings/Notifications. Cada fila tiene dos interruptores
 * (Correo y Push) y el botón "Guardar preferencias" envía todo con PATCH a
 * notification-settings.update.
 *
 * Props:
 * - preferences: tipos de aviso con su etiqueta, descripción y estado actual de correo/push.
 */
import { useForm } from '@inertiajs/vue3';
import { Mail, Smartphone } from 'lucide-vue-next';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { update } from '@/routes/notification-settings';

/**
 * Preferencias por tipo de aviso (23-nov-2026): correo y push se pueden
 * desactivar por separado. La campana del sistema siempre registra el aviso.
 */
export type NotificationPreferenceRow = {
    type: string;
    label: string;
    description: string;
    mail: boolean;
    push: boolean;
};

const props = defineProps<{ preferences: NotificationPreferenceRow[] }>();

/** Formulario de Inertia con el estado editable (type, mail, push) de cada tipo de aviso. */
const form = useForm({
    preferences: props.preferences.map((p) => ({
        type: p.type,
        mail: p.mail,
        push: p.push,
    })),
});

/**
 * Devuelve la fila editable del formulario para un tipo de aviso.
 *
 * @param type Clave del tipo de aviso.
 */
function rowFor(type: string) {
    return form.preferences.find((p) => p.type === type)!;
}

/** Guarda las preferencias y muestra un aviso de éxito o de error. */
function save() {
    form.patch(update().url, {
        preserveScroll: true,
        onSuccess: () => {
            // Lo guardado pasa a ser la nueva base: sin esto isDirty seguiría en true
            // y el botón "Guardar" quedaría activo aunque no haya cambios pendientes.
            form.defaults();
            Swal.fire({
                icon: 'success',
                title: 'Preferencias guardadas',
                timer: 2000,
                showConfirmButton: false,
            });
        },
        onError: () => {
            Swal.fire({
                icon: 'error',
                title: 'No se pudieron guardar',
                text: 'Revisa tu conexión e intenta de nuevo.',
            });
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <ul class="divide-y rounded-xl border">
            <li
                v-for="pref in preferences"
                :key="pref.type"
                class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold">{{ pref.label }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ pref.description }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-5">
                    <label class="flex items-center gap-2 text-sm">
                        <Mail
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Correo
                        <Switch
                            :model-value="rowFor(pref.type).mail"
                            :aria-label="`Correo: ${pref.label}`"
                            @update:model-value="
                                (v: boolean) => (rowFor(pref.type).mail = v)
                            "
                        />
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Smartphone
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Push
                        <Switch
                            :model-value="rowFor(pref.type).push"
                            :aria-label="`Push: ${pref.label}`"
                            @update:model-value="
                                (v: boolean) => (rowFor(pref.type).push = v)
                            "
                        />
                    </label>
                </div>
            </li>
        </ul>

        <div class="flex justify-end">
            <Button :disabled="form.processing || !form.isDirty" @click="save"
                >Guardar preferencias</Button
            >
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * AlertError — alerta roja (variante destructive) con una lista de errores.
 *
 * Se usa en TwoFactorSetupModal y TwoFactorRecoveryCodes para mostrar los
 * errores devueltos por el servidor.
 *
 * Props:
 * - errors: mensajes de error a listar (se eliminan los duplicados).
 * - title: encabezado de la alerta (por defecto "No se pudo completar la acción.").
 */
import { AlertCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

type Props = {
    errors: string[];
    title?: string;
};

const props = withDefaults(defineProps<Props>(), {
    title: 'No se pudo completar la acción.',
});

/** Errores sin duplicados, en el orden en que llegaron. */
const uniqueErrors = computed(() => Array.from(new Set(props.errors)));
</script>

<template>
    <Alert variant="destructive">
        <AlertCircle class="size-4" />
        <AlertTitle>{{ title }}</AlertTitle>
        <AlertDescription>
            <ul class="list-inside list-disc text-sm">
                <li v-for="(error, index) in uniqueErrors" :key="index">
                    {{ error }}
                </li>
            </ul>
        </AlertDescription>
    </Alert>
</template>

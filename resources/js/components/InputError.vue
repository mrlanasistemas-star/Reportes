<script setup lang="ts">
/**
 * InputError — mensaje de error de validación bajo un campo de formulario.
 *
 * Se usa en las páginas de autenticación, DeleteUser y TwoFactorSetupModal.
 * Solo se muestra cuando hay mensaje; usa role="alert" para que lo anuncien
 * los lectores de pantalla.
 *
 * Props:
 * - message: texto del error (vacío o indefinido = oculto).
 */
import { CircleAlert } from 'lucide-vue-next';

defineProps<{
    message?: string;
}>();
</script>

<template>
    <!-- Fase 8.1: error de campo con icono y anunciado a lectores de pantalla. -->
    <p
        v-show="message"
        role="alert"
        class="flex items-start gap-1.5 text-sm font-medium text-danger"
    >
        <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span>{{ message }}</span>
    </p>
</template>

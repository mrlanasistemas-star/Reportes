<script setup lang="ts">
/**
 * Heading — encabezado de sección con título y descripción opcional.
 *
 * Se usa en secciones de configuración (p. ej. DeleteUser).
 *
 * Props:
 * - title: texto del título.
 * - description: texto secundario opcional bajo el título.
 * - variant: 'default' (título grande con margen inferior) o 'small' (compacto).
 */
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>

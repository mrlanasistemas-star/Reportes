<script setup lang="ts">
/**
 * TextLink — enlace de texto subrayado basado en <Link> de Inertia.
 *
 * Se usa en las páginas de autenticación (Login, ForgotPassword, VerifyEmail).
 *
 * Props:
 * - href: destino del enlace.
 * - tabindex: orden de tabulación opcional.
 * - method: método HTTP de Inertia (p. ej. 'post' para cerrar sesión).
 * - as: etiqueta a renderizar (p. ej. 'button').
 */
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

type Props = {
    href: LinkComponentBaseProps['href'];
    tabindex?: number;
    method?: Method;
    as?: string;
};

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
    >
        <slot />
    </Link>
</template>

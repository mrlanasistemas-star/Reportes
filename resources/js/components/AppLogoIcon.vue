<script setup lang="ts">
/**
 * AppLogoIcon — imagen del logo oficial (/logoMrLana.png).
 *
 * Se usa en AppLogo, AppHeader, AppSidebarHeader y en los layouts de
 * autenticación. No hereda atributos automáticamente: los reenvía a la
 * <img> con v-bind="$attrs".
 *
 * Props:
 * - className: clases CSS para la imagen.
 */
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/logoMrLana.png"
        alt="Logo Mr Lana"
        width="256"
        height="256"
        :class="className"
        v-bind="$attrs"
    />
</template>

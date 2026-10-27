<script setup lang="ts">
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
    <img src="/logoMrLana.png" alt="Logo Mr Lana" width="256" height="256"
    :class="className" v-bind="$attrs"/>
</template>

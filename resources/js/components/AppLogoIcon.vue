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
    <img src="/logoMrLana.png" alt="MR LANA"
    :class="className" v-bind="$attrs"/>
</template>

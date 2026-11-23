<script lang="ts" setup>
/** Primitiva UI: Contenedor <nav> de migas de pan (shadcn-vue) para indicar la ubicación del usuario. */
import type { HTMLAttributes } from "vue"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <nav
    aria-label="breadcrumb"
    data-slot="breadcrumb"
    :class="props.class"
  >
    <slot />
  </nav>
</template>

<script setup lang="ts">
/** Primitiva UI: Bloque animado de marcador de carga (skeleton) mientras llega el contenido. */
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

interface SkeletonProps {
  class?: HTMLAttributes["class"]
}

const props = defineProps<SkeletonProps>()
</script>

<template>
  <div
    data-slot="skeleton"
    :class="cn('app-skeleton rounded-md bg-primary/10', props.class)"
  />
</template>

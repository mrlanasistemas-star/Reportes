<script setup lang="ts">
import type { ProgressRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ProgressIndicator, ProgressRoot } from "reka-ui"
import { cn } from "@/lib/utils"

// `modelValue = null` → indicador INDETERMINADO (barra en movimiento sin
// porcentaje). Úsalo cuando el avance no sea medible; nunca inventes un %.
const props = withDefaults(
  defineProps<ProgressRootProps & { class?: HTMLAttributes["class"] }>(),
  {
    modelValue: 0,
  },
)

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <ProgressRoot
    data-slot="progress"
    v-bind="delegatedProps"
    :class="
      cn(
        'bg-primary/15 relative h-2 w-full overflow-hidden rounded-full',
        props.class,
      )
    "
  >
    <ProgressIndicator
      v-if="props.modelValue !== null && props.modelValue !== undefined"
      data-slot="progress-indicator"
      class="bg-primary h-full w-full flex-1 transition-all duration-500"
      :style="`transform: translateX(-${100 - (props.modelValue ?? 0)}%);`"
    />
    <div
      v-else
      data-slot="progress-indeterminate"
      class="bg-primary progress-indeterminate absolute inset-y-0 w-1/3 rounded-full"
    />
  </ProgressRoot>
</template>

<style scoped>
.progress-indeterminate {
  animation: progress-slide 1.4s ease-in-out infinite;
}

@keyframes progress-slide {
  0% {
    left: -35%;
  }
  100% {
    left: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .progress-indeterminate {
    animation: none;
    left: 0;
    width: 100%;
    opacity: 0.45;
  }
}
</style>

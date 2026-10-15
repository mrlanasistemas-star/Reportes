<script setup lang="ts">
// Fase 8.1: encabezado de página común — kicker opcional (módulo/sección),
// título, subtítulo y acciones alineadas a la derecha (abajo en móvil).
withDefaults(defineProps<{
  title: string
  subtitle?: string | null
  kicker?: string | null
}>(), {
  subtitle: null,
  kicker: null,
})
</script>

<template>
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div class="flex min-w-0 items-start gap-3">
      <div
        v-if="$slots.icon"
        class="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-xl border bg-card text-primary shadow-soft-xs"
      >
        <slot name="icon" />
      </div>
      <div class="min-w-0 space-y-1">
        <p
          v-if="kicker"
          class="app-kicker"
        >
          {{ kicker }}
        </p>
        <h1 class="app-title">
          {{ title }}
        </h1>
        <p
          v-if="subtitle"
          class="app-subtitle max-w-3xl"
        >
          {{ subtitle }}
        </p>
      </div>
    </div>

    <div
      v-if="$slots.actions"
      class="flex shrink-0 flex-wrap items-center gap-2"
    >
      <slot name="actions" />
    </div>
  </div>
</template>

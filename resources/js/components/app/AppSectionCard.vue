<script setup lang="ts">
withDefaults(defineProps<{
  title?: string | null
  subtitle?: string | null
  padded?: boolean
}>(), {
  title: null,
  subtitle: null,
  padded: true,
})
</script>

<template>
  <section class="app-section">
    <div
      v-if="title || subtitle || $slots.actions"
      class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="min-w-0">
        <h2
          v-if="title"
          class="app-section-title"
        >
          {{ title }}
        </h2>
        <p
          v-if="subtitle"
          class="mt-1 text-sm text-muted-foreground"
        >
          {{ subtitle }}
        </p>
      </div>
      <div
        v-if="$slots.actions"
        class="flex flex-wrap items-center gap-2"
      >
        <slot name="actions" />
      </div>
    </div>

    <div :class="padded ? 'p-5' : ''">
      <slot />
    </div>
  </section>
</template>

<script setup lang="ts">
// Fix 08-sep-2026 (módulo OKR): este componente estaba duplicado de TextField
// — renderizaba un <input> de una sola línea, nunca un <textarea> real (no
// tenía consumidores todavía, así que no rompía ninguna pantalla existente).
const model = defineModel<string | number | null>()

withDefaults(defineProps<{
  label?: string
  placeholder?: string
  rows?: number
  error?: string | null
  description?: string | null
  disabled?: boolean
}>(), {
  placeholder: '',
  rows: 3,
  disabled: false,
})
</script>

<template>
  <div class="w-full space-y-1.5">
    <label
      v-if="label"
      class="text-sm font-semibold text-foreground"
    >
      {{ label }}
    </label>

    <textarea
      v-model="model"
      :rows="rows"
      :placeholder="placeholder"
      :disabled="disabled"
      class="app-textarea resize-y"
    />

    <p
      v-if="description && !error"
      class="text-xs text-muted-foreground"
    >
      {{ description }}
    </p>

    <p
      v-if="error"
      class="text-xs font-semibold text-destructive"
    >
      {{ error }}
    </p>
  </div>
</template>

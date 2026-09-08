<script setup lang="ts">
// Select "shadcn" (Radix) genérico con la misma forma que TextField/TextareaField
// (label + description + error) — para catálogos cortos (estado, tipo, dirección...).
// Para listas largas/buscables (sucursal, colaborador, responsable) usa SearchableSelect.
import { computed } from 'vue'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

type OptionLike = { value: string | number; label: string }

// Radix/reka-ui prohíbe un SelectItem con value="" (esa cadena está reservada
// para "limpiar la selección") — pero este módulo usa '' como "Todas/Todos/
// Cualquiera" en los filtros. Se traduce a un sentinel interno y de vuelta,
// nunca se expone al componente Select real.
const SENTINEL_EMPTY = '__ff_empty__'

const model = defineModel<string | number | null>()

const props = withDefaults(defineProps<{
  label?: string
  options: OptionLike[]
  placeholder?: string
  error?: string | null
  description?: string | null
  disabled?: boolean
}>(), {
  placeholder: 'Selecciona...',
  disabled: false,
})

const internalValue = computed(() => {
  const v = model.value
  return v === '' || v === null || v === undefined ? SENTINEL_EMPTY : String(v)
})

const internalOptions = computed(() => props.options.map((o) => ({
  ...o,
  value: o.value === '' ? SENTINEL_EMPTY : String(o.value),
})))

function onUpdate(value: unknown) {
  model.value = value === SENTINEL_EMPTY ? '' : (value as string)
}
</script>

<template>
  <div class="w-full space-y-1.5">
    <label
      v-if="label"
      class="text-sm font-semibold text-foreground"
    >
      {{ label }}
    </label>

    <Select :model-value="internalValue" :disabled="disabled" @update:model-value="onUpdate">
      <SelectTrigger class="h-11 w-full rounded-2xl px-4 text-sm">
        <SelectValue :placeholder="placeholder" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="opt in internalOptions" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </SelectItem>
      </SelectContent>
    </Select>

    <p v-if="description && !error" class="text-xs text-muted-foreground">
      {{ description }}
    </p>
    <p v-if="error" class="text-xs font-semibold text-destructive">
      {{ error }}
    </p>
  </div>
</template>

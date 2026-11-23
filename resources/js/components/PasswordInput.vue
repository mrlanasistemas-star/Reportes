<script setup lang="ts">
/**
 * PasswordInput — campo de contraseña con botón para mostrar u ocultar el texto.
 *
 * Se usa en DeleteUser, ConfirmPassword, ResetPassword y settings/Security.
 * Reenvía todos los atributos (name, v-model, etc.) al Input interno.
 *
 * Props:
 * - class: clases extra para el input.
 *
 * Expone: `$el` (referencia al input) y `focus()` para enfocarlo desde el padre.
 */
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

/** true = la contraseña se muestra como texto plano. */
const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="relative">
        <Input
            ref="inputRef"
            :type="showPassword ? 'text' : 'password'"
            :class="cn('pr-10', props.class)"
            v-bind="$attrs"
        />
        <button
            type="button"
            @click="showPassword = !showPassword"
            :class="
                cn(
                    'absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                )
            "
            :aria-label="
                showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'
            "
            :aria-pressed="showPassword ? 'true' : 'false'"
            :title="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
        >
            <EyeOff v-if="showPassword" class="size-4" aria-hidden="true" />
            <Eye v-else class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>

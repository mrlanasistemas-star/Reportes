<script setup lang="ts">
/**
 * OkrNumberInput — campo numérico del módulo OKR. Se usa en OkrGoalsTable y
 * OkrKrEditor (y en tests/okrNumberInput.test.ts). Los atributos extra se
 * reenvían al Input.
 *
 * Props / v-model:
 * - modelValue: valor como texto ('' = vacío).
 * - decimals: decimales a los que se redondea al salir del campo (por defecto 2).
 *
 * Emits:
 * - update:modelValue(value): nuevo valor como texto.
 */
// Campo numérico del módulo OKR (19-oct-2026) sobre el Input de shadcn con UN
// solo enlace: modelValue ↔ update:modelValue.
//
// Causa del bug de "escribir dos veces": se usaba <Input :value @input>, pero el
// Input ya trae su propio v-model interno. Al teclear, ese v-model re-renderizaba
// el componente en el microtask que corre ENTRE los dos listeners nativos de
// "input" y reponía el :value anterior; el @input externo leía el campo ya vacío
// y se perdían dígitos ("10000" quedaba en "000").
//
// · El modelo es texto ('' = vacío), igual que KrRow. Lo que se escribe no se
//   reformatea mientras se edita (30000.50, "12500." a medias, -5000).
// · Solo se emiten números finitos; NaN, Infinity o texto incompleto → ''.
// · Al salir del campo se redondea a `decimals` y se limpia texto inválido.
import { nextTick, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { roundTo } from './wizard/okrWizard';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{ modelValue: string; decimals?: number }>(),
    { decimals: 2 },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/** Valor interno: número finito o '' cuando está vacío o incompleto. */
type Draft = number | '';

/**
 * Convierte el modelo (o lo que entrega el input) a Draft.
 * @param v valor de entrada.
 * @returns número finito o ''.
 */
function toDraft(v: string | number | null | undefined): Draft {
    if (v === '' || v === null || v === undefined) {
        return '';
    }

    const n = Number(v);

    return Number.isFinite(n) ? n : '';
}

// Valor tal como lo entrega el <input type="number"> del Input (número o '').
// Se le devuelve en el mismo tipo para que su v-model nunca reescriba el texto.
const draft = ref<Draft>(toDraft(props.modelValue));

// Refleja el modelo SOLO si no equivale a lo escrito: un cambio externo (meta
// recalculada, base sugerida, reapertura) entra; "30000.5" no pisa "30000.50".
/** Copia el modelo al campo solo si representa un número distinto. */
function syncFromModel() {
    const next = toDraft(props.modelValue);

    if (next !== draft.value) {
        draft.value = next;
    }
}

watch(() => props.modelValue, syncFromModel);

/**
 * Guarda el valor en el campo y lo emite si cambió respecto al modelo.
 * @param value nuevo valor.
 */
function commit(value: Draft) {
    draft.value = value;
    const text = value === '' ? '' : String(value);

    if (text !== props.modelValue) {
        emit('update:modelValue', text);
        // Si el controlador ajustó o rechazó el valor (p. ej. tope de peso) y el
        // modelo no cambió, el campo vuelve a mostrar el valor vigente.
        nextTick(syncFromModel);
    }
}

/**
 * Recibe cada cambio del Input.
 * @param value valor tal como lo entrega el Input.
 */
function onUpdate(value: string | number) {
    commit(toDraft(value));
}

/**
 * Quita el foco al girar la rueda del mouse sobre el campo enfocado (evita
 * cambiar el número sin querer).
 * @param e evento wheel.
 */
function onWheel(e: WheelEvent) {
    const el = e.target as HTMLInputElement;

    if (document.activeElement === el) {
        el.blur();
    }
}

/**
 * Al salir del campo redondea a `decimals` o limpia texto inválido.
 * @param e evento blur.
 */
function onBlur(e: FocusEvent) {
    const el = e.target as HTMLInputElement;

    if (draft.value === '') {
        // Texto que el navegador no considera número ("-", "1e"): se limpia.
        if (el.validity?.badInput) {
            el.value = '';
        }

        return;
    }

    const rounded = roundTo(draft.value, props.decimals);

    if (rounded !== draft.value) {
        commit(rounded);
    }
}
</script>

<template>
    <!-- inputmode: teclado numérico en móvil. La rueda del mouse sobre un campo
         enfocado cambiaba la meta sin querer: se suelta el foco al girarla. -->
    <Input
        v-bind="$attrs"
        type="number"
        inputmode="decimal"
        :model-value="draft"
        @update:model-value="onUpdate"
        @blur="onBlur"
        @wheel.passive="onWheel"
    />
</template>

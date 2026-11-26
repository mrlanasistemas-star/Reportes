<script setup lang="ts">
/**
 * HighlightText — resalta, sin distinguir acentos ni mayúsculas, las palabras
 * buscadas dentro de un texto (nombre, sucursal, ruta).
 */
import { computed } from 'vue';

const props = defineProps<{
    text: string | null | undefined;
    tokens: string[];
}>();

const segments = computed(() => {
    const text = props.text ?? '';

    if (!text || props.tokens.length === 0) {
        return [{ value: text, hit: false }];
    }

    // Normaliza carácter por carácter para conservar el índice original.
    const chars = [...text];
    const norm = chars.map(
        (c) =>
            c.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().charAt(0) || c,
    );
    const joined = norm.join('');
    const marks = new Array(chars.length).fill(false);

    for (const token of props.tokens) {
        let from = 0;
        let idx: number;

        while (token && (idx = joined.indexOf(token, from)) !== -1) {
            for (let i = idx; i < idx + token.length; i++) {
                marks[i] = true;
            }

            from = idx + token.length;
        }
    }

    const out: { value: string; hit: boolean }[] = [];

    chars.forEach((c, i) => {
        const last = out[out.length - 1];

        if (last && last.hit === marks[i]) {
            last.value += c;
        } else {
            out.push({ value: c, hit: marks[i] });
        }
    });

    return out;
});
</script>

<template>
    <span
        ><template v-for="(s, i) in segments" :key="i"
            ><mark
                v-if="s.hit"
                class="rounded-[3px] bg-primary/15 px-px text-inherit ring-1 ring-primary/25"
                >{{ s.value }}</mark
            ><template v-else>{{ s.value }}</template></template
        ></span
    >
</template>

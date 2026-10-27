<script setup lang="ts">
// Fondo de las pantallas de acceso (27-oct-2026): dos manchas difusas en el
// verde de la marca que derivan muy lento + una retícula tenue que se
// desvanece hacia los bordes. Solo se anima transform (.app-auth-blob), es
// decorativo (aria-hidden) y se queda quieto con prefers-reduced-motion.
withDefaults(defineProps<{ tone?: 'light' | 'dark' }>(), { tone: 'light' })
</script>

<template>
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <span
            :class="[
                'app-auth-blob -top-24 -right-24 size-[28rem]',
                tone === 'dark' ? 'bg-emerald-500/20' : 'bg-emerald-400/15 dark:bg-emerald-500/10',
            ]"
        />
        <span
            :class="[
                'app-auth-blob -bottom-32 -left-24 size-[24rem]',
                tone === 'dark' ? 'bg-teal-400/15' : 'bg-teal-300/15 dark:bg-teal-500/[0.08]',
            ]"
            style="animation-delay: -11s; animation-duration: 28s"
        />
        <div
            v-if="tone === 'light'"
            class="absolute inset-0 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_70%)] bg-[size:44px_44px] opacity-40"
        />
    </div>
</template>

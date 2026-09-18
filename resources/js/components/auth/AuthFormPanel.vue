<script setup lang="ts">
import { ShieldCheck } from 'lucide-vue-next'

withDefaults(
    defineProps<{
        mobileTitle: string
        mobileSubtitle?: string
        desktopTitle: string
        desktopSubtitle: string
        status?: string
    }>(),
    { mobileSubtitle: undefined, status: undefined },
)

const year = new Date().getFullYear()

const particles = Array.from({ length: 12 }, (_, i) => ({
    id: i,
    left: Math.random() * 100,
    top: Math.random() * 100,
    size: 2 + Math.random() * 3,
    duration: 9 + Math.random() * 11,
    delay: -Math.random() * 16,
    drift: 10 + Math.random() * 22,
    tone: i % 3 === 0 ? 'bg-emerald-400/40 dark:bg-emerald-300/30' : 'bg-indigo-400/40 dark:bg-indigo-300/30',
}))
</script>

<template>
    <div class="relative flex flex-col items-center justify-center overflow-hidden bg-background p-6 sm:p-10">
        <!-- Personalidad del lado derecho: blobs, malla de puntos, anillo y partículas -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -top-28 -right-16 size-80 rounded-full bg-indigo-400/10 blur-3xl sm:size-[26rem] dark:bg-indigo-500/10"></div>
            <div class="absolute -bottom-24 -left-16 size-72 rounded-full bg-emerald-400/10 blur-3xl sm:size-96 dark:bg-emerald-500/10"></div>
            <div
                class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(99,102,241,0.08)_1px,transparent_0)] bg-[size:26px_26px] dark:bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.05)_1px,transparent_0)]"
            ></div>
            <div
                class="fv-orbit absolute top-[-12rem] right-[-12rem] hidden size-[26rem] rounded-full border border-dashed border-indigo-400/15 sm:block dark:border-indigo-300/10"
            ></div>
            <div
                class="fv-orbit absolute top-[-12rem] right-[-12rem] hidden size-[19rem] translate-x-6 translate-y-6 rounded-full border border-dashed border-emerald-400/15 [animation-direction:reverse] sm:block dark:border-emerald-300/10"
            ></div>
            <span
                v-for="p in particles"
                :key="p.id"
                class="fv-particle absolute rounded-full"
                :class="p.tone"
                :style="{
                    left: `${p.left}%`,
                    top: `${p.top}%`,
                    width: `${p.size}px`,
                    height: `${p.size}px`,
                    '--fv-drift': `${p.drift}px`,
                    animationDuration: `${p.duration}s`,
                    animationDelay: `${p.delay}s`,
                }"
            ></span>
        </div>

        <div
            class="relative w-full max-w-sm animate-in fade-in slide-in-from-bottom-2 duration-500 sm:rounded-3xl sm:border sm:border-border/60 sm:bg-card/50 sm:p-8 sm:shadow-xl sm:shadow-slate-900/5 sm:backdrop-blur-md md:max-w-md dark:sm:shadow-black/20"
        >
            <div class="mb-8 flex flex-col items-center gap-3 text-center lg:hidden">
                <img src="/logoMrLana.png" alt="LANA" draggable="false" class="size-12 rounded-2xl shadow-lg shadow-indigo-900/30" />
                <h1 class="text-xl font-black tracking-tight text-foreground">{{ mobileTitle }}</h1>
                <p v-if="mobileSubtitle" class="text-sm text-muted-foreground">{{ mobileSubtitle }}</p>
            </div>

            <div class="mb-6 hidden lg:block">
                <h2 class="text-2xl font-black tracking-tight text-foreground">{{ desktopTitle }}</h2>
                <p class="mt-1.5 text-sm text-muted-foreground">{{ desktopSubtitle }}</p>
            </div>

            <div
                v-if="status"
                class="mb-5 flex animate-in items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 fade-in slide-in-from-top-1 duration-300 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
            >
                <ShieldCheck class="size-4 shrink-0" />
                {{ status }}
            </div>

            <slot />

            <slot name="footer" />

            <p class="mt-8 text-center text-xs text-muted-foreground lg:hidden">© {{ year }} LANA. Todos los derechos reservados.</p>
        </div>
    </div>
</template>

<script setup lang="ts">
import loginSide from '@/img/login-side.jpg'

const year = new Date().getFullYear()

const particles = Array.from({ length: 18 }, (_, i) => ({
    id: i,
    left: Math.random() * 100,
    top: Math.random() * 100,
    size: 2 + Math.random() * 3,
    duration: 8 + Math.random() * 10,
    delay: -Math.random() * 14,
    drift: 12 + Math.random() * 26,
    tone: i % 3 === 0 ? 'bg-emerald-300/50' : 'bg-indigo-200/50',
}))
</script>

<template>
    <div class="relative hidden overflow-hidden bg-slate-950 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <!-- Foto de fondo, panel del lado izquierdo -->
        <img
            :src="loginSide"
            alt=""
            draggable="false"
            loading="eager"
            decoding="async"
            fetchpriority="high"
            class="fv-kenburns pointer-events-none absolute inset-0 size-full object-cover"
        />

        <!-- Velo de contraste + tinte de marca -->
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-slate-950/85 via-slate-950/55 to-slate-950/90"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-tr from-indigo-950/50 via-transparent to-emerald-950/30"></div>
        <div class="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-indigo-500/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-20 size-96 rounded-full bg-emerald-500/15 blur-3xl"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] bg-[size:24px_24px]"></div>

        <!-- Puntitos flotando -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
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

        <!-- Marca -->
        <div class="relative animate-in fade-in slide-in-from-left-4 duration-700">
            <div class="flex items-center gap-3">
                <img
                    src="/logoMrLana.png"
                    alt="LANA"
                    draggable="false"
                    class="size-11 shrink-0 rounded-2xl shadow-lg shadow-indigo-900/40 ring-1 ring-white/10"
                />
                <p class="text-xs font-black tracking-[0.3em] text-indigo-300 uppercase">LANA</p>
            </div>
        </div>

        <!-- Pie -->
        <div class="relative animate-in fade-in slide-in-from-left-4 duration-700" style="animation-delay: 150ms">
            <p class="text-xs text-slate-500">© {{ year }} LANA. Todos los derechos reservados.</p>
        </div>
    </div>
</template>

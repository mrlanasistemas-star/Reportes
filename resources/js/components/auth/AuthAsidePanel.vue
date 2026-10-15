<script setup lang="ts">
import { CalendarRange, ChartNoAxesCombined, ShieldCheck } from 'lucide-vue-next'
import AuthBrand from '@/components/auth/AuthBrand.vue'
import loginSide from '@/img/login-side.jpg'

// Fase 8.3 (15-oct-2026): panel de marca sobrio — foto institucional con un
// velo uniforme, logo legible y tres frases que dicen qué hace el sistema.
// Sin partículas ni zoom animado (distraían y costaban en equipos lentos).
const year = new Date().getFullYear()

const highlights = [
    { icon: ChartNoAxesCombined, title: 'Radiografía financiera', text: 'EBITDA, colocación, recuperación, cartera y mora por sucursal.' },
    { icon: CalendarRange, title: 'Cierre mensual guiado', text: 'Carga de archivos, validación e incidencias en un solo flujo.' },
    { icon: ShieldCheck, title: 'Acceso por rol', text: 'Cada persona ve solo los módulos y sucursales que le corresponden.' },
]
</script>

<template>
    <aside class="relative hidden overflow-hidden bg-slate-950 lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-14">
        <img
            :src="loginSide"
            alt=""
            draggable="false"
            loading="eager"
            decoding="async"
            fetchpriority="high"
            class="pointer-events-none absolute inset-0 size-full object-cover opacity-60"
        />
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-slate-950/95 via-slate-950/80 to-emerald-950/80"></div>

        <div class="relative">
            <AuthBrand inverted />
        </div>

        <div class="relative max-w-md">
            <p class="text-xs font-semibold tracking-[0.18em] text-emerald-300 uppercase">Panel de reportes</p>
            <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight text-white xl:text-4xl">
                La operación de cada sucursal, clara y en un solo lugar.
            </h2>

            <ul class="mt-8 space-y-5">
                <li v-for="item in highlights" :key="item.title" class="flex gap-3.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-emerald-300 ring-1 ring-white/15">
                        <component :is="item.icon" class="size-4" aria-hidden="true" />
                    </span>
                    <span>
                        <span class="block text-sm font-semibold text-white">{{ item.title }}</span>
                        <span class="block text-sm text-slate-300">{{ item.text }}</span>
                    </span>
                </li>
            </ul>
        </div>

        <p class="relative text-xs text-slate-400">© {{ year }} MR LANA. Todos los derechos reservados.</p>
    </aside>
</template>

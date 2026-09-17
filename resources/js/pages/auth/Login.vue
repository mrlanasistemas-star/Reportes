<script setup lang="ts">
import { ref } from 'vue'
import { Form, Head } from '@inertiajs/vue3'
import { BarChart3, Eye, EyeOff, LineChart, ShieldCheck, Sparkles, Target } from 'lucide-vue-next'
import InputError from '@/components/InputError.vue'
import TextLink from '@/components/TextLink.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { store } from '@/routes/login'
import { request } from '@/routes/password'

defineProps<{
    status?: string
    canResetPassword: boolean
}>()

// Cierre 17-sep-2026, ronda 4 — login rediseñado: mitad y mitad, moderno, sin las
// imágenes de fondo pesadas de antes (4.5MB + 5.9MB, cargaban en CADA visita a
// /login — impacto real en tiempo de carga). El panel izquierdo ahora es CSS puro
// (gradiente + blobs), consistente con el resto del sitio (mismo lenguaje visual
// que el Dashboard). Layout=null para esta página está en app.ts (antes quedaba
// envuelta sin querer en AuthLayout/AuthSimpleLayout, nunca se le pasaba
// title/description — un wrapper vacío de más que el fv-screen con position:fixed
// de antes solo tapaba visualmente).

const year = new Date().getFullYear()
const showPassword = ref(false)

const highlights = [
    { icon: BarChart3, text: 'Radiografía financiera en tiempo real, por sucursal y periodo.' },
    { icon: Target, text: 'Seguimiento de OKR y metas del equipo, siempre a la vista.' },
    { icon: LineChart, text: 'Reportes ejecutivos con un clic — Excel y PDF listos.' },
]
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="grid min-h-svh lg:grid-cols-2">
        <!-- Panel izquierdo — branding, oculto en móvil -->
        <div class="relative hidden overflow-hidden bg-slate-950 lg:flex lg:flex-col lg:justify-between lg:p-12">
            <div class="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-indigo-500/30 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-20 size-96 rounded-full bg-emerald-500/20 blur-3xl"></div>
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.08)_1px,transparent_0)] bg-[size:24px_24px]"></div>

            <div class="relative animate-in fade-in slide-in-from-left-4 duration-700">
                <div class="flex items-center gap-3">
                    <div class="flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-lg shadow-indigo-900/40">
                        <Sparkles class="size-5 text-white" />
                    </div>
                    <p class="text-xs font-black tracking-[0.3em] text-indigo-300 uppercase">MR LANA</p>
                </div>
                <h1 class="mt-8 max-w-md text-4xl leading-tight font-black tracking-tight text-white">
                    Radiografía financiera de tu operación, en un solo lugar.
                </h1>
                <p class="mt-4 max-w-sm text-sm leading-6 text-slate-400">
                    Todo lo que necesitas para dar seguimiento a sucursales, colaboradores y metas del negocio.
                </p>
            </div>

            <div class="relative animate-in fade-in slide-in-from-left-4 space-y-4 duration-700" style="animation-delay: 150ms">
                <div
                    v-for="(item, i) in highlights"
                    :key="i"
                    class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-sm transition duration-200 hover:border-white/20 hover:bg-white/10"
                >
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/10">
                        <component :is="item.icon" class="size-4 text-emerald-300" />
                    </div>
                    <p class="text-sm text-slate-200">{{ item.text }}</p>
                </div>
            </div>

            <p class="relative text-xs text-slate-500">© {{ year }} Reportes — MR LANA</p>
        </div>

        <!-- Panel derecho — formulario -->
        <div class="flex flex-col items-center justify-center bg-background p-6 sm:p-10">
            <div class="w-full max-w-sm animate-in fade-in slide-in-from-bottom-2 duration-500">
                <div class="mb-8 flex flex-col items-center gap-3 text-center lg:hidden">
                    <div class="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-lg shadow-indigo-900/30">
                        <Sparkles class="size-6 text-white" />
                    </div>
                    <h1 class="text-xl font-black tracking-tight text-foreground">Radiografía Financiera</h1>
                </div>

                <div class="mb-6 hidden lg:block">
                    <h2 class="text-2xl font-black tracking-tight text-foreground">Bienvenido de vuelta</h2>
                    <p class="mt-1.5 text-sm text-muted-foreground">Ingresa tus credenciales para continuar.</p>
                </div>

                <div
                    v-if="status"
                    class="mb-5 flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
                >
                    <ShieldCheck class="size-4 shrink-0" />
                    {{ status }}
                </div>

                <Form v-bind="store.form()" :reset-on-success="['password']" v-slot="{ errors, processing }" class="space-y-5">
                    <div class="space-y-2">
                        <Label for="email">Correo electrónico</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="correo@ejemplo.com"
                            class="h-11 rounded-xl transition focus-visible:ring-2 focus-visible:ring-indigo-500/40"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <Label for="password">Contraseña</Label>
                            <TextLink v-if="canResetPassword" :href="request()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                ¿Olvidaste tu contraseña?
                            </TextLink>
                        </div>
                        <div class="relative">
                            <Input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                                class="h-11 rounded-xl pr-11 transition focus-visible:ring-2 focus-visible:ring-indigo-500/40"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted-foreground transition hover:text-foreground"
                                :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" class="size-4" />
                                <Eye v-else class="size-4" />
                            </button>
                        </div>
                        <InputError :message="errors.password" />
                    </div>

                    <Button
                        type="submit"
                        :disabled="processing"
                        data-test="login-button"
                        class="h-11 w-full rounded-xl bg-indigo-600 font-bold shadow-lg shadow-indigo-600/20 transition duration-200 hover:-translate-y-0.5 hover:bg-indigo-500 hover:shadow-xl hover:shadow-indigo-600/30 active:translate-y-0"
                    >
                        <Spinner v-if="processing" class="size-4" />
                        <span>{{ processing ? 'Validando…' : 'Iniciar sesión' }}</span>
                    </Button>
                </Form>

                <p class="mt-8 text-center text-xs text-muted-foreground lg:hidden">© {{ year }} Reportes — MR LANA</p>
            </div>
        </div>
    </div>
</template>

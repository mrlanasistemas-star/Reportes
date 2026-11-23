<script setup lang="ts">
/**
 * AuthFormPanel — columna del formulario en las pantallas de acceso.
 *
 * Se usa en pages/auth/Login y pages/auth/ForgotPassword. Muestra la marca en
 * móvil, una tarjeta con título/subtítulo (distintos para móvil y escritorio),
 * un aviso de estado opcional y el formulario recibido por slot.
 *
 * Props:
 * - mobileTitle / desktopTitle: título para pantallas chicas / desde lg.
 * - mobileSubtitle: subtítulo para móvil (si falta se usa desktopSubtitle).
 * - desktopSubtitle: subtítulo desde lg.
 * - status: mensaje de éxito a mostrar sobre el formulario (p. ej. "enlace enviado").
 *
 * Slots: default (formulario), footer (enlaces bajo la tarjeta).
 */
import { ShieldCheck } from 'lucide-vue-next';
import AuthBackdrop from '@/components/auth/AuthBackdrop.vue';
import AuthBrand from '@/components/auth/AuthBrand.vue';

withDefaults(
    defineProps<{
        mobileTitle: string;
        mobileSubtitle?: string;
        desktopTitle: string;
        desktopSubtitle: string;
        status?: string;
    }>(),
    { mobileSubtitle: undefined, status: undefined },
);

/** Año actual para el aviso de derechos reservados. */
const year = new Date().getFullYear();
</script>

<template>
    <!-- Entrada escalonada (27-oct-2026): marca → tarjeta → pie, sobre AuthBackdrop. -->
    <main
        class="relative isolate flex flex-col items-center justify-center overflow-hidden bg-background px-4 py-10 sm:px-10"
    >
        <AuthBackdrop />

        <div class="relative w-full max-w-[26rem]">
            <!-- Marca visible en móvil/tablet (en escritorio vive en el panel izquierdo) -->
            <div class="app-enter mb-8 flex justify-center lg:hidden">
                <AuthBrand />
            </div>

            <div
                class="app-enter rounded-2xl border bg-card p-6 shadow-soft-lg sm:p-8"
                style="--app-delay: 70ms"
            >
                <div class="mb-6">
                    <h1
                        class="text-2xl font-bold tracking-tight text-foreground"
                    >
                        <span class="lg:hidden">{{ mobileTitle }}</span>
                        <span class="hidden lg:inline">{{ desktopTitle }}</span>
                    </h1>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        <span class="lg:hidden">{{
                            mobileSubtitle ?? desktopSubtitle
                        }}</span>
                        <span class="hidden lg:inline">{{
                            desktopSubtitle
                        }}</span>
                    </p>
                </div>

                <div
                    v-if="status"
                    role="status"
                    class="app-enter app-banner app-banner-success mb-5"
                >
                    <ShieldCheck aria-hidden="true" />
                    <p class="font-medium">{{ status }}</p>
                </div>

                <slot />
            </div>

            <div class="app-enter" style="--app-delay: 140ms">
                <slot name="footer" />
            </div>

            <p
                class="app-enter mt-8 text-center text-xs text-muted-foreground lg:hidden"
                style="--app-delay: 180ms"
            >
                © {{ year }} MR LANA. Todos los derechos reservados.
            </p>
        </div>
    </main>
</template>

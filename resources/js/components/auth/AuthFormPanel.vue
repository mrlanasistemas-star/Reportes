<script setup lang="ts">
import { ShieldCheck } from 'lucide-vue-next'
import AuthBrand from '@/components/auth/AuthBrand.vue'

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
</script>

<template>
    <main class="flex flex-col items-center justify-center bg-background px-4 py-10 sm:px-10">
        <div class="w-full max-w-[26rem]">
            <!-- Marca visible en móvil/tablet (en escritorio vive en el panel izquierdo) -->
            <div class="mb-8 flex justify-center lg:hidden">
                <AuthBrand />
            </div>

            <div class="rounded-2xl border bg-card p-6 shadow-soft-md sm:p-8">
                <div class="mb-6">
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">
                        <span class="lg:hidden">{{ mobileTitle }}</span>
                        <span class="hidden lg:inline">{{ desktopTitle }}</span>
                    </h1>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        <span class="lg:hidden">{{ mobileSubtitle ?? desktopSubtitle }}</span>
                        <span class="hidden lg:inline">{{ desktopSubtitle }}</span>
                    </p>
                </div>

                <div v-if="status" role="status" class="app-banner app-banner-success mb-5">
                    <ShieldCheck aria-hidden="true" />
                    <p class="font-medium">{{ status }}</p>
                </div>

                <slot />
            </div>

            <slot name="footer" />

            <p class="mt-8 text-center text-xs text-muted-foreground lg:hidden">© {{ year }} MR LANA. Todos los derechos reservados.</p>
        </div>
    </main>
</template>

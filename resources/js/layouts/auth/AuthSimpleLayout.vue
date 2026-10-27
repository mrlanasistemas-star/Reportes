<script setup lang="ts">
import AuthBackdrop from '@/components/auth/AuthBackdrop.vue';
import AuthBrand from '@/components/auth/AuthBrand.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!-- Fase 8.3: pantallas de verificación (confirmar contraseña, restablecer,
         verificar correo, código en dos pasos) con la misma marca y tarjeta
         que el inicio de sesión. 27-oct-2026: misma entrada animada y fondo. -->
    <main class="relative isolate flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-4 py-10 sm:px-6">
        <AuthBackdrop />
        <div class="relative w-full max-w-[26rem]">
            <div class="app-enter mb-8 flex justify-center">
                <AuthBrand />
            </div>
            <div class="app-enter rounded-2xl border bg-card p-6 shadow-soft-lg sm:p-8" style="--app-delay: 70ms">
                <div class="mb-6 space-y-1.5">
                    <h1 class="text-xl font-bold tracking-tight text-foreground">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </main>
</template>

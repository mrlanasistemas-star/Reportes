<script setup lang="ts">
import AuthBrand from '@/components/auth/AuthBrand.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!-- Fase 8.3: pantallas de verificación (confirmar contraseña, restablecer,
         verificar correo, código en dos pasos) con la misma marca y tarjeta
         que el inicio de sesión. -->
    <main class="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-10 sm:px-6">
        <div class="w-full max-w-[26rem]">
            <div class="mb-8 flex justify-center">
                <AuthBrand />
            </div>
            <div class="rounded-2xl border bg-card p-6 shadow-soft-md sm:p-8">
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

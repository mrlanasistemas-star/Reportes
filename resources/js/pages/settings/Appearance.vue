<script setup lang="ts">
/**
 * Configuración > Apariencia. Permite elegir tema claro, oscuro o del sistema
 * mediante AppearanceTabs (la preferencia se guarda con useAppearance).
 * Se envuelve en AppLayout + settings/Layout desde app.ts.
 */
import { Head } from '@inertiajs/vue3';
import { Palette } from 'lucide-vue-next';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { edit } from '@/routes/appearance';

/** Migas de pan que recibe el layout. */
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Apariencia',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Apariencia" />

    <h2 class="sr-only">Configuración de apariencia</h2>

    <SettingsCard
        :icon="Palette"
        title="Apariencia"
        description="Elige cómo quieres ver el sistema. Se guarda en este navegador y se aplica de inmediato, también en la pantalla de inicio de sesión."
    >
        <AppearanceTabs />
    </SettingsCard>
</template>

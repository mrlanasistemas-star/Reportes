<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Mail, UserRound } from 'lucide-vue-next';
import Swal from 'sweetalert2';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    name: user.value.name ?? '',
});

const submit = () => {
    form.patch(edit().url, {
        preserveScroll: true,
        onSuccess: () => {
            Swal.fire({
                icon: 'success',
                title: 'Cambios guardados',
                text: 'La información del perfil se actualizó correctamente.',
                confirmButtonText: 'Aceptar',
            });
        },
        onError: (errors) => {
            const firstError =
                errors.name ||
                errors.email ||
                'Ocurrió un error al guardar los cambios. Verifica la información e inténtalo de nuevo.';

            Swal.fire({
                icon: 'error',
                title: 'No se pudo guardar',
                text: firstError,
                confirmButtonText: 'Entendido',
            });
        },
    });
};
</script>

<template>
    <Head title="Perfil" />

    <h2 class="sr-only">Configuración de perfil</h2>

    <form @submit.prevent="submit">
        <SettingsCard :icon="UserRound" title="Información de perfil" description="Así aparece tu nombre en el menú del sistema.">
            <div class="grid gap-5 md:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="name">Nombre completo</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        name="name"
                        required
                        autocomplete="name"
                        placeholder="Nombre completo"
                        :aria-invalid="!!form.errors.name"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="email">Correo electrónico</Label>
                    <div class="relative">
                        <Mail class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="email"
                            type="email"
                            :model-value="user.email"
                            readonly
                            disabled
                            aria-describedby="email-help"
                            class="cursor-not-allowed pl-9"
                        />
                    </div>
                    <p id="email-help" class="app-help">
                        Es tu usuario de acceso; solo un administrador puede cambiarlo.
                    </p>
                </div>
            </div>

            <template #footer>
                <Button type="submit" :disabled="form.processing || !form.isDirty" data-test="update-profile-button">
                    {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                </Button>
            </template>
        </SettingsCard>
    </form>
</template>

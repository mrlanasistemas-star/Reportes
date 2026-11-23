<script setup lang="ts">
/**
 * DeleteUser — sección "Eliminar cuenta" de la configuración del perfil.
 *
 * Muestra una zona de peligro con un botón que abre un diálogo de confirmación;
 * el usuario debe escribir su contraseña y el formulario se envía a
 * ProfileController.destroy. Si hay error, el foco vuelve al campo de
 * contraseña. Sin props ni emits.
 */
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

/** Referencia al campo de contraseña para enfocarlo cuando el servidor rechaza el envío. */
const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Eliminar cuenta"
            description="Elimina tu cuenta y toda su información"
        />
        <div class="app-danger-zone space-y-4">
            <div class="relative space-y-0.5 text-danger">
                <p class="font-medium">Atención</p>
                <p class="text-sm">
                    Procede con cuidado: esta acción no se puede deshacer.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="delete-user-button"
                        >Eliminar cuenta</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle
                                >¿Seguro que quieres eliminar tu
                                cuenta?</DialogTitle
                            >
                            <DialogDescription>
                                Al eliminar tu cuenta se borrarán de forma
                                permanente toda su información y datos. Escribe
                                tu contraseña para confirmar que quieres
                                eliminarla definitivamente.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only"
                                >Contraseña</Label
                            >
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                placeholder="Contraseña"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    Cancelar
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{
                                    processing
                                        ? 'Eliminando…'
                                        : 'Eliminar cuenta'
                                }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>

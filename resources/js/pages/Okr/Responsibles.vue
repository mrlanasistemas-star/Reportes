<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { ArrowLeft, Plus, ShieldCheck, UserPlus, Users } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import AppLayout from '@/layouts/AppLayout.vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import TextField from '@/components/forms/TextField.vue'
import SelectField from '@/components/forms/SelectField.vue'
import { Button } from '@/components/ui/button'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    responsibles: { id: number; name: string; email: string; role: string; status: 'active' | 'pending'; objectives_count: number }[]
    temp_password?: string | null
}>()

// Contraseña temporal — se muestra UNA SOLA VEZ (ver ResponsibleController::index()).
watch(() => props.temp_password, (pwd) => {
    if (!pwd) return
    Swal.fire({
        icon: 'success', title: 'Responsable agregado',
        html: `Contraseña temporal (cópiala ahora, no se volverá a mostrar):<br><code style="font-size:1.1em">${pwd}</code><br><br>Debe usar "¿Olvidaste tu contraseña?" en el login para entrar la primera vez.`,
        confirmButtonColor: '#4f46e5',
    })
}, { immediate: true })

const showForm = ref(false)
const form = useForm({ name: '', email: '', role: 'colaborador' })

function submit() {
    form.post('/okr/responsibles', {
        onSuccess: () => { form.reset(); showForm.value = false },
    })
}

function enableAccess(responsible: { id: number; name: string }) {
    Swal.fire({
        icon: 'question', title: `¿Habilitar acceso para ${responsible.name}?`,
        text: 'Confirma que ya verificaste la identidad de esta persona fuera del sistema.',
        showCancelButton: true, confirmButtonText: 'Habilitar', cancelButtonText: 'Cancelar', confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (r.isConfirmed) router.post(`/okr/responsibles/${responsible.id}/enable-access`)
    })
}
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
            <AppPageHeader title="Responsables" subtitle="Personas que pueden dar seguimiento a un OKR. Agrega nuevas o revisa cuántos objetivos tiene cada quien.">
                <template #actions>
                    <Button type="button" class="app-btn app-btn-primary h-11 gap-2" @click="showForm = !showForm">
                        <Plus class="size-4" /> Agregar responsable
                    </Button>
                </template>
            </AppPageHeader>
        </div>

        <div v-if="showForm" class="app-card animate-in fade-in slide-in-from-top-2 space-y-4 p-5 duration-200">
            <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                <UserPlus class="size-4 text-primary" /> Nuevo responsable
                <OkrHelpTooltip text="Se crea sin acceso todavía — un administrador debe habilitarlo explícitamente después de confirmar la identidad de la persona." />
            </p>
            <div class="grid gap-4 sm:grid-cols-3">
                <TextField v-model="form.name" label="Nombre completo" placeholder="Ej. Ana López" :error="form.errors.name" />
                <TextField v-model="form.email" type="email" label="Correo" placeholder="ana@empresa.com" :error="form.errors.email" />
                <SelectField
                    v-model="form.role"
                    label="Rol"
                    :options="[{ value: 'colaborador', label: 'Colaborador' }, { value: 'admin', label: 'Administrador' }]"
                    description="Administrador puede eliminar OKR y administrar el catálogo de KPI."
                />
            </div>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" class="h-10" @click="showForm = false">Cancelar</Button>
                <Button type="button" class="h-10" :disabled="form.processing" @click="submit">Guardar responsable</Button>
            </div>
        </div>

        <div class="app-table-wrap">
            <div class="app-table-toolbar">
                <div class="flex items-center gap-2">
                    <Users class="size-4 text-primary" />
                    <h2 class="text-sm font-bold text-foreground">{{ responsibles.length }} responsable(s)</h2>
                </div>
            </div>

            <div class="app-table-content">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Nombre</th>
                            <th class="px-4 py-3 text-left font-semibold">Correo</th>
                            <th class="px-4 py-3 text-left font-semibold">Rol</th>
                            <th class="px-4 py-3 text-left font-semibold">Acceso</th>
                            <th class="px-4 py-3 text-left font-semibold">OKR a cargo</th>
                            <th class="px-4 py-3 text-left font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in responsibles" :key="r.id" class="border-t border-border transition hover:bg-muted/30">
                            <td class="px-4 py-3 font-semibold text-foreground">{{ r.name }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ r.email }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold" :class="r.role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'">
                                    <ShieldCheck v-if="r.role === 'admin'" class="size-3" /> {{ r.role === 'admin' ? 'Administrador' : 'Colaborador' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="r.status === 'active' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'">
                                    {{ r.status === 'active' ? 'Activo' : 'Pendiente' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-semibold tabular-nums text-foreground">{{ r.objectives_count }}</td>
                            <td class="px-4 py-3">
                                <button v-if="r.status === 'pending'" type="button" class="text-xs font-bold text-primary hover:underline" @click="enableAccess(r)">Habilitar acceso</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState v-if="responsibles.length === 0" title="Sin responsables" message="Agrega el primero para poder asignarle un OKR." />
            </div>
        </div>
    </div>
</template>

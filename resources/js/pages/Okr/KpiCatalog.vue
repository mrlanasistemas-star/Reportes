<script setup lang="ts">
// Módulo OKR — Catálogo de KPI (rediseño 08-sep-2026, sección AF del pedido).
// Ancho completo del contenedor — nunca max-w-5xl. Solo administradores
// (users.role='admin') pueden entrar aquí — ver OkrServiceProvider.
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ArrowDown, ArrowLeft, ArrowUp, Plus, Sparkles } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import AppLayout from '@/layouts/AppLayout.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import TextField from '@/components/forms/TextField.vue'
import SelectField from '@/components/forms/SelectField.vue'
import { Button } from '@/components/ui/button'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{ kpis: any[]; availableProviders: string[] }>()

const showCreate = ref(false)
const form = useForm({
    code: '', name: '', description: '', unit: 'currency', type: 'cumulative', direction: 'increase',
    automation: 'manual', provider_key: '', is_active: true,
})

function submit() {
    form.post('/okr/kpis', {
        onSuccess: () => {
            form.reset()
            showCreate.value = false
            Swal.fire({ icon: 'success', title: 'KPI creado', confirmButtonColor: '#4f46e5', timer: 1800, showConfirmButton: false })
        },
    })
}

function toggleActive(kpi: any) {
    useForm({
        code: kpi.code, name: kpi.name, description: kpi.description, unit: kpi.unit, type: kpi.type,
        direction: kpi.direction, automation: kpi.automation, provider_key: kpi.provider_key, is_active: !kpi.is_active,
    }).put(`/okr/kpis/${kpi.id}`)
}
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-foreground">Catálogo de KPI</h1>
                    <p class="text-sm text-muted-foreground">Métricas disponibles para armar Key Results — automáticas (Reportería) o manuales.</p>
                </div>
            </div>
            <Button class="h-11 gap-2 rounded-2xl" @click="showCreate = !showCreate">
                <Plus class="size-4" /> Nuevo KPI
            </Button>
        </div>

        <div v-if="showCreate" class="app-card animate-in fade-in slide-in-from-top-2 space-y-4 p-5 duration-200">
            <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                <Sparkles class="size-4 text-primary" /> Nuevo KPI
                <OkrHelpTooltip text="El 'code' es la llave técnica estable (nunca cambia). 'provider' solo puede ser una fuente ya registrada en el sistema — nunca texto libre." />
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <TextField v-model="form.code" label="Code" placeholder="ej. new_metric" :error="form.errors.code" />
                <TextField v-model="form.name" label="Nombre visible" placeholder="Ej. Margen operativo" :error="form.errors.name" />
            </div>
            <div class="grid gap-4 sm:grid-cols-4">
                <SelectField v-model="form.unit" label="Unidad" :options="[{ value: 'currency', label: 'Moneda' }, { value: 'percentage', label: 'Porcentaje' }, { value: 'integer', label: 'Entero' }, { value: 'decimal', label: 'Decimal' }]" />
                <SelectField v-model="form.type" label="Tipo" :options="[{ value: 'cumulative', label: 'Acumulado' }, { value: 'balance', label: 'Saldo' }, { value: 'percentage', label: 'Porcentaje' }]" />
                <SelectField v-model="form.direction" label="Dirección" :options="[{ value: 'increase', label: 'Incrementar ↑' }, { value: 'decrease', label: 'Disminuir ↓' }]" />
                <SelectField v-model="form.automation" label="Automatización" :options="[{ value: 'manual', label: 'Manual' }, { value: 'automatic', label: 'Automático' }, { value: 'hybrid', label: 'Híbrido' }]" />
            </div>
            <div v-if="form.automation !== 'manual'">
                <SelectField
                    v-model="form.provider_key"
                    label="Provider (fuente registrada — nunca texto libre)"
                    placeholder="— Ninguno —"
                    :options="availableProviders.map((p) => ({ value: p, label: p }))"
                    :error="form.errors.provider_key"
                />
            </div>
            <div class="flex gap-2">
                <Button class="h-10 rounded-2xl" :disabled="form.processing" @click="submit">Guardar KPI</Button>
                <Button variant="outline" class="h-10 rounded-2xl" @click="showCreate = false">Cancelar</Button>
            </div>
        </div>

        <div class="app-table-wrap">
            <div class="app-table-content">
                <table v-if="kpis.length" class="w-full min-w-[860px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-3 text-left font-semibold">Code</th>
                            <th class="px-3 py-3 text-left font-semibold">Nombre</th>
                            <th class="px-3 py-3 text-left font-semibold">Tipo</th>
                            <th class="px-3 py-3 text-left font-semibold">Dirección</th>
                            <th class="px-3 py-3 text-left font-semibold">Fuente</th>
                            <th class="px-3 py-3 text-left font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="k in kpis" :key="k.id" class="border-t border-border transition-colors hover:bg-muted/30">
                            <td class="px-3 py-3 font-mono text-xs text-muted-foreground">{{ k.code }}</td>
                            <td class="px-3 py-3 font-semibold text-foreground">{{ k.name }}</td>
                            <td class="px-3 py-3 text-muted-foreground">{{ k.type }}</td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center gap-1 text-muted-foreground">
                                    <ArrowUp v-if="k.direction === 'increase'" class="size-3.5" />
                                    <ArrowDown v-else class="size-3.5" />
                                    {{ k.direction === 'increase' ? 'Incrementar' : 'Disminuir' }}
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <span v-if="k.automation === 'automatic'" class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">Automático — {{ k.provider_key }}</span>
                                <span v-else-if="k.automation === 'hybrid'" class="rounded-full bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300">Híbrido</span>
                                <span v-else class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground">Manual</span>
                            </td>
                            <td class="px-3 py-3">
                                <button
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold transition"
                                    :class="k.is_active ? 'bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300' : 'bg-muted text-muted-foreground hover:bg-muted/70'"
                                    @click="toggleActive(k)"
                                >
                                    {{ k.is_active ? 'Activo' : 'Inactivo' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState v-else title="Sin KPI en el catálogo" message="Agrega el primero para poder usarlo en un Key Result." />
            </div>
        </div>
    </div>
</template>

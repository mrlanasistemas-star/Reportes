<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, Plus } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{ kpis: any[]; availableProviders: string[] }>()

const showCreate = ref(false)
const form = useForm({
    code: '', name: '', description: '', unit: 'currency', type: 'cumulative', direction: 'increase',
    automation: 'manual', provider_key: '', is_active: true,
})

function submit() {
    form.post('/okr/kpis', { onSuccess: () => { form.reset(); showCreate.value = false } })
}

function toggleActive(kpi: any) {
    useForm({
        code: kpi.code, name: kpi.name, description: kpi.description, unit: kpi.unit, type: kpi.type,
        direction: kpi.direction, automation: kpi.automation, provider_key: kpi.provider_key, is_active: !kpi.is_active,
    }).put(`/okr/kpis/${kpi.id}`)
}
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link href="/okr" class="text-slate-400 hover:text-slate-700"><ArrowLeft class="size-5" /></Link>
                <h1 class="text-xl font-black text-slate-950">Catálogo de KPI</h1>
            </div>
            <button @click="showCreate = !showCreate" class="inline-flex h-9 items-center gap-2 rounded-xl bg-indigo-700 px-4 text-xs font-black text-white">
                <Plus class="size-4" /> Nuevo KPI
            </button>
        </div>

        <div v-if="showCreate" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-3 sm:grid-cols-2">
                <input v-model="form.code" placeholder="code (ej. new_metric)" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" />
                <input v-model="form.name" placeholder="Nombre visible" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" />
            </div>
            <div class="grid gap-3 sm:grid-cols-4">
                <select v-model="form.unit" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                    <option value="currency">currency</option><option value="percentage">percentage</option>
                    <option value="integer">integer</option><option value="decimal">decimal</option>
                </select>
                <select v-model="form.type" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                    <option value="cumulative">cumulative</option><option value="balance">balance</option><option value="percentage">percentage</option>
                </select>
                <select v-model="form.direction" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                    <option value="increase">increase</option><option value="decrease">decrease</option>
                </select>
                <select v-model="form.automation" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                    <option value="manual">manual</option><option value="automatic">automatic</option><option value="hybrid">hybrid</option>
                </select>
            </div>
            <div v-if="form.automation !== 'manual'">
                <label class="block text-xs font-bold text-slate-500 mb-1">Provider (fuente registrada — nunca texto libre)</label>
                <select v-model="form.provider_key" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm">
                    <option value="">— Ninguno —</option>
                    <option v-for="p in availableProviders" :key="p" :value="p">{{ p }}</option>
                </select>
            </div>
            <button @click="submit" class="h-9 rounded-xl bg-indigo-700 px-4 text-xs font-black text-white">Guardar KPI</button>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[800px] text-sm">
                <thead class="bg-slate-900 text-white">
                    <tr>
                        <th class="px-3 py-3 text-left font-bold">Code</th>
                        <th class="px-3 py-3 text-left font-bold">Nombre</th>
                        <th class="px-3 py-3 text-left font-bold">Tipo</th>
                        <th class="px-3 py-3 text-left font-bold">Dirección</th>
                        <th class="px-3 py-3 text-left font-bold">Fuente</th>
                        <th class="px-3 py-3 text-left font-bold">Activo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="k in kpis" :key="k.id" class="border-t border-slate-100">
                        <td class="px-3 py-3 font-mono text-xs text-slate-500">{{ k.code }}</td>
                        <td class="px-3 py-3 font-bold text-slate-900">{{ k.name }}</td>
                        <td class="px-3 py-3 text-slate-600">{{ k.type }}</td>
                        <td class="px-3 py-3 text-slate-600">{{ k.direction === 'increase' ? 'Incrementar' : 'Disminuir' }}</td>
                        <td class="px-3 py-3">
                            <span v-if="k.automation === 'automatic'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700">Automático — {{ k.provider_key }}</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-500">Manual</span>
                        </td>
                        <td class="px-3 py-3">
                            <button @click="toggleActive(k)" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="k.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'">
                                {{ k.is_active ? 'Activo' : 'Inactivo' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

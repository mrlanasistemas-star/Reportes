<script setup lang="ts">
// Barra de acción masiva: asigna contraseña nueva a los usuarios seleccionados.
// El backend genera las contraseñas, cierra sus sesiones y deja listo un Excel
// de descarga ÚNICA (Nombre, Usuario, Contraseña, Correo, Sucursal).
import { router } from '@inertiajs/vue3'
import { KeyRound, Loader2, X } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref } from 'vue'
import { Button } from '@/components/ui/button'

const props = defineProps<{ assignUrl: string }>()
const selected = defineModel<number[]>('selected', { required: true })
const processing = ref(false)

async function assign() {
    const count = selected.value.length
    const confirm = await Swal.fire({
        icon: 'warning',
        title: `Asignar contraseña nueva a ${count} usuario${count === 1 ? '' : 's'}`,
        html: `<p>Se generará una contraseña nueva para cada uno. <b>Su contraseña actual dejará de funcionar</b> y se cerrarán sus sesiones abiertas.</p>
<p style="margin-top:.6rem">Después podrás descargar <b>una sola vez</b> el Excel con nombre, usuario, contraseña, correo y sucursal.</p>`,
        showCancelButton: true, confirmButtonText: 'Sí, asignar contraseñas', cancelButtonText: 'Cancelar', confirmButtonColor: '#4f46e5', reverseButtons: true,
    })

    if (!confirm.isConfirmed) {
        return
    }

    processing.value = true
    router.post(props.assignUrl, { user_ids: selected.value }, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = []
        },
        onFinish: () => {
            processing.value = false
        },
    })
}
</script>

<template>
    <div v-if="selected.length > 0" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3">
        <p class="text-sm font-semibold text-foreground">
            {{ selected.length }} usuario{{ selected.length === 1 ? '' : 's' }} seleccionado{{ selected.length === 1 ? '' : 's' }}
        </p>
        <div class="flex items-center gap-2">
            <Button type="button" variant="outline" class="h-9 gap-1.5" :disabled="processing" @click="selected = []">
                <X class="size-4" /> Quitar selección
            </Button>
            <Button type="button" class="h-9 gap-1.5" :disabled="processing" @click="assign">
                <Loader2 v-if="processing" class="size-4 animate-spin" /><KeyRound v-else class="size-4" /> Asignar contraseñas
            </Button>
        </div>
    </div>
</template>

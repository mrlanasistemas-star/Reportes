<script setup lang="ts">
// Resumen del módulo Usuarios: cuántos hay en cada estado de acceso e invitación.
import { computed } from 'vue'
import AppStatCard from '@/components/app/AppStatCard.vue'
import type { UserRow } from './users'

const props = defineProps<{ users: UserRow[] }>()

const stats = computed(() => {
    const u = props.users

    return [
        { title: 'Usuarios', value: u.length, helper: `${u.filter((x) => x.role === 'admin').length} administrador(es)` },
        { title: 'Acceso habilitado', value: u.filter((x) => x.access === 'enabled').length, helper: 'Pueden usar todos los módulos de su rol.' },
        { title: 'Pendientes de activación', value: u.filter((x) => x.access === 'pending').length, helper: 'Esperan que alguien active su acceso.' },
        { title: 'Invitaciones con error', value: u.filter((x) => x.invitation.status === 'failed').length, helper: 'Reintenta el envío desde la tabla.' },
        { title: 'Cuentas bloqueadas', value: u.filter((x) => x.access === 'blocked').length, helper: 'No pueden iniciar sesión.' },
    ]
})
</script>

<template>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <AppStatCard v-for="s in stats" :key="s.title" :title="s.title" :value="s.value" :helper="s.helper" />
    </div>
</template>

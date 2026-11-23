<script setup lang="ts">
/**
 * Pantalla: ROLES Y PERMISOS (/roles) — módulo de Administración (prioridad 8),
 * separado de Usuarios.
 *
 * Para qué sirve: lista los roles, muestra la matriz visual de módulos/acciones
 * del rol elegido y abre el asistente de 3 pasos para crear o editar un rol.
 *
 * Origen de los datos: `App\Http\Controllers\Okr\RoleController@index`
 * (alias `RoleAdministrationController` en routes/web.php), que renderiza
 * `roles/Index` con roles, catálogos de módulos/acciones, URLs y permisos.
 *
 * Rutas que usa: `roles.store` (store_url, POST desde RoleWizardDialog),
 * `roles.update` / `roles.destroy` (update_url / destroy_url de cada rol, usadas
 * por los componentes hijos) y `usuarios.index` (users_url) para ir a Usuarios.
 * Esta página no hace peticiones directas: las delega a sus componentes.
 */
import { Head } from '@inertiajs/vue3';
import { Grid3x3, Plus, Users } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import AppPageHeader from '@/components/app/AppPageHeader.vue';
import { Button } from '@/components/ui/button';
import RoleManager from '@/components/usuarios/RoleManager.vue';
import RolePermissionMatrix from '@/components/usuarios/RolePermissionMatrix.vue';
import type {
    ActionOption,
    Grantable,
    ModuleOption,
    RoleInfo,
} from '@/components/usuarios/roles';
import RoleWizardDialog from '@/components/usuarios/RoleWizardDialog.vue';
import { useAdminFlash } from '@/components/usuarios/useAdminFlash';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

/**
 * Props que envía RoleController@index.
 * - roles: roles con sus módulos, acciones, usuarios, resumen y URLs de edición/borrado.
 * - module_catalog / action_catalog: catálogos para la matriz y el asistente.
 * - columns: etiquetas de columnas de acciones (ActionPermissionService::COLUMNS).
 * - store_url: URL para crear un rol nuevo.
 * - users_url: enlace a Usuarios (null si el actor no tiene ese módulo).
 * - can.create: si el actor puede crear roles.
 * - grantable: lo máximo que el actor puede otorgar (AccessDelegationGuard).
 */
const props = defineProps<{
    roles: RoleInfo[];
    module_catalog: ModuleOption[];
    action_catalog: ActionOption[];
    columns: Record<string, string>;
    store_url: string;
    users_url: string | null;
    can: { create: boolean };
    grantable: Grantable;
}>();

// Muestra con SweetAlert los mensajes flash (éxito/error) que devuelve el servidor.
useAdminFlash();

/** Clave del rol seleccionado; arranca en el primer rol que no sea Administrador (su matriz es "todo"). */
const selectedKey = ref<string | null>(
    props.roles.find((r) => r.key !== 'admin')?.key ??
        props.roles[0]?.key ??
        null,
);
/** Rol completo correspondiente a `selectedKey` (null si ya no existe tras recargar props). */
const selected = computed(
    () => props.roles.find((r) => r.key === selectedKey.value) ?? null,
);

/** Controla la visibilidad del asistente de rol (crear/editar). */
const wizardOpen = ref(false);
/** Rol en edición; null significa que el asistente está creando uno nuevo. */
const editing = ref<RoleInfo | null>(null);

/**
 * Selecciona un rol para ver su matriz de permisos.
 * Tras renderizar, desplaza la vista a la matriz (en móvil queda muy abajo).
 */
async function select(role: RoleInfo) {
    selectedKey.value = role.key;
    await nextTick();
    document
        .getElementById('matriz-permisos')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Abre el asistente en modo "nuevo rol" (sin rol precargado). */
function create() {
    editing.value = null;
    wizardOpen.value = true;
}

/** Abre el asistente precargado con el rol indicado para editarlo. */
function edit(role: RoleInfo) {
    editing.value = role;
    wizardOpen.value = true;
}
</script>

<template>
    <Head title="Roles y permisos" />
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <!-- Encabezado con accesos a Usuarios y "Nuevo rol" -->
        <AppPageHeader
            kicker="Administración"
            title="Roles y permisos"
            subtitle="Qué módulos puede usar cada rol y qué acciones puede hacer dentro de ellos. Cada permiso se valida también en el servidor."
        >
            <template #actions>
                <a
                    v-if="users_url"
                    :href="users_url"
                    class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-4 text-sm font-semibold text-foreground shadow-soft-xs transition hover:bg-muted"
                >
                    <Users class="size-4" aria-hidden="true" /> Usuarios
                </a>
                <Button
                    v-if="can.create"
                    type="button"
                    class="h-10 gap-2 px-4"
                    @click="create"
                >
                    <Plus class="size-4" aria-hidden="true" /> Nuevo rol
                </Button>
            </template>
        </AppPageHeader>

        <!-- Listado de roles (tarjetas con editar / ver permisos) -->
        <RoleManager
            :roles="roles"
            :selected-key="selectedKey"
            @edit="edit"
            @select="select"
        />

        <!-- Matriz de permisos del rol seleccionado -->
        <section
            v-if="selected"
            id="matriz-permisos"
            class="app-section space-y-4 p-4 sm:p-5"
        >
            <div
                class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p class="app-kicker">Permisos del rol</p>
                    <h2
                        class="mt-0.5 flex items-center gap-2 text-lg font-semibold text-foreground"
                    >
                        <Grid3x3
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        {{ selected.name }}
                    </h2>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        selected.key === 'admin'
                            ? 'El Administrador siempre tiene todo.'
                            : selected.summary
                    }}
                </p>
            </div>
            <RolePermissionMatrix
                :modules="selected.modules"
                :actions="selected.actions"
                :module-catalog="module_catalog"
                :action-catalog="action_catalog"
            />
        </section>

        <!-- Asistente de 3 pasos para crear/editar rol -->
        <RoleWizardDialog
            v-model:open="wizardOpen"
            :role="editing"
            :store-url="store_url"
            :modules="module_catalog"
            :actions="action_catalog"
            :grantable="grantable"
        />
    </div>
</template>

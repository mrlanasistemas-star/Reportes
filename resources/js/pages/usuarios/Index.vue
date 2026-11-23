<script setup lang="ts">
/**
 * Pantalla: USUARIOS (/usuarios) — módulo de Administración (prioridad 8/10).
 *
 * Para qué sirve: administrar quién entra al sistema, con qué rol, con qué
 * alcance de sucursales y en qué estado está su acceso (activar, revocar,
 * bloquear, restablecer contraseña, reenviar invitación, ligar colaborador,
 * importación masiva y asignación masiva de contraseñas). Administrar el acceso
 * nunca cambia la situación laboral del colaborador.
 *
 * Es una página orquestadora: secciones, filtros y diálogos viven en
 * components/usuarios/*. Las acciones usan las URLs que genera el backend y
 * el backend vuelve a autorizar cada una (ActionPermissionService +
 * AccessDelegationGuard).
 *
 * Origen de los datos: `App\Http\Controllers\Okr\ResponsibleController@index`
 * (alias `UserAdministrationController` en routes/web.php) renderiza
 * `usuarios/Index`. `employees` es una prop diferida (Inertia::defer). Los
 * resultados de una acción (contraseña temporal, invitación, importación,
 * asignación masiva) llegan como props flash tras la redirección.
 *
 * Rutas que usa: las de `usuarios.*` (store, enable-access, disable-access,
 * toggle-disabled, reset-password, resend-invitation, update-role,
 * link-employee, update-branch-scope, import-preview, import,
 * import-credentials, bulk-reset-passwords y bulk-passwords-download),
 * casi siempre a través de los componentes hijos y useUserActions; además
 * recarga parcial de `users` y `audit` y el enlace a Roles (`roles.index`).
 */
import { Head, router } from '@inertiajs/vue3';
import { FileSpreadsheet, Plus, ShieldCheck } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import AppPageHeader from '@/components/app/AppPageHeader.vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { Button } from '@/components/ui/button';
import BranchScopeDialog from '@/components/usuarios/BranchScopeDialog.vue';
import BulkPasswordBar from '@/components/usuarios/BulkPasswordBar.vue';
import LinkEmployeeDialog from '@/components/usuarios/LinkEmployeeDialog.vue';
import ResetPasswordDialog from '@/components/usuarios/ResetPasswordDialog.vue';
import RoleChangeDialog from '@/components/usuarios/RoleChangeDialog.vue';
import { useAdminFlash } from '@/components/usuarios/useAdminFlash';
import UserAuditLog from '@/components/usuarios/UserAuditLog.vue';
import UserCreateDialog from '@/components/usuarios/UserCreateDialog.vue';
import UserFilters from '@/components/usuarios/UserFilters.vue';
import UserImportDialog from '@/components/usuarios/UserImportDialog.vue';
import type {
    AccessState,
    AuditEntry,
    BranchOption,
    LinkableEmployee,
    UserDataScope,
    UserRoleOption,
    UserRow,
} from '@/components/usuarios/users';
import UsersGrid from '@/components/usuarios/UsersGrid.vue';
import UserSummaryCards from '@/components/usuarios/UserSummaryCards.vue';
import { useUserActions } from '@/components/usuarios/useUserActions';
import { useTabIndicator } from '@/composables/useTabIndicator';
import AppLayout from '@/layouts/AppLayout.vue';
import { downloadFile } from '@/lib/downloadOverlay';

defineOptions({ layout: AppLayout });

/**
 * Props de ResponsibleController@index.
 * - users: filas de usuarios con rol, acceso, invitación, sucursal y permisos por fila (`can`).
 * - employees: colaboradores ligables (diferida).
 * - roles / audit: catálogo de roles y bitácora de cambios.
 * - current_user_id, enabled_admin_count, invitation_expires_minutes, environment: contexto.
 * - store_url, roles_url, import_urls, bulk_password_urls: URLs que genera el backend.
 * - scope_branches / actor_scope: sucursales asignables y alcance del actor.
 * - can: acciones permitidas al actor a nivel página.
 * - bulk_password_result, temp_password, reset_password, invitation_result,
 *   import_preview, import_result: resultados flash de la última acción.
 */
const props = defineProps<{
    users: UserRow[];
    /** Prop DIFERIDA (Inertia::defer): llega en una segunda petición; undefined mientras carga. */
    employees?: LinkableEmployee[];
    roles: UserRoleOption[];
    audit: AuditEntry[];
    current_user_id: number;
    enabled_admin_count: number;
    invitation_expires_minutes: number;
    store_url: string;
    roles_url: string | null;
    scope_branches: BranchOption[];
    actor_scope: UserDataScope;
    environment: string;
    import_urls: {
        preview: string;
        confirm: string;
        credentials: string;
    } | null;
    can: {
        create: boolean;
        invite: boolean;
        manage_access: boolean;
        import: boolean;
        bulk_reset_password: boolean;
    };
    bulk_password_urls: { assign: string; download: string };
    bulk_password_result?: { updated: number; skipped: number } | null;
    temp_password?: { name: string; email: string; password: string } | null;
    reset_password?: { name: string; email: string; password: string } | null;
    invitation_result?: {
        sent: boolean;
        message: string;
        email: string;
    } | null;
    import_preview?: ImportPreview | null;
    import_result?: ImportResult | null;
}>();

// Tipos de la vista previa y del resultado de la importación masiva de usuarios.
type ImportPreviewRow = {
    noi: string | null;
    name: string;
    branch_name: string;
    branch_id: number | null;
    position: string;
    email: string;
    role: string;
    status: 'create' | 'needs_review' | 'exists' | 'error';
    observations: string[];
};
type ImportPreview = {
    environment: string;
    original_name: string;
    summary: Record<string, number>;
    rows: ImportPreviewRow[];
};
type ImportResultRow = {
    name: string;
    branch: string;
    position: string;
    email: string;
    creation: string;
    access: string;
    invitation: string;
    observations: string;
};
type ImportResult = {
    summary: Record<string, number>;
    rows: ImportResultRow[];
    has_credentials: boolean;
};

/** Secciones (pestañas) de la pantalla; se reflejan en `?seccion=` de la URL. */
type Section =
    | 'resumen'
    | 'activos'
    | 'pendientes'
    | 'invitaciones'
    | 'administracion'
    | 'historial';
const SECTIONS: { key: Section; label: string }[] = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'activos', label: 'Activos' },
    { key: 'pendientes', label: 'Accesos pendientes' },
    { key: 'invitaciones', label: 'Invitaciones' },
    { key: 'administracion', label: 'Administración' },
    { key: 'historial', label: 'Historial' },
];

/** Lee la sección inicial desde `?seccion=`; si no es válida, usa "resumen". */
function readSection(): Section {
    const value = new URLSearchParams(window.location.search).get(
        'seccion',
    ) as Section | null;

    return SECTIONS.some((s) => s.key === value)
        ? (value as Section)
        : 'resumen';
}

/** Sección activa. */
const section = ref<Section>(readSection());

/** Cambia de sección y actualiza `?seccion=` en la URL sin navegar (replaceState). */
function setSection(value: Section) {
    section.value = value;
    const url = new URL(window.location.href);
    url.searchParams.set('seccion', value);
    window.history.replaceState(window.history.state, '', url);
}

// Filtros del listado: texto, rol, estado de acceso y sucursal ('__none__' = sin sucursal).
const search = ref('');
const roleFilter = ref('');
const accessFilter = ref<'' | AccessState>('');
const branchFilter = ref('');

/** Sucursales actuales presentes en los usuarios (únicas y ordenadas) para el filtro. */
const branches = computed(() =>
    [
        ...new Set(
            props.users
                .map((u) => u.current_branch)
                .filter((b): b is string => !!b),
        ),
    ].sort(),
);

/** Indica si un usuario pertenece a la sección dada (activos, pendientes, invitaciones, administración…). */
function inSection(u: UserRow, key: Section): boolean {
    switch (key) {
        case 'activos':
            return u.access === 'enabled';
        case 'pendientes':
            return u.access === 'pending';
        // Estado de ENTREGA de la invitación únicamente (pendiente o fallida). No se
        // mezcla con `last_login_at`: que el correo fuera aceptado por el servidor y que
        // la persona haya entrado alguna vez son cosas distintas (una invitación aceptada
        // de alguien que aún no inicia sesión NO es un problema de envío).
        case 'invitaciones':
            return u.invitation.status !== 'accepted';
        case 'administracion':
            return u.role === 'admin' || u.access === 'blocked';
        default:
            return true;
    }
}

/** Usuarios de la sección activa. */
const sectionUsers = computed(() =>
    props.users.filter((u) => inSection(u, section.value)),
);

/** Usuarios de la sección activa que además cumplen búsqueda (nombre, correo, colaborador), rol, acceso y sucursal. */
const visibleUsers = computed(() => {
    const q = search.value.trim().toLowerCase();

    return sectionUsers.value.filter(
        (u) =>
            (q === '' ||
                `${u.name} ${u.email} ${u.employee_name ?? ''}`
                    .toLowerCase()
                    .includes(q)) &&
            (roleFilter.value === '' || u.role === roleFilter.value) &&
            (accessFilter.value === '' || u.access === accessFilter.value) &&
            (branchFilter.value === '' ||
                (branchFilter.value === '__none__'
                    ? !u.current_branch
                    : u.current_branch === branchFilter.value)),
    );
});

/** Contador de cada pestaña (Historial cuenta entradas de la bitácora). */
const sectionCount = (key: Section) =>
    key === 'historial'
        ? props.audit.length
        : props.users.filter((u) => inSection(u, key)).length;

/**
 * Contenedor de pestañas, para el indicador deslizante bajo la pestaña activa
 * (declarado después de section y sectionCount: watch() evalúa su fuente de
 * inmediato). Se re-mide también cuando cambian los contadores, porque cambian
 * el ancho de las pestañas.
 */
const tabsRef = ref<HTMLElement | null>(null);

/**
 * Pestañas accesibles por teclado (patrón WAI-ARIA): ←/→ (cíclico), Inicio y
 * Fin cambian de sección y mueven el foco; solo la pestaña activa entra en el
 * orden de Tab.
 */
function onTabKeydown(event: KeyboardEvent) {
    const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];

    if (!keys.includes(event.key)) {
        return;
    }

    event.preventDefault();
    const current = SECTIONS.findIndex((s) => s.key === section.value);
    const last = SECTIONS.length - 1;
    let next = current;

    if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = last;
    } else if (event.key === 'ArrowRight') {
        next = current >= last ? 0 : current + 1;
    } else {
        next = current <= 0 ? last : current - 1;
    }

    setSection(SECTIONS[next].key);
    nextTick(() =>
        tabsRef.value
            ?.querySelector<HTMLButtonElement>(
                `[data-tab="${SECTIONS[next].key}"]`,
            )
            ?.focus(),
    );
}
// Posición/ancho del indicador; la clave combina sección activa y contadores.
const { indicatorStyle, indicatorReady } = useTabIndicator(
    tabsRef,
    () => `${section.value}|${SECTIONS.map((s) => sectionCount(s.key)).join()}`,
);

/** Acciones directas sobre un usuario (activar, revocar, bloquear, reenviar) y avisos de contraseña. */
const actions = useUserActions();
// Visibilidad de los diálogos de alta y de importación.
const createOpen = ref(false);
const importOpen = ref(false);

/**
 * La vista previa o el resultado llegan como props (flash de sesión): abrir el
 * diálogo de importación cuando aparezcan, para continuar el flujo en su paso.
 */
watch(
    () => [props.import_preview, props.import_result] as const,
    ([p, r]) => {
        if (p || r) {
            importOpen.value = true;
        }
    },
    { immediate: true },
);
// Diálogos por usuario (restablecer contraseña, alcance de sucursales) y su usuario objetivo.
const resetOpen = ref(false);
const resetTarget = ref<UserRow | null>(null);
const scopeOpen = ref(false);
const scopeTarget = ref<UserRow | null>(null);

/** Abre el diálogo de alcance por sucursal del usuario. */
function openScope(u: UserRow) {
    scopeTarget.value = u;
    scopeOpen.value = true;
}

/** Abre el diálogo para restablecer la contraseña del usuario. */
function openReset(u: UserRow) {
    resetTarget.value = u;
    resetOpen.value = true;
}

// Diálogos de cambio de rol y de ligar colaborador, con su usuario objetivo.
const roleOpen = ref(false);
const roleTarget = ref<UserRow | null>(null);
const linkOpen = ref(false);
const linkTarget = ref<UserRow | null>(null);

/** Abre el diálogo de cambio de rol del usuario. */
function openRole(u: UserRow) {
    roleTarget.value = u;
    roleOpen.value = true;
}

/** Abre el diálogo para ligar el usuario con un colaborador. */
function openLink(u: UserRow) {
    linkTarget.value = u;
    linkOpen.value = true;
}

/** Reenvía la invitación (nueva contraseña temporal) y, si se envió, recarga solo `users` y `audit`. */
async function resend(u: UserRow) {
    const invitation = await actions.resendInvitation(u);

    if (invitation) {
        router.reload({ only: ['users', 'audit'] });
    }
}

// Flash genérico con SweetAlert; se suprime cuando hay un resultado que se muestra con su propio diálogo.
useAdminFlash(
    () =>
        !!props.temp_password ||
        !!props.reset_password ||
        !!props.invitation_result ||
        !!props.bulk_password_result,
);

/** Resultado del alta: contraseña generada (una sola vez) y/o estado REAL de la invitación. */
watch(
    () => [props.temp_password, props.invitation_result] as const,
    ([pwd, inv]) => {
        if (!pwd && !inv) {
            return;
        }

        if (pwd) {
            // La contraseña va primero (una sola vez); el estado de la invitación se anota debajo.
            actions.showGeneratedPassword('Usuario creado', pwd, inv?.message);

            return;
        }

        if (inv) {
            Swal.fire({
                icon: inv.sent ? 'success' : 'warning',
                title: 'Usuario creado',
                text: inv.message,
            });
        }
    },
    { immediate: true },
);

/** Ids seleccionados para la asignación masiva de contraseñas. */
const selectedIds = ref<number[]>([]);
/** Tras recargar usuarios, se depuran ids que ya no existen o que ya no admiten restablecer contraseña. */
watch(
    () => props.users,
    (users) => {
        const ids = new Set(
            users.filter((u) => u.can.reset_password).map((u) => u.id),
        );
        selectedIds.value = selectedIds.value.filter((id) => ids.has(id));
    },
);

/** Resultado de la asignación masiva: ofrece descargar el Excel, que solo se puede bajar UNA vez. */
watch(
    () => props.bulk_password_result,
    (result) => {
        if (!result) {
            return;
        }

        const skippedNote =
            result.skipped > 0
                ? `<p style="margin-top:.5rem;font-size:.9em;color:#475569">${result.skipped} seleccionado(s) se omitieron (tu propia cuenta o usuarios que tu rol no administra).</p>`
                : '';
        Swal.fire({
            icon: 'success',
            title: `Contraseñas asignadas: ${result.updated}`,
            html: `<p>Descarga ahora el Excel con nombre, usuario, contraseña, correo y sucursal.</p>
<p style="margin-top:.5rem;color:#b45309">Solo se puede descargar una vez; si lo pierdes, tendrás que volver a asignarlas.</p>${skippedNote}`,
            confirmButtonText: 'Descargar Excel',
            showCancelButton: true,
            cancelButtonText: 'Cerrar',
            allowOutsideClick: false,
        }).then((r) => {
            if (r.isConfirmed) {
                void downloadFile(props.bulk_password_urls.download);
            }
        });
    },
    { immediate: true },
);

/** Resultado de restablecer contraseña: muestra la contraseña generada (una sola vez). */
watch(
    () => props.reset_password,
    (data) => {
        if (data) {
            actions.showGeneratedPassword('Contraseña restablecida', data);
        }
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Usuarios" />
    <div class="w-full space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        <!-- Encabezado: Roles y permisos, Importar usuarios y Nuevo usuario -->
        <AppPageHeader
            kicker="Administración"
            title="Usuarios"
            subtitle="Quién puede entrar al sistema, con qué rol y en qué estado está su acceso. Administrar el acceso nunca cambia la situación laboral del colaborador."
        >
            <template #actions>
                <a
                    v-if="roles_url"
                    :href="roles_url"
                    class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-4 text-sm font-semibold text-foreground shadow-soft-xs transition hover:bg-muted"
                >
                    <ShieldCheck class="size-4" aria-hidden="true" /> Roles y
                    permisos
                </a>
                <Button
                    v-if="can.import && import_urls"
                    type="button"
                    variant="outline"
                    class="h-10 gap-2 px-4"
                    @click="importOpen = true"
                >
                    <FileSpreadsheet class="size-4" aria-hidden="true" />
                    Importar usuarios
                </Button>
                <Button
                    v-if="can.create"
                    type="button"
                    data-tour="usuarios-add"
                    class="h-10 gap-2 px-4"
                    @click="createOpen = true"
                >
                    <Plus class="size-4" aria-hidden="true" /> Nuevo usuario
                </Button>
            </template>
        </AppPageHeader>

        <!-- Pestañas de sección con contador e indicador deslizante -->
        <nav
            ref="tabsRef"
            class="scrollbar-none relative flex gap-1 overflow-x-auto border-b border-border"
            aria-label="Secciones de usuarios"
            role="tablist"
        >
            <button
                v-for="s in SECTIONS"
                :key="s.key"
                type="button"
                role="tab"
                :aria-selected="section === s.key"
                :data-tab="s.key"
                :tabindex="section === s.key ? 0 : -1"
                @keydown="onTabKeydown"
                class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors"
                :class="
                    section === s.key
                        ? 'border-transparent text-foreground'
                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                "
                @click="setSection(s.key)"
            >
                {{ s.label }}
                <span
                    class="rounded-full px-1.5 py-0.5 text-[11px] tabular-nums"
                    :class="
                        section === s.key
                            ? 'bg-primary/10 text-primary'
                            : 'bg-muted'
                    "
                    >{{ sectionCount(s.key) }}</span
                >
            </button>
            <span
                class="app-tab-indicator"
                :style="indicatorStyle"
                :data-ready="indicatorReady"
                aria-hidden="true"
            />
        </nav>

        <!-- Historial (bitácora) o listado de usuarios de la sección -->
        <UserAuditLog v-if="section === 'historial'" :entries="audit" />

        <template v-else>
            <!-- Resumen, filtros, barra de contraseñas masivas y tarjetas de usuarios -->
            <UserSummaryCards v-if="section === 'resumen'" :users="users" />
            <UserFilters
                v-model:search="search"
                v-model:role="roleFilter"
                v-model:access="accessFilter"
                v-model:branch="branchFilter"
                :roles="roles"
                :branches="branches"
            />
            <BulkPasswordBar
                v-if="can.bulk_reset_password"
                v-model:selected="selectedIds"
                :assign-url="bulk_password_urls.assign"
            />
            <UsersGrid
                v-model:selected="selectedIds"
                :selectable="can.bulk_reset_password"
                :users="visibleUsers"
                :resending-id="actions.resendingId.value"
                @enable="actions.enableAccess"
                @revoke="actions.revokeAccess"
                @toggle-blocked="actions.toggleBlocked"
                @reset="openReset"
                @resend="resend"
                @edit-scope="openScope"
                @change-role="openRole"
                @link-employee="openLink"
            />
            <p class="app-help">
                «Aceptada por el servidor de correo» significa que el servidor
                tomó el mensaje para enviarlo; no confirma que la persona lo
                haya recibido. El correo de bienvenida entrega una contraseña
                temporal; si no llegó, puedes reenviarla (se genera una nueva).
            </p>
        </template>

        <!-- Diálogos: alta, alcance, contraseña, rol, colaborador e importación -->
        <UserCreateDialog
            v-model:open="createOpen"
            :store-url="store_url"
            :roles="roles"
            :employees="employees"
            :can-invite="can.invite"
            :can-manage-access="can.manage_access"
            :invitation-expires-minutes="invitation_expires_minutes"
            :roles-url="roles_url"
            :scope-branches="scope_branches"
            :can-grant-all-branches="actor_scope.general"
        />
        <BranchScopeDialog
            v-model:open="scopeOpen"
            :user="scopeTarget"
            :branches="scope_branches"
            :can-grant-all="actor_scope.general"
        />
        <ResetPasswordDialog v-model:open="resetOpen" :user="resetTarget" />
        <RoleChangeDialog
            v-model:open="roleOpen"
            :user="roleTarget"
            :roles="roles"
        />
        <LinkEmployeeDialog
            v-model:open="linkOpen"
            :user="linkTarget"
            :employees="employees"
        />
        <UserImportDialog
            v-if="can.import && import_urls"
            v-model:open="importOpen"
            :urls="import_urls"
            :environment="environment"
            :preview="import_preview ?? null"
            :result="import_result ?? null"
            :can-invite="can.invite"
        />
    </div>
</template>

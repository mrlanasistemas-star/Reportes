<script setup lang="ts">
/**
 * UserMenuContent — contenido del menú desplegable del usuario.
 *
 * Lo usan NavUser (menú lateral) y AppHeader. Muestra la información del
 * usuario y enlaces a Perfil y Seguridad, además de "Cerrar sesión", que pide
 * confirmación con SweetAlert antes de enviar el logout.
 *
 * Props:
 * - user: usuario autenticado.
 */
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings, ShieldCheck } from 'lucide-vue-next';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

import type { User } from '@/types';
type Props = {
    user: User;
};

defineProps<Props>();

/**
 * Pide confirmación para cerrar sesión; si se acepta, limpia la caché de
 * Inertia (router.flushAll) y envía la petición de logout.
 */
const confirmLogout = async () => {
    const active = document.activeElement as HTMLElement | null;
    active?.blur();

    const result = await Swal.fire({
        title: '¿Cerrar sesión?',
        text: 'Tu sesión actual se cerrará.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cerrar sesión',
        cancelButtonText: 'Cancelar',
        reverseButtons: false,
        focusConfirm: false,
        focusCancel: true,
        allowOutsideClick: true,
        allowEscapeKey: true,
        heightAuto: false,
        buttonsStyling: false,
        customClass: {
            popup: 'app-swal-popup',
            icon: 'app-swal-icon',
            title: 'app-swal-title',
            htmlContainer: 'app-swal-text',
            actions: 'app-swal-actions',
            confirmButton: 'app-swal-confirm',
            cancelButton: 'app-swal-cancel',
        },
        didOpen: () => {
            const activeInside = document.activeElement as HTMLElement | null;

            if (activeInside?.blur) {
                activeInside.blur();
            }
        },
    });

    if (!result.isConfirmed) {
        return;
    }

    router.flushAll();
    router.post(logout());
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>

    <DropdownMenuSeparator />

    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Configuración
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="editSecurity()"
                prefetch
            >
                <ShieldCheck class="mr-2 h-4 w-4" />
                Seguridad y contraseña
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <DropdownMenuSeparator />

    <DropdownMenuItem @select.prevent="confirmLogout" data-test="logout-button">
        <LogOut class="mr-2 h-4 w-4" />
        Cerrar sesión
    </DropdownMenuItem>
</template>

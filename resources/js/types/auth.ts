/** Tipos de autenticación compartidos por Inertia (page.props.auth). */
/** Usuario autenticado. */
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    role?: string; // clave del rol (sistema o personalizado, 16-oct-2026)
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Bloque `auth` de las props compartidas (HandleInertiaRequests). */
export type Auth = {
    user: User;
    /** Módulos que el rol del usuario puede ver (ver ModuleAccessService). */
    modules: string[];
    /** Fase 6.4 — alcance por sucursal (DataScopeService::describe). Solo UX: el backend decide. */
    dataScope?: DataScope | null;
};

/**
 * Alcance de datos del usuario: admin/todo, solo ciertas sucursales o modo
 * heredado. `label` es el texto para mostrar. Solo UX: el backend decide.
 */
export type DataScope = {
    mode: 'admin' | 'all' | 'branches' | 'legacy';
    general: boolean;
    branch_ids: number[];
    label: string;
};

/** Textos de la pantalla de verificación en dos pasos según el modo. */
export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

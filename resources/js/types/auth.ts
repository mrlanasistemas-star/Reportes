export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    role?: 'admin' | 'gerencial' | 'colaborador';
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Módulos que el rol del usuario puede ver (ver ModuleAccessService). */
    modules: string[];
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

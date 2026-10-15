// Tipos y utilidades compartidas de "Usuarios y accesos" (16-oct-2026).
export type RoleInfo = {
    key: string
    name: string
    description: string | null
    base_level: 'admin' | 'gerencial' | 'colaborador'
    is_system: boolean
    users_count: number
    modules: string[]
    actions: Record<string, string[]> | null
}

export type ModuleOption = { key: string; label: string; description: string }
export type ActionOption = { module: string; action: string; label: string }

export const BASE_LEVELS = [
    { key: 'gerencial', label: 'Gerencial', hint: 'Ve y gestiona los OKR de su equipo; puede operar los módulos que le habilites.' },
    { key: 'colaborador', label: 'Colaborador', hint: 'Solo ve y da seguimiento a lo propio (su OKR, sus documentos).' },
] as const

// Color estable por rol (decorativo).
const TONES = [
    'from-amber-500 to-orange-600', 'from-sky-500 to-blue-600', 'from-emerald-500 to-teal-600',
    'from-fuchsia-500 to-pink-600', 'from-rose-500 to-red-600', 'from-cyan-500 to-indigo-600',
]

export function roleTone(role: Pick<RoleInfo, 'key'>): string {
    if (role.key === 'admin') {
        return 'from-indigo-600 to-violet-700'
    }

    if (role.key === 'colaborador') {
        return 'from-slate-500 to-slate-700'
    }

    const n = role.key.split('').reduce((acc, ch) => acc + ch.charCodeAt(0), 0)

    return TONES[n % TONES.length]
}

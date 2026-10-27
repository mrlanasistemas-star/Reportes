// Tipos y utilidades del módulo Roles y permisos (prioridad 8/9).
export type RoleUser = { id: number; name: string; email: string; blocked: boolean }

export type RoleInfo = {
    key: string
    name: string
    description: string | null
    base_level: 'admin' | 'gerencial' | 'colaborador'
    is_system: boolean
    modules: string[]
    actions: Record<string, string[]>
    users: RoleUser[]
    summary: string
    can_edit: boolean
    can_delete: boolean
    update_url: string | null
    destroy_url: string | null
}

export type ModuleOption = { key: string; label: string; description: string }
export type ActionColumn = 'consultar' | 'crear' | 'editar' | 'eliminar' | 'exportar' | 'autorizar'
/** `requires`: acciones del mismo módulo que esta necesita (ActionPermissionService::REQUIRES). */
export type ActionOption = { module: string; action: string; label: string; column: ActionColumn; description: string; requires?: string[] }
export type Grantable = { modules: string[]; actions: Record<string, string[]> }

/** Columnas de la matriz: "Ver" es el acceso al módulo; el resto son acciones. */
export const MATRIX_COLUMNS: { key: 'ver' | ActionColumn; label: string }[] = [
    { key: 'ver', label: 'Ver' },
    { key: 'consultar', label: 'Consultar' },
    { key: 'crear', label: 'Crear' },
    { key: 'editar', label: 'Editar' },
    { key: 'eliminar', label: 'Eliminar' },
    { key: 'exportar', label: 'Exportar' },
    { key: 'autorizar', label: 'Autorizar' },
]

export const BASE_LEVELS = [
    { key: 'gerencial', label: 'Gerencial', hint: 'Ve y gestiona los OKR de toda la operación; opera los módulos que le habilites.' },
    { key: 'colaborador', label: 'Colaborador', hint: 'Solo ve y da seguimiento a lo propio (su OKR, sus documentos). Si en Usuarios no se le asigna un alcance por sucursal, solo consulta la sucursal de su colaborador.' },
] as const

/** Aclaraciones por módulo que se muestran en su tarjeta de permisos. */
export const MODULE_NOTES: Record<string, string> = {
    reportes: '«Ver reportes de todos» muestra los reportes generados por cualquier usuario, pero siempre dentro del alcance por sucursal de cada usuario (se asigna en Usuarios): con alcance general ve todos, incluidos los generales/consolidados; con sucursales específicas, solo los de esas sucursales.',
}

/** Acciones que se pre-marcan al dar acceso a un módulo (sin ellas el módulo abre vacío). */
export const DEFAULT_ON_ENABLE: Record<string, string[]> = {
    reportes: ['ver_propios'],
}

/** Acciones que `action` requiere, transitivas (sin incluirla). */
export function requiredBy(catalog: ActionOption[], module: string, action: string): string[] {
    const out = new Set<string>()
    const pending = [action]

    while (pending.length) {
        const current = pending.pop() as string
        const def = catalog.find((a) => a.module === module && a.action === current)

        for (const req of def?.requires ?? []) {
            if (!out.has(req)) {
                out.add(req)
                pending.push(req)
            }
        }
    }

    return [...out]
}

/** Acciones que dependen (directa o indirectamente) de `action`. */
export function dependentsOf(catalog: ActionOption[], module: string, action: string): string[] {
    return actionsIn(catalog, module).filter((a) => requiredBy(catalog, module, a.action).includes(action)).map((a) => a.action)
}

export function actionLabel(catalog: ActionOption[], module: string, action: string): string {
    return catalog.find((a) => a.module === module && a.action === action)?.label ?? action
}

/**
 * Marca/desmarca una acción respetando dependencias: al marcar se agregan las
 * requeridas; al desmarcar se quitan las que dependen de ella. Devuelve la
 * lista nueva y lo que se agregó/quitó además de la acción pedida (para avisar).
 */
export function toggleWithDependencies(catalog: ActionOption[], module: string, current: string[], action: string): { next: string[]; added: string[]; removed: string[] } {
    const order = actionsIn(catalog, module).map((a) => a.action)
    const sort = (list: Iterable<string>) => order.filter((a) => new Set(list).has(a))

    if (current.includes(action)) {
        const removed = dependentsOf(catalog, module, action).filter((a) => current.includes(a))
        const drop = new Set([action, ...removed])

        return { next: sort(current.filter((a) => !drop.has(a))), added: [], removed }
    }

    const added = requiredBy(catalog, module, action).filter((a) => !current.includes(a))

    return { next: sort([...current, action, ...added]), added, removed: [] }
}

export function actionsIn(catalog: ActionOption[], module: string, column?: ActionColumn): ActionOption[] {
    return catalog.filter((a) => a.module === module && (!column || a.column === column))
}

/** Cuenta solo acciones de módulos habilitados (lo mismo que guarda el backend). */
export function countActions(modules: string[], actions: Record<string, string[]>): number {
    return modules.reduce((n, m) => n + (actions[m]?.length ?? 0), 0)
}

export function summaryText(name: string, modules: string[], actions: Record<string, string[]>): string {
    const m = modules.length
    const a = countActions(modules, actions)

    return `${name || 'Este rol'} puede utilizar ${m} ${m === 1 ? 'módulo' : 'módulos'} y tiene ${a} ${a === 1 ? 'acción permitida' : 'acciones permitidas'}.`
}

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

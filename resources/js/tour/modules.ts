import {
    BarChart3, CalendarRange, FileSpreadsheet, LayoutDashboard, Settings, ShieldCheck, Target, Upload, Users, Wallet,
} from 'lucide-vue-next'
import type { Component } from 'vue'

// Módulos del Centro de ayuda (16-oct-2026): qué guía/recorrido corresponde a
// la pantalla actual. El botón flotante de la derecha usa `paths` para llevarte
// a la guía DEL MÓDULO en el que estás. Orden importa: el prefijo más
// específico primero (/okr/responsibles antes que /okr).
export type GuideModule = {
    id: string
    tourId: string | null
    label: string
    description: string
    /** Qué datos usa el módulo (se muestra en la ayuda «?»). */
    uses: string
    /** Qué hacer si falta información o algo aparece vacío. */
    missing: string
    icon: Component
    tone: string
    paths: string[]
    /** Paso del manual ilustrado relacionado (ver GuideManual.vue). */
    manualStep?: string
    topics: string[]
    /** Módulos (auth.modules) que habilitan esta guía; basta uno. Omitido = todos lo ven. */
    access?: string[]
}

export const GUIDE_MODULES: GuideModule[] = [
    {
        id: 'dashboard', access: ['dashboard'], tourId: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, tone: 'from-emerald-600 to-teal-700',
        description: 'Resumen ejecutivo: EBITDA, OPEX, colocación, recuperación, cartera y mora del periodo.',
        uses: 'La radiografía generada de cada periodo (nómina, cartera, cobranza, colocación y gastos ya procesados).',
        missing: 'Si un periodo no aparece o sus cifras salen en cero, revisa en Carga de archivos que sus fuentes estén procesadas y que el reporte esté generado.',
        paths: ['/dashboard'], manualStep: 's17', topics: ['Periodo', 'Sucursal', 'Exportar'],
    },
    {
        id: 'carga', access: ['carga'], tourId: 'carga', label: 'Carga de archivos', icon: Upload, tone: 'from-sky-500 to-blue-600',
        description: 'Sube los archivos fuente del periodo y revisa su validación y procesamiento.',
        uses: 'Los archivos fuente del mes operativo (nómina, cartera, cobranza, colocación y gastos), validados contra las columnas autorizadas.',
        missing: 'Si una fuente queda con error o incidencias, corrige el archivo y vuelve a subirlo. Si no ves el periodo, créalo primero en Periodos.',
        paths: ['/historico-general'], manualStep: 's4', topics: ['Fuentes', 'Incidencias', 'Generar reporte'],
    },
    {
        id: 'periodos', access: ['periodos'], tourId: 'periodos', label: 'Periodos', icon: CalendarRange, tone: 'from-emerald-500 to-teal-600',
        description: 'Crea y administra los meses operativos que usa todo el sistema.',
        uses: 'Semanas base y los meses operativos formados con ellas; bimestres, trimestres y demás se arman solos.',
        missing: 'Si el mes no aparece, genera sus semanas (Paso 1) y después arma el mes operativo (Paso 2).',
        paths: ['/periodos'], manualStep: 's3', topics: ['Alta', 'Cierre'],
    },
    {
        id: 'colaboradores', access: ['colaboradores'], tourId: 'colaboradores', label: 'Colaboradores', icon: Users, tone: 'from-amber-500 to-orange-600',
        description: 'Sucursal de cada colaborador por periodo, altas y bajas detectadas en nómina.',
        uses: 'La nómina procesada de cada periodo y el catálogo oficial de sucursales y rutas.',
        missing: 'Si un colaborador falta o aparece sin sucursal, verifica que la nómina del periodo esté cargada y asígnale su sucursal.',
        paths: ['/asignaciones-empleado-sucursal', '/empleados'], manualStep: 's16', topics: ['Sucursal', 'Altas y bajas'],
    },
    {
        id: 'reportes', access: ['reportes'], tourId: 'reportes', label: 'Reportes mensuales', icon: FileSpreadsheet, tone: 'from-fuchsia-500 to-pink-600',
        description: 'Radiografía financiera por periodo, comparativos y exportación a Excel/PDF.',
        uses: 'La radiografía generada del periodo; los comparativos usan la de ambos periodos.',
        missing: 'Si ves «Sin radiografía generada», genérala en Carga de archivos, etapa «Generar reporte».',
        paths: ['/reportes-mensuales'], manualStep: 's12', topics: ['Comparativos', 'Excel', 'PDF'],
    },
    {
        id: 'efectividad', access: ['efectividad'], tourId: 'efectividad', label: 'Efectividad de cobranza', icon: Wallet, tone: 'from-rose-500 to-red-600',
        description: 'Calcula la efectividad de cobranza por ruta y gestor a partir del archivo de Lendus.',
        uses: 'Las carteras diarias (Saldos por Cliente) y el Ingresos Cobranza de Lendus que subes en el momento; no se guardan.',
        missing: 'Si falta un día o una ruta, confirma que subiste la cartera de ese día en el formato de Lendus.',
        paths: ['/efectividad-cobranza'], topics: ['Archivo Lendus', 'Resultados'],
    },
    {
        id: 'usuarios', access: ['usuarios', 'roles'], tourId: 'usuarios', label: 'Usuarios y accesos', icon: ShieldCheck, tone: 'from-slate-600 to-slate-800',
        description: 'Usuarios, roles y permisos por módulo y acción.',
        uses: 'Las cuentas de usuario, los roles (de sistema y personalizados) y los módulos y acciones de cada rol.',
        missing: 'Si alguien no ve un módulo, revisa los permisos de su rol en Roles. Si no recibió su invitación, revisa su estado en la tabla de usuarios.',
        paths: ['/usuarios', '/roles'], topics: ['Roles', 'Permisos', 'Correo de bienvenida'],
    },
    {
        id: 'okr', access: ['okr'], tourId: 'okr', label: 'OKR', icon: Target, tone: 'from-violet-500 to-purple-700',
        description: 'Objetivos por sucursal y gestor: línea base, metas, seguimiento, Cartas Compromiso y Warnings.',
        uses: 'Las metas que capturas, la reportería del periodo y la colocación semanal que subes por sucursal.',
        missing: 'Si un avance sale en cero o «sin dato», sube la colocación de la semana o usa «Recalcular con Reportería» en el detalle del OKR.',
        paths: ['/okr'], topics: ['Línea base', 'Metas por gestor', 'Carta Compromiso', 'Warnings'],
    },
    {
        id: 'configuracion', tourId: 'configuracion', label: 'Configuración', icon: Settings, tone: 'from-zinc-500 to-zinc-700',
        description: 'Tu perfil, contraseña, verificación en dos pasos y apariencia.',
        uses: 'Tu cuenta: nombre, correo, contraseña, verificación en dos pasos y apariencia.',
        missing: 'Si no puedes cambiar un dato, pide ayuda a un administrador.',
        paths: ['/settings'], topics: ['Perfil', 'Seguridad'],
    },
    {
        id: 'general', access: ['carga', 'reportes'], tourId: null, label: 'Flujo general', icon: BarChart3, tone: 'from-cyan-500 to-sky-600',
        description: 'De la carga de archivos al reporte final, paso a paso.',
        uses: 'Periodos, archivos fuente y la radiografía generada.',
        missing: 'Sigue el orden: Periodos → Carga de archivos → Generar reporte → Reportes mensuales.',
        paths: [], manualStep: 's2', topics: ['Inicio rápido'],
    },
]

export function moduleForPath(pathname: string): GuideModule | null {
    return GUIDE_MODULES.find((m) => m.paths.some((p) => pathname === p || pathname.startsWith(p + '/'))) ?? null
}

export function moduleById(id: string | null | undefined): GuideModule | null {
    return GUIDE_MODULES.find((m) => m.id === id) ?? null
}

/**
 * Fase 8.13 (15-oct-2026): la guía solo ofrece módulos que el rol del usuario
 * puede abrir — nunca un recorrido sobre una pantalla que le responderá 403.
 */
export function guideModulesFor(allowed: readonly string[] | null | undefined): GuideModule[] {
    const list = allowed ?? []

    return GUIDE_MODULES.filter((m) => !m.access || m.access.some((key) => list.includes(key)))
}

/** Recorrido de bienvenida: el Dashboard si lo tiene; si no, el primer módulo permitido. */
export function welcomeTourFor(allowed: readonly string[] | null | undefined): string | null {
    return guideModulesFor(allowed).find((m) => m.tourId)?.tourId ?? null
}

import type { TourDefinition } from './types'

// Registro centralizado de recorridos (Fase 2 de la auditoría, 15-oct-2026) —
// referencia arquitectónica: el ERP MR LANA ya resuelve esto con
// tour/registry.ts + Composables/useTour.ts; aquí se adapta el MISMO concepto
// a Reportes sin copiar dependencias incompatibles (implementación propia,
// sin paquetes externos de tours).
export const TOURS: Record<string, TourDefinition> = {
    dashboard: {
        id: 'dashboard',
        label: 'Dashboard',
        steps: [
            {
                href: '/dashboard',
                title: 'Resumen ejecutivo',
                body: 'Aquí ves de un vistazo cómo va la operación: EBITDA, OPEX, colocación, recuperación, cartera y mora del periodo más reciente.',
                example: 'Ejemplo: si el EBITDA sube respecto al mes pasado, la gráfica de tendencia te lo muestra de inmediato.',
            },
            {
                target: 'dashboard-period',
                title: 'Selector de periodo',
                body: 'Cambia el mes que quieres consultar. Todos los indicadores y gráficas se actualizan al instante, sin recargar la página.',
            },
            {
                target: 'dashboard-branch',
                title: 'Filtro por sucursal',
                body: 'Elige una sucursal para ver solo sus números, o déjalo en "Todas las sucursales" para el consolidado autorizado.',
                example: 'Ejemplo: selecciona ATLIXCO para ver únicamente el EBITDA y la cartera de esa sucursal.',
            },
            {
                target: 'dashboard-export',
                title: 'Exportar Excel y PDF',
                body: 'Descarga exactamente lo que estás viendo — mismo periodo, misma sucursal — en Excel o en un reporte PDF con gráficas.',
            },
            {
                target: 'nav-okr',
                title: 'Módulo OKR',
                body: 'Desde aquí administras los objetivos de cada sucursal y colaborador: metas, seguimiento, Cartas Compromiso y Warnings.',
            },
        ],
    },
    okr: {
        id: 'okr',
        label: 'OKR',
        steps: [
            {
                title: '¿Qué es un OKR?',
                body: 'Es un objetivo que quieres alcanzar en un plazo definido.',
            },
            {
                title: '¿Qué es un Key Result?',
                body: 'Es la medida que utilizarás para saber si estás cumpliendo ese objetivo.',
            },
            {
                target: 'okr-baseline',
                title: 'Línea base',
                body: 'Es el valor desde el que empiezas. Cuando activas el OKR se conserva para comparar tus avances.',
            },
            {
                target: 'okr-increment',
                title: 'Incremento esperado',
                body: 'Es cuánto deseas mejorar respecto a tu línea base.',
            },
            {
                target: 'okr-goal',
                title: 'Meta final',
                body: 'Es el resultado que quieres alcanzar.',
            },
            {
                target: 'okr-gestores',
                title: 'OKR por gestor',
                body: 'Puedes dar una meta diferente a cada gestor sin cambiar la meta general de la sucursal.',
            },
            {
                target: 'okr-placement-upload',
                title: 'Colocación semanal',
                body: 'Subes una sola vez el archivo de la sucursal. El sistema calcula la colocación general y la correspondiente a cada gestor.',
            },
            {
                target: 'okr-letter-button',
                title: 'Carta Compromiso',
                body: 'Documento que contiene los compromisos del colaborador. Puedes revisarlo antes de emitirlo.',
            },
            {
                target: 'okr-warning-button',
                title: 'Warning',
                body: 'Documento de seguimiento ante un incumplimiento comprobado. No se genera automáticamente cuando falta información.',
            },
        ],
    },
    carga: {
        id: 'carga',
        label: 'Carga de archivos',
        steps: [
            {
                href: '/historico-general',
                title: 'Carga de archivos',
                body: 'Aquí subes los archivos fuente de cada periodo (nómina, cartera, cobranza, etc.). El sistema valida y procesa cada uno.',
            },
            {
                target: 'carga-upload-zone',
                title: 'Subir un archivo',
                body: 'Arrastra o selecciona el archivo correspondiente a este periodo. Verás el estado de cada carga: pendiente, procesado o con errores.',
            },
        ],
    },
    periodos: {
        id: 'periodos',
        label: 'Periodos',
        steps: [
            {
                href: '/periodos',
                title: 'Periodos',
                body: 'Un periodo representa un mes operativo. Aquí creas, abres y cierras los periodos que usará el resto del sistema.',
            },
        ],
    },
    colaboradores: {
        id: 'colaboradores',
        label: 'Colaboradores',
        steps: [
            {
                href: '/asignaciones-empleado-sucursal',
                title: 'Colaboradores',
                body: 'Aquí ves la sucursal asignada a cada colaborador por periodo, y las altas/bajas detectadas a partir de la nómina.',
            },
        ],
    },
    reportes: {
        id: 'reportes',
        label: 'Reportes mensuales',
        steps: [
            {
                href: '/reportes-mensuales',
                title: 'Reportes mensuales',
                body: 'Consulta la radiografía financiera completa de cada periodo: resultados, comparativos y exportaciones a Excel/PDF.',
            },
        ],
    },
    efectividad: {
        id: 'efectividad',
        label: 'Efectividad de cobranza',
        steps: [
            {
                href: '/efectividad-cobranza',
                title: 'Efectividad de cobranza',
                body: 'Sube las carteras diarias (Saldos por Cliente) y el Ingresos Cobranza de Lendus; el sistema calcula la efectividad por ruta y por gestor en modo diario, acumulado, semanal o mensual. Los archivos no se guardan: el cálculo es temporal.',
                example: 'Ejemplo: una ruta que cobró $90 de $100 esperados tiene 90% de efectividad.',
            },
            {
                title: 'Resultados',
                body: 'Revisa el detalle por ruta y gestor y descárgalo como imagen PNG o PDF. Solo se consideran las rutas con cartera sana del periodo. El pago puntual de la cuota vigente cuenta como sano aunque el cliente tenga atrasos anteriores.',
            },
        ],
    },
    usuarios: {
        id: 'usuarios',
        label: 'Usuarios y accesos',
        steps: [
            {
                href: '/usuarios',
                title: 'Usuarios',
                body: 'Aquí administras quién puede entrar: su rol (de sistema o personalizado), el estado de su acceso (habilitado, pendiente de activación o bloqueado), su invitación y su último acceso. Activar o bloquear un acceso nunca cambia la situación laboral del colaborador.',
            },
            {
                target: 'usuarios-add',
                title: 'Nuevo usuario',
                body: 'Elige el rol y cómo obtiene su contraseña: invitación por correo para que la establezca, contraseña generada (la verás una sola vez) o escrita por ti con confirmación.',
            },
            {
                href: '/roles',
                title: 'Roles y permisos',
                body: 'Es un módulo aparte. Crea roles personalizados en 3 pasos (nombre, módulos y acciones), revisa la matriz Ver/Crear/Editar/Eliminar/Exportar/Autorizar de cada rol y quién lo tiene asignado.',
            },
        ],
    },
    configuracion: {
        id: 'configuracion',
        label: 'Configuración',
        steps: [
            {
                href: '/settings/profile',
                title: 'Configuración',
                body: 'Aquí administras tu perfil, tu seguridad (contraseña y verificación en dos pasos) y la apariencia del sistema.',
            },
        ],
    },
}

export function tourExists(id: string): boolean {
    return id in TOURS
}

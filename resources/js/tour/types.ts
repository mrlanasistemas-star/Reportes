// Guía interactiva (Fase 2 de la auditoría, 15-oct-2026) — tipos compartidos
// por el registro de recorridos y el overlay. Cada paso apunta a un elemento
// real vía `data-tour="<target>"` en el DOM; si ese elemento no existe en la
// página actual (rol sin acceso, módulo distinto, etc.) el paso se SALTA solo
// — nunca se traba la guía ni se rompe la navegación normal.
export type TourStep = {
    /** Debe coincidir con un atributo data-tour="..." en el DOM. Omitir para un paso introductorio centrado (sin resaltar nada). */
    target?: string
    title: string
    body: string
    /** Ejemplo práctico opcional, mostrado en cursiva bajo el texto principal. */
    example?: string
    /** Si el siguiente paso vive en otra pantalla, se navega aquí (Inertia) antes de buscar el target. */
    href?: string
    /**
     * Primer segmento de URL del módulo al que pertenece este paso (p. ej. 'okr',
     * 'usuarios'). Sirve para que un paso SIN `href` (p. ej. uno que resalta algo de
     * otro módulo) también respete permisos: si el rol no ve ese módulo en el menú,
     * el paso se salta. Omitir para pasos del propio módulo del recorrido.
     */
    module?: string
}

export type TourDefinition = {
    id: string
    label: string
    steps: TourStep[]
}

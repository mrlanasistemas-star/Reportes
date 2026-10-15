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
}

export type TourDefinition = {
    id: string
    label: string
    steps: TourStep[]
}

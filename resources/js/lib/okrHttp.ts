// Peticiones fetch() del módulo OKR (15-oct-2026): CSRF por cookie XSRF-TOKEN
// (mismo mecanismo que Inertia/axios), JSON, y un resultado uniforme para que
// cada botón limpie SU propio estado de carga en éxito, 4xx, 5xx o corte de red.
export type OkrResult<T = any> = { ok: true; status: number; data: T } | { ok: false; status: number; message: string; data: any }

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
}

export async function okrRequest<T = any>(url: string, init: RequestInit & { json?: unknown } = {}): Promise<OkrResult<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), ...(init.headers as Record<string, string> | undefined) }
    let body = init.body

    if (init.json !== undefined) {
        headers['Content-Type'] = 'application/json'
        body = JSON.stringify(init.json)
    }

    try {
        const res = await fetch(url, { ...init, body, headers, cache: 'no-store' })
        const data = await res.json().catch(() => null)

        if (res.ok) {
            return { ok: true, status: res.status, data }
        }

        const firstError = data?.errors ? (Object.values(data.errors).flat()[0] as string | undefined) : undefined
        // 404/405 nunca muestran el mensaje crudo del framework (p.ej. "The route
        // X could not be found.") — ese texto no dice nada útil al usuario final.
        // Para 422 (validación) y errores de negocio sí usamos data.message,
        // porque esos SÍ están escritos para el usuario (ver controllers Okr\*).
        const friendlyByStatus: Record<number, string> = {
            403: 'No tienes permiso para esta acción.',
            404: 'No se encontró la información solicitada. Intenta de nuevo o recarga la página.',
            405: 'No se pudo completar la acción. Recarga la página e intenta de nuevo.',
            419: 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.',
        }
        const message = firstError
            ?? (res.status in friendlyByStatus ? friendlyByStatus[res.status] : data?.message)
            ?? (res.status >= 500 ? 'Ocurrió un error en el servidor. Intenta de nuevo.' : 'No se pudo completar la acción.')

        return { ok: false, status: res.status, message, data }
    } catch {
        return { ok: false, status: 0, message: 'Sin conexión con el servidor. Revisa tu red e intenta de nuevo.', data: null }
    }
}

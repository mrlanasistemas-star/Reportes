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
            404: 'Lo que intentas consultar ya no existe: pudo eliminarse o el enlace ya no es válido. Vuelve al listado para ver la información vigente.',
            405: 'Esta acción no está disponible desde aquí. Si el problema continúa, informa a Sistemas.',
            419: 'Tu sesión expiró por inactividad. Inicia sesión de nuevo para continuar.',
        }
        const raw = typeof data?.message === 'string' ? data.message : ''
        // 4.4 (19-oct-2026): ruta NO registrada en el servidor (p. ej. caché de rutas
        // desactualizada tras un despliegue) ≠ "el registro ya no existe". Se dice
        // exactamente qué falta para que Sistemas lo corrija.
        const routeMissing = res.status === 404 && /could not be found/i.test(raw)
            ? `Esta función no está disponible en el servidor: la ruta ${(init.method ?? 'GET').toUpperCase()} ${url.split('?')[0]} no está registrada (posible actualización incompleta). Informa a Sistemas para que actualice el servidor.`
            : undefined
        // 404 con mensaje propio del backend (p. ej. "No encontramos el PDF de CC-…") se respeta.
        const ownNotFound = res.status === 404 && raw !== '' && !/could not be found|No query results|^Not Found$/i.test(raw) ? raw : undefined
        // 403 con mensaje propio del backend (abort(403, '...') escrito para el usuario) se respeta.
        const message = firstError
            ?? routeMissing
            ?? ownNotFound
            ?? (res.status === 403 && typeof data?.message === 'string' && data.message !== 'This action is unauthorized.' ? data.message : undefined)
            ?? (res.status in friendlyByStatus ? friendlyByStatus[res.status] : data?.message)
            ?? (res.status >= 500 ? 'No pudimos completar la operación por un error técnico. Intenta nuevamente; si continúa, informa a Sistemas.' : `No se pudo completar la acción (código ${res.status}).`)

        return { ok: false, status: res.status, message, data }
    } catch {
        return { ok: false, status: 0, message: 'Sin conexión con el servidor. Revisa tu red e intenta de nuevo.', data: null }
    }
}

/**
 * Módulo: tareas en segundo plano del Estado de Resultados.
 *
 * Algunas acciones del ER («Procesar conciliación», «Aprobar») se ejecutan en
 * cola en el servidor. Este módulo hace el POST y, si el servidor responde 202
 * con una tarea encolada, consulta su estado periódicamente hasta que termine,
 * devolviendo el mismo JSON (o lanzando el mismo error) que daría la petición
 * síncrona. Así los llamadores no distinguen entre cola `sync` y cola real.
 */
import {
    ApiError,
    apiJson,
    friendlyError,
} from '@/components/estado-resultados/types';

/** Estado público de una tarea en segundo plano del ER (EstadoResultadosTask::toStatusArray). */
export interface ErTaskStatus {
    id: number;
    kind: 'procesar' | 'aprobar';
    status: 'queued' | 'running' | 'ok' | 'error';
    finished: boolean;
    progress_percent: number;
    current_step: string | null;
    http_status: number | null;
    response: Record<string, unknown> | null;
}

/** Cuerpo de respuesta cuando el servidor encola la acción (HTTP 202 con `queued: true`). */
interface QueuedBody {
    queued?: boolean;
    message?: string;
    task?: ErTaskStatus | null;
}

/** Intervalo entre consultas del estado de la tarea (ms). */
const POLL_MS = 3000;
/** Límite de espera en la pantalla (el job tiene 30 min de timeout). */
const MAX_WAIT_MS = 35 * 60 * 1000;

/** Espera asíncrona de `ms` milisegundos entre consultas. */
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Resultado de la tarea terminada: el mismo JSON (o el mismo error) que daba la petición síncrona.
 * Si la tarea no guardó `http_status`, se asume 200 cuando terminó en `ok` y 500 en otro caso.
 * @param task Tarea ya terminada.
 * @returns El cuerpo de respuesta tipado.
 * @throws ApiError cuando el estado HTTP registrado es >= 400.
 */
function settle<T>(task: ErTaskStatus): T {
    const body = (task.response ?? {}) as Record<string, unknown>;
    const status = task.http_status ?? (task.status === 'ok' ? 200 : 500);

    if (status >= 400) {
        throw new ApiError(
            friendlyError(status, body.message as string | undefined),
            (body.details as string[] | undefined) ?? [],
            status,
            body,
        );
    }

    return body as T;
}

/**
 * POST de una acción que el servidor ejecuta en cola («Procesar conciliación», «Aprobar»).
 * - 200/4xx inmediatos (cola sync o validación previa): igual que apiJson.
 * - 202: consulta /tareas/{id} cada 3 s hasta que termine y devuelve su resultado.
 * - 409 con una tarea abierta del mismo tipo (p. ej. tras recargar la página): se engancha a
 *   esa tarea en lugar de lanzar otra.
 * `onWaiting` permite actualizar el aviso «en proceso» con la etapa actual.
 * Pasados 35 min de espera en pantalla lanza un ApiError (status 0) pidiendo recargar.
 * @param url Ruta POST de la acción.
 * @param tasksBase Ruta base de consulta de tareas (se le agrega `/{id}`).
 * @param init Opciones de fetch (método, cuerpo, cabeceras).
 * @param onWaiting Callback invocado en cada vuelta mientras la tarea no termina.
 * @returns El resultado final de la acción.
 */
export async function runErTask<T>(
    url: string,
    tasksBase: string,
    init: RequestInit = {},
    onWaiting?: (task: ErTaskStatus) => void,
): Promise<T> {
    let task: ErTaskStatus | null = null;

    try {
        const res = await apiJson<T & QueuedBody>(url, init);

        if (!res.queued || !res.task) {
            return res as T;
        }

        task = res.task;
    } catch (e) {
        const open =
            e instanceof ApiError && e.status === 409
                ? ((e.body.task as ErTaskStatus | null | undefined) ?? null)
                : null;

        if (!open || open.finished) {
            throw e;
        }

        task = open;
    }

    const started = Date.now();

    while (!task.finished) {
        onWaiting?.(task);

        if (Date.now() - started > MAX_WAIT_MS) {
            throw new ApiError(
                'La tarea sigue en proceso en segundo plano. Recarga la página en unos minutos para ver el resultado.',
                [],
                0,
            );
        }

        await sleep(POLL_MS);
        task = (
            await apiJson<{ task: ErTaskStatus }>(`${tasksBase}/${task.id}`)
        ).task;
    }

    return settle<T>(task);
}

/**
 * Documento que muestra el visor integrado (OkrDocumentViewerDialog), ya sea
 * una carta compromiso o un apercibimiento (Fase 5, 19-oct-2026).
 *
 * - kind: tipo de documento ('letter' = carta compromiso, 'warning' = apercibimiento).
 * - signed: si es la versión firmada (escaneada) en lugar de la generada.
 * - folio: folio del documento.
 * - status: estado del documento (texto que manda el servidor).
 * - collaborator: nombre del colaborador al que pertenece.
 * - date: fecha del documento.
 * - detail: texto adicional (motivo, nivel, etc.).
 * - view_url / download_url: rutas para verlo en línea y descargarlo.
 * - file_available: false cuando el archivo ya no está disponible.
 */
export type OkrViewerDocument = {
    kind: 'letter' | 'warning';
    signed?: boolean;
    folio: string;
    status?: string | null;
    collaborator?: string | null;
    date?: string | null;
    detail?: string | null;
    view_url: string;
    download_url: string;
    file_available?: boolean;
};

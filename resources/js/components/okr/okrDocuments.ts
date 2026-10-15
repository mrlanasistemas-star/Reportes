// Documento que muestra el visor integrado (Fase 5, 19-oct-2026).
export type OkrViewerDocument = {
    kind: 'letter' | 'warning'
    signed?: boolean
    folio: string
    status?: string | null
    collaborator?: string | null
    date?: string | null
    detail?: string | null
    view_url: string
    download_url: string
    file_available?: boolean
}

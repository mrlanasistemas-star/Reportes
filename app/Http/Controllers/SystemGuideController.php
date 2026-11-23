<?php

namespace App\Http\Controllers;

use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guía del sistema: página de ayuda para usuarios, marca de "bienvenida vista"
 * del recorrido guiado y descarga de la guía en PDF.
 */
class SystemGuideController extends Controller
{
    /**
     * Muestra la página Inertia de la guía del sistema.
     *
     * @return Response Página `SystemGuide/Index`.
     */
    public function index(): Response
    {
        return Inertia::render('SystemGuide/Index');
    }

    /**
     * Marca como vista la bienvenida al recorrido guiado.
     *
     * La bienvenida al recorrido se muestra UNA vez por usuario (16-oct-2026).
     * Escribe `users.tour_welcome_seen_at` solo si aún estaba vacío (idempotente).
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return JsonResponse {seen: true}.
     */
    public function markWelcomeSeen(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->tour_welcome_seen_at === null) {
            $user->forceFill(['tour_welcome_seen_at' => now()])->save();
        }

        return response()->json(['seen' => true]);
    }

    /**
     * Genera y descarga la guía del sistema en PDF.
     *
     * Renderiza la vista Blade `reports.system-guide-pdf` con Browsershot
     * (Chrome headless) en cada petición y sobrescribe
     * storage/app/guias/guia-del-sistema.pdf; luego lo envía como descarga.
     *
     * @param  BrowsershotPdfRenderer  $renderer  Servicio que convierte HTML a PDF.
     * @return StreamedResponse Descarga "Guia-del-sistema.pdf".
     */
    public function pdf(BrowsershotPdfRenderer $renderer): StreamedResponse
    {
        $directory = storage_path('app/guias');
        $path = $directory.'/guia-del-sistema.pdf';

        $renderer->renderViewToFile('reports.system-guide-pdf', [], $path, [
            'margins' => ['top' => 20, 'right' => 16, 'bottom' => 22, 'left' => 16],
            'footer_left' => 'MR LANA · Guía del sistema',
        ]);

        return response()->streamDownload(function () use ($path) {
            echo File::get($path);
        }, 'Guia-del-sistema.pdf', ['Content-Type' => 'application/pdf']);
    }
}

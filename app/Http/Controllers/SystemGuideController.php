<?php

namespace App\Http\Controllers;

use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemGuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SystemGuide/Index');
    }

    /** La bienvenida al recorrido se muestra UNA vez por usuario (16-oct-2026). */
    public function markWelcomeSeen(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->tour_welcome_seen_at === null) {
            $user->forceFill(['tour_welcome_seen_at' => now()])->save();
        }

        return response()->json(['seen' => true]);
    }

    public function pdf(BrowsershotPdfRenderer $renderer): StreamedResponse
    {
        $directory = storage_path('app/guias');
        $path = $directory . '/guia-del-sistema.pdf';

        $renderer->renderViewToFile('reports.system-guide-pdf', [], $path, [
            'margins'     => ['top' => 20, 'right' => 16, 'bottom' => 22, 'left' => 16],
            'footer_left' => 'MR LANA · Guía del sistema',
        ]);

        return response()->streamDownload(function () use ($path) {
            echo File::get($path);
        }, 'Guia-del-sistema.pdf', ['Content-Type' => 'application/pdf']);
    }
}

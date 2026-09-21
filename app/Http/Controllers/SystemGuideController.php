<?php

namespace App\Http\Controllers;

use App\Services\Pdf\BrowsershotPdfRenderer;
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

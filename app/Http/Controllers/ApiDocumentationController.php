<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documentación de la API /api/v1 (14-oct-2026) — página privada por enlace
 * directo: solo administradores, sin entrada en el sidebar ni en el sitemap,
 * con noindex. Fuente de verdad: docs/api-reporteria.md y docs/api-openapi.yaml.
 */
class ApiDocumentationController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Solo un administrador puede ver la documentación de la API.');

        return view('docs.api', [
            'baseUrl' => rtrim(config('app.url'), '/') . '/api/v1',
        ]);
    }
}

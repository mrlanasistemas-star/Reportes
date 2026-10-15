<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Almacenamiento AISLADO para toda la suite (15-oct-2026): ninguna prueba escribe,
     * sobrescribe ni purga archivos de storage/ real (exportaciones, auditorías,
     * originales cargados o espacios temporales de Efectividad). storage_path() y los
     * discos apuntan a storage/framework/testing/sandbox (ignorado por git).
     */
    public function createApplication()
    {
        $sandbox = dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'sandbox';
        foreach (['app/private', 'app/public/reports', 'app/public/report_uploads', 'app/radiografias', 'app/tmp', 'app/audits',
            'framework/cache/data', 'framework/sessions', 'framework/views', 'framework/testing', 'logs'] as $dir) {
            $path = $sandbox.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir);
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        $_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $sandbox;

        // Controladores como DashboardController llaman set_time_limit(120/180) para
        // las peticiones web; en la suite todo corre en UN proceso CLI y ese límite
        // se heredaba a las pruebas siguientes (Fatal "Maximum execution time of 120
        // seconds exceeded" en exportaciones largas). Cada prueba parte sin límite.
        @set_time_limit(0);

        return parent::createApplication();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

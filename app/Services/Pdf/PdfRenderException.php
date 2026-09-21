<?php

namespace App\Services\Pdf;

use RuntimeException;
use Throwable;

/**
 * Errores del motor central de PDF (BrowsershotPdfRenderer) — códigos rastreables
 * (sección 18 del pedido de migración a Browsershot) en vez de un genérico
 * "No se pudo generar." GenerateRadiographyJob/los controladores leen ->code()
 * para clasificar el fallo sin adivinar a partir de texto libre; ->getMessage()
 * ya es un texto amigable para el usuario final.
 */
class PdfRenderException extends RuntimeException
{
    public const NODE_NOT_FOUND = 'PDF_RENDER_NODE_NOT_FOUND';
    public const CHROME_NOT_FOUND = 'PDF_RENDER_CHROME_NOT_FOUND';
    public const TIMEOUT = 'PDF_RENDER_TIMEOUT';
    public const CHART_TIMEOUT = 'PDF_RENDER_CHART_TIMEOUT';
    public const HTML_FAILED = 'PDF_RENDER_HTML_FAILED';

    public function __construct(
        private readonly string $renderCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function code(): string
    {
        return $this->renderCode;
    }

    public static function nodeNotFound(?Throwable $previous = null): self
    {
        return new self(
            self::NODE_NOT_FOUND,
            'No se encontró el binario de Node.js necesario para generar el PDF. Verifica BROWSERSHOT_NODE_BINARY.',
            $previous,
        );
    }

    public static function chromeNotFound(?Throwable $previous = null): self
    {
        return new self(
            self::CHROME_NOT_FOUND,
            'No se encontró Chrome/Chromium necesario para generar el PDF. Verifica BROWSERSHOT_CHROME_PATH.',
            $previous,
        );
    }

    public static function timeout(?Throwable $previous = null): self
    {
        return new self(
            self::TIMEOUT,
            'La generación del PDF tardó demasiado y fue cancelada. Inténtalo nuevamente.',
            $previous,
        );
    }

    public static function chartTimeout(?Throwable $previous = null): self
    {
        return new self(
            self::CHART_TIMEOUT,
            'Las gráficas del PDF tardaron demasiado en renderizarse y la generación fue cancelada. Inténtalo nuevamente.',
            $previous,
        );
    }

    public static function htmlFailed(?Throwable $previous = null): self
    {
        return new self(
            self::HTML_FAILED,
            'No se pudo renderizar el contenido del PDF. Revisa el log técnico para más detalle.',
            $previous,
        );
    }
}

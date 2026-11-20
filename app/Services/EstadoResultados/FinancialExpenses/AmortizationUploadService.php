<?php

namespace App\Services\EstadoResultados\FinancialExpenses;

use App\Models\EstadoResultados\EstadoResultadosAmortizationRow;
use App\Models\EstadoResultados\EstadoResultadosAmortizationUpload;
use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Carga (y sustitución) de la tabla de amortización de un crédito.
 *
 * Validación del archivo con el mismo patrón que EstadoResultadosUploadService::validateFile
 * (extensión, tamaño, firma binaria) + SpreadsheetSafetyInspector dentro del parser. Una tabla
 * con errores se rechaza COMPLETA (no hay tablas a medias). Sustituir = la carga vigente del
 * crédito pasa a «sustituida» y sus renglones dejan de usarse; queda en la bitácora.
 *
 * La tabla es del CRÉDITO (abarca muchos periodos): cargarla no escribe en ningún periodo.
 * Los periodos aprobados siguen sirviendo su snapshot congelado (PeriodLockGuard / versión);
 * la firma de insumos (ApprovalInputSignature) incluye la tabla para que un cambio se note.
 */
class AmortizationUploadService
{
    public const EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'csv'];

    public const DISK = 'local';

    public function __construct(private readonly AmortizationScheduleParser $parser) {}

    /** @return array{upload: EstadoResultadosAmortizationUpload, idempotent: bool, replaced: ?EstadoResultadosAmortizationUpload} */
    public function upload(EstadoResultadosCredit $credit, UploadedFile $file, ?int $userId, ?string $notes = null): array
    {
        return $this->importPath($credit, (string) $file->getRealPath(), $file->getClientOriginalName(), $userId, $notes, $file->getSize() ?: null);
    }

    /** @return array{upload: EstadoResultadosAmortizationUpload, idempotent: bool, replaced: ?EstadoResultadosAmortizationUpload} */
    public function importPath(EstadoResultadosCredit $credit, string $absolutePath, string $originalName, ?int $userId, ?string $notes = null, ?int $reportedSize = null): array
    {
        $this->validateFile($absolutePath, $originalName, $reportedSize);
        $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $hash = hash_file('sha256', $absolutePath) ?: '';

        $current = $credit->currentUpload()->first();
        if ($current && $current->file_hash === $hash) {
            return ['upload' => $current, 'idempotent' => true, 'replaced' => null];
        }

        $parsed = $this->parser->parse($absolutePath, $ext);

        $storedPath = 'estado-resultados/amortizacion/'.$credit->key.'/'.$hash.'.'.$ext;
        Storage::disk(self::DISK)->put($storedPath, (string) file_get_contents($absolutePath));

        $rows = $parsed['rows'];
        $dates = array_column($rows, 'payment_date');
        sort($dates);

        $upload = DB::transaction(function () use ($credit, $originalName, $hash, $absolutePath, $storedPath, $rows, $dates, $parsed, $userId, $notes, $reportedSize) {
            // Bloquea el crédito: dos sustituciones simultáneas no dejan dos tablas vigentes.
            EstadoResultadosCredit::query()->whereKey($credit->id)->lockForUpdate()->first();
            $previous = EstadoResultadosAmortizationUpload::query()
                ->where('credit_id', $credit->id)->where('status', EstadoResultadosAmortizationUpload::VIGENTE)
                ->lockForUpdate()->get();

            $upload = EstadoResultadosAmortizationUpload::query()->create([
                'credit_id' => $credit->id,
                'original_name' => mb_substr($originalName, 0, 250),
                'file_hash' => $hash,
                'file_size' => max((int) $reportedSize, (int) @filesize($absolutePath)),
                'stored_path' => $storedPath,
                'status' => EstadoResultadosAmortizationUpload::VIGENTE,
                'rows_count' => count($rows),
                'first_payment_date' => $dates[0] ?? null,
                'last_payment_date' => $dates[count($dates) - 1] ?? null,
                'total_payment' => Money::str2(Money::sum(array_column($rows, 'payment_total'))),
                'total_principal' => Money::str2(Money::sum(array_column($rows, 'principal'))),
                'total_interest' => Money::str2(Money::sum(array_column($rows, 'interest'))),
                'total_interest_vat' => Money::str2(Money::sum(array_map(fn ($r) => $r['interest_vat'] ?? 0, $rows))),
                'column_map' => ['sheet' => $parsed['sheet'], 'header_row' => $parsed['header_row'], 'columns' => $parsed['columns']],
                'warnings' => $parsed['warnings'],
                'notes' => $notes,
                'uploaded_by' => $userId,
            ]);

            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                EstadoResultadosAmortizationRow::query()->insert(array_map(fn ($r) => [
                    'credit_id' => $credit->id,
                    'upload_id' => $upload->id,
                    'payment_number' => $r['payment_number'],
                    'payment_date' => $r['payment_date'],
                    'payment_total' => $r['payment_total'],
                    'principal' => $r['principal'],
                    'interest' => $r['interest'],
                    'interest_vat' => $r['interest_vat'],
                    'balance' => $r['balance'],
                    'origin' => EstadoResultadosAmortizationRow::ORIGEN_ARCHIVO,
                    'source_row' => $r['source_row'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            foreach ($previous as $p) {
                $p->update(['status' => EstadoResultadosAmortizationUpload::SUSTITUIDA, 'superseded_at' => $now, 'superseded_by_upload_id' => $upload->id]);
            }

            return [$upload, $previous->first()];
        });

        return ['upload' => $upload[0], 'idempotent' => false, 'replaced' => $upload[1]];
    }

    /** Mismo patrón que EstadoResultadosUploadService::validateFile, con CSV admitido. */
    public function validateFile(string $absolutePath, string $originalName, ?int $reportedSize = null): void
    {
        $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new EstadoResultadosImportException(
                'Formato no permitido'.($ext !== '' ? ' (.'.$ext.')' : '').'. Sube la tabla de amortización en Excel (.xlsx, .xls) o CSV.'
            );
        }
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new EstadoResultadosImportException('No se recibió el archivo completo. Vuelve a intentarlo.');
        }
        $size = max((int) $reportedSize, (int) filesize($absolutePath));
        if ($size === 0) {
            throw new EstadoResultadosImportException('El archivo está vacío.');
        }
        if ($size > EstadoResultadosUploadService::maxBytes()) {
            throw new EstadoResultadosImportException(
                'El archivo pesa '.number_format($size / 1048576, 1).' MB y excede el máximo permitido de '.rtrim(rtrim(number_format(EstadoResultadosUploadService::maxMb(), 1), '0'), '.').' MB.',
                ['Una tabla de amortización pesa normalmente unos cuantos KB. Verifica que sea el archivo correcto.'],
            );
        }
        $head = (string) file_get_contents($absolutePath, false, null, 0, 8);
        $isZip = str_starts_with($head, "PK\x03\x04");
        $isOle = $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        if ($ext === 'csv') {
            $sample = (string) file_get_contents($absolutePath, false, null, 0, 4096);
            if ($isZip || $isOle || str_contains($sample, "\0")) {
                throw new EstadoResultadosImportException('El archivo .csv no es texto (parece un Excel renombrado). Súbelo con su extensión real.');
            }

            return;
        }
        if (($ext === 'xls' && ! $isOle && ! $isZip) || ($ext !== 'xls' && ! $isZip)) {
            throw new EstadoResultadosImportException(
                'El contenido del archivo no corresponde a un Excel .'.$ext.' (archivo renombrado o dañado).',
                ['Ábrelo en Excel y guárdalo de nuevo como "Libro de Excel (.xlsx)".'],
            );
        }
    }
}

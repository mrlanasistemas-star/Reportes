<?php

namespace App\Services\Imports;

use App\Models\LendusEmployeeDirectory;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importador del directorio de empleados de Lendus (fuente `lendus_empleados`).
 *
 * Lee la hoja "Employees" del Excel exportado por Lendus (o la primera hoja si no
 * existe), localiza el renglón de encabezados (debe contener nombre y código),
 * y guarda cada persona en `lendus_employee_directories` con su puesto, estatus,
 * fecha de inicio, si su rol es operativo y la sucursal inferida a partir del
 * prefijo de su CÓDIGO (BranchResolverService). Ese directorio se usa después
 * para resolver identidad y sucursal de gestores/promotores.
 * Lo invoca ReportAnalysisService al procesar un upload de esta fuente.
 * Reimportar el mismo upload es idempotente: primero borra sus filas previas.
 */
class LendusEmployeeDirectoryImportService
{
    // Roles operativos que se consideran para resolución de sucursal
    private const OPERATIONAL_KEYWORDS = [
        'coordinador regional', 'coordinador',
        'gerente regional', 'gerente', 'subgerente',
        'gestor de credito', 'gestor', 'promotor',
    ];

    // Roles administrativos que se ignoran para resolución
    private const NON_OPERATIONAL_KEYWORDS = [
        'analista', 'mesa de control', 'sistemas', 'administrativo',
        'contabilidad', 'recursos humanos', 'rh', 'director',
    ];

    // Columnas esperadas en la hoja Employees (alias aceptados)
    private const COLUMN_ALIASES = [
        'codigo' => ['codigo', 'code', 'clave'],
        'nombre' => ['nombre', 'name', 'empleado'],
        'telefono' => ['telefono', 'teléfono', 'tel'],
        'puesto' => ['puesto', 'cargo', 'position'],
        'area' => ['area', 'área', 'department'],
        'promotor' => ['promotor', 'promoter'],
        'distribuidor' => ['distribuidor', 'distributor'],
        'estatus' => ['estatus', 'status', 'estado'],
        'fecha_inicio' => ['fecha inicio', 'fecha_inicio', 'start date', 'fecha de ingreso'],
    ];

    /**
     * @param  BranchResolverService  $branchResolver  Resuelve el nombre de sucursal a partir del código del empleado.
     */
    public function __construct(
        private readonly BranchResolverService $branchResolver,
    ) {}

    /**
     * Importa el archivo del upload.
     * Efectos: borra e inserta filas de lendus_employee_directories del upload;
     * consulta `branches` para convertir el nombre inferido en branch_id (con caché
     * en memoria por código). Las filas con error se cuentan y se omiten.
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional (filas leídas, total desconocido) para reportar avance.
     * @return array{rows_read:int,rows_inserted:int,rows_skipped:int,rows_errors:int} Estadísticas de la importación.
     *
     * @throws \RuntimeException Si falta el archivo, no tiene hojas o la hoja está vacía.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo de empleados Lendus no tiene stored_path.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de empleados Lendus no existe en storage/public.');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $absolutePath = ReportUploadStorage::path($upload->stored_path);

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        $sheetsInfo = $reader->listWorksheetInfo($absolutePath);
        if (empty($sheetsInfo)) {
            throw new \RuntimeException('No se encontraron hojas en el archivo de empleados Lendus.');
        }

        // Busca la hoja "Employees" sin distinguir mayúsculas
        $targetSheetName = null;
        foreach ($sheetsInfo as $info) {
            if (strtolower(trim($info['worksheetName'])) === 'employees') {
                $targetSheetName = $info['worksheetName'];
                break;
            }
        }

        // Si no existe "Employees", usa la primera hoja
        if (! $targetSheetName) {
            $targetSheetName = $sheetsInfo[0]['worksheetName'];
        }

        $reader->setLoadSheetsOnly([$targetSheetName]);
        $spreadsheet = $reader->load($absolutePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $rows = $worksheet->toArray(null, true, true, false);
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && $this->hasContent($r)));

        if (empty($rows)) {
            throw new \RuntimeException('La hoja Employees está vacía o no se pudo leer.');
        }

        // El archivo puede traer renglones de título arriba de los encabezados reales.
        // Se exigen AMBOS 'nombre' y 'codigo' para que renglones de título
        // como "REPORTE DE EMPLEADOS" (que coinciden parcialmente con alias) se salten.
        $headerIndex = 0;
        foreach ($rows as $i => $row) {
            $map = $this->buildColumnMap($row);
            if (isset($map['nombre']) && isset($map['codigo'])) {
                $headerIndex = $i;
                break;
            }
        }

        $headerRow = $rows[$headerIndex];
        $map = $this->buildColumnMap($headerRow);

        // Elimina filas previas de este upload (reimportación idempotente)
        LendusEmployeeDirectory::query()->where('report_upload_id', $upload->id)->delete();

        $stats = ['rows_read' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0, 'rows_errors' => 0];

        $branchCache = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $stats['rows_skipped']++;

                continue;
            }

            $stats['rows_read']++;

            try {
                $nombre = $this->clean($this->col($row, $map, 'nombre'));
                if (! $nombre) {
                    $stats['rows_skipped']++;

                    continue;
                }

                $codigo = $this->clean($this->col($row, $map, 'codigo')) ?? '';
                $puesto = $this->clean($this->col($row, $map, 'puesto')) ?? '';
                $area = $this->clean($this->col($row, $map, 'area')) ?? '';
                $promotor = $this->clean($this->col($row, $map, 'promotor')) ?? '';
                $distribuidor = $this->clean($this->col($row, $map, 'distribuidor')) ?? '';
                $estatus = $this->clean($this->col($row, $map, 'estatus')) ?? '';
                $fechaRaw = $this->col($row, $map, 'fecha_inicio');

                $fechaInicio = $this->parseDate($fechaRaw);
                $isOperational = $this->isOperational($puesto);

                // Infiere la sucursal a partir del prefijo del CÓDIGO
                $inferredBranchId = null;
                $inferredBranchName = null;
                if ($codigo) {
                    if (isset($branchCache[$codigo])) {
                        [$inferredBranchId, $inferredBranchName] = $branchCache[$codigo];
                    } else {
                        $branchName = $this->branchResolver->resolveBranchNameFromCode($codigo);
                        if ($branchName) {
                            $branchId = DB::table('branches')
                                ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])
                                ->value('id');
                            $inferredBranchId = $branchId ? (int) $branchId : null;
                            $inferredBranchName = $branchName;
                        }
                        $branchCache[$codigo] = [$inferredBranchId, $inferredBranchName];
                    }
                }

                LendusEmployeeDirectory::create([
                    'report_upload_id' => $upload->id,
                    'codigo' => $codigo ?: null,
                    'nombre' => $nombre,
                    'normalized_name' => $this->normalizeName($nombre),
                    'puesto' => $puesto ?: null,
                    'area' => $area ?: null,
                    'promotor' => $promotor ?: null,
                    'distribuidor' => $distribuidor ?: null,
                    'estatus' => $estatus ?: null,
                    'fecha_inicio' => $fechaInicio,
                    'inferred_branch_id' => $inferredBranchId,
                    'inferred_branch_name' => $inferredBranchName,
                    'is_operational' => $isOperational,
                    'raw_payload' => $row,
                ]);

                $stats['rows_inserted']++;

                if ($progress) {
                    $progress($stats['rows_read'], null);
                }
            } catch (\Throwable $e) {
                $stats['rows_errors']++;
            }
        }

        return $stats;
    }

    /**
     * Clasifica el puesto: primero descarta roles administrativos (analista, sistemas,
     * RH, director...); luego acepta roles operativos (coordinador, gerente,
     * subgerente, gestor, promotor). Cualquier otro puesto se considera no operativo.
     *
     * @param  string  $puesto  Puesto tal como viene en Lendus.
     * @return bool true si el rol participa en la resolución de sucursal.
     */
    private function isOperational(string $puesto): bool
    {
        $lower = mb_strtolower(trim($puesto));
        foreach (self::NON_OPERATIONAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return false;
            }
        }
        foreach (self::OPERATIONAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza el nombre (ASCII, minúsculas, sin signos, espacios simples) para cruces por nombre.
     *
     * @param  string  $value  Nombre original.
     * @return string Nombre normalizado.
     */
    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    /**
     * Mapea cada campo esperado al índice de columna, comparando el encabezado
     * (minúsculas) contra los alias por igualdad o contención; gana la primera columna.
     *
     * @param  array  $header  Celdas del renglón de encabezados.
     * @return array<string,int> Campo => índice de columna.
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $cell) {
            $clean = mb_strtolower(trim((string) $cell));
            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if ($clean === $alias || str_contains($clean, $alias)) {
                        if (! isset($map[$field])) {
                            $map[$field] = $i;
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  array  $row  Fila de datos.
     * @param  array  $map  Mapa campo => índice.
     * @param  string  $field  Campo lógico buscado.
     * @return mixed Valor de la celda o null si la columna no existe.
     */
    private function col(array $row, array $map, string $field): mixed
    {
        if (! isset($map[$field])) {
            return null;
        }

        return $row[$map[$field]] ?? null;
    }

    /**
     * @param  mixed  $value  Valor de celda.
     * @return string|null Texto recortado, o null si queda vacío.
     */
    private function clean(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }

    /**
     * Convierte una fecha de Excel (número de serie) o texto a 'Y-m-d'.
     *
     * @param  mixed  $value  Valor de celda.
     * @return string|null Fecha ISO o null si no se puede interpretar.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array  $row  Fila leída.
     * @return bool true si al menos una celda no es null ni ''.
     */
    private function hasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array  $row  Fila leída.
     * @return bool true si la fila no tiene contenido.
     */
    private function isEmptyRow(array $row): bool
    {
        return ! $this->hasContent($row);
    }
}

<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ExpenseCategoryMapper;
use App\Support\ReportUploadStorage;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Importador del "Reporte de gastos" de Lendus en PDF (fuente `gastos_lendus`).
 * Es la fuente AUTORIZADA para OPEX y "Nómina y Capital Humano" por sucursal.
 *
 * El PDF agrupa los gastos por secciones de sucursal (encabezado en mayúsculas)
 * y cada renglón trae fecha, empleado, monto, concepto, estatus y categoría.
 * Flujo: extrae el texto de cada página, salta encabezados/pies/totales, detecta
 * la sucursal vigente por los encabezados de sección y parsea cada línea con "$".
 * Reglas de negocio:
 * - Se omiten renglones Cancelados y montos en cero.
 * - amount = paid_amount = Monto Gasto (en este PDF el monto pagado por empleado siempre es $0).
 * - "Excedente/Depósito a corporativo" → categoría "Envío de utilidad a corporativo";
 *   "Fondeo/Préstamo intersucursal" → "Préstamos Intersucursales" (con sucursal destino
 *   detectada en raw_payload). El resto se clasifica con ExpenseCategoryMapper.
 * Lo invoca ReportAnalysisService al procesar un upload de esta fuente.
 */
class GastosLendusPdfImportService
{
    /**
     * Nombres de sucursal tal como aparecen en los encabezados de sección del PDF.
     * Se usan como coincidencia directa; el respaldo por regex acepta cualquier otra
     * línea de una sola palabra en mayúsculas (ver isBranchHeader()).
     */
    private const KNOWN_BRANCHES = [
        'ATLIXCO', 'CUERNAVACA', 'CORDOBA', 'HUAMANTLA',
        'SAN JUAN DEL RIO', 'SAN JUAN DEL RÍO', 'MIACATLAN', 'CHIHUAHUA',
        'DURANGO', 'ORIZABA', 'ATLACOMULCO', 'IXTLAHUACA', 'TLAXCALA',
        'TENANGO', 'TENANGO DEL VALLE', 'TULA', 'SAN LUIS POTOSI',
        'YAUTEPEC', 'SAN LUIS SUBGERENCIA', 'CORPORATIVO',
    ];

    /**
     * Categorías de gasto conocidas — se usan como ancla en la regex de cada renglón.
     * Si el PDF agrega categorías nuevas DEBEN agregarse aquí (si no, esos renglones se omiten).
     */
    private const CATEGORIES_PATTERN = 'PAGOS Y SERVICIOS|PROTOCOLARES|EMERGENTES|DEDUCCIONES';

    /**
     * @param  BranchResolverService  $branchResolver  Resuelve rutas a sucursal oficial y crea sucursales conocidas.
     */
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    /**
     * Importa el PDF del upload a fact_expenses.
     * Efectos: borra e inserta filas de fact_expenses del upload; puede crear
     * sucursales; registra warning si un encabezado de sucursal no se reconoce.
     * Usa cachés en memoria de sucursal y empleado por nombre.
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional con estadísticas parciales cada 100 filas.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, errores) y log.
     *
     * @throws \RuntimeException Si el upload no tiene archivo o el archivo no existe.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);

        $parser = new Parser;
        $pdf = $parser->parseFile($absolutePath);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $currentBranch = null;
        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsErrors = 0;
        $branchCache = [];
        $employeeCache = [];

        foreach ($pdf->getPages() as $page) {
            $text = $page->getText();
            $lines = explode("\n", $text);

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);
                if ($line === '') {
                    continue;
                }

                // Omite encabezados de página/columnas y pies de página
                if ($this->isSkipLine($line)) {
                    continue;
                }

                // Las líneas TOTAL: son solo de referencia — se omiten
                if (str_starts_with($line, 'TOTAL:')) {
                    continue;
                }

                // Líneas sin $ → posible encabezado de sección de sucursal
                if (! str_contains($line, '$')) {
                    $candidate = mb_strtoupper(trim($line));
                    if ($this->isBranchHeader($candidate)) {
                        $currentBranch = $candidate;
                    }

                    continue;
                }

                // Líneas con $ → se intentan parsear como renglón de gasto
                $parsed = $this->parseDataLine($line);
                if (! $parsed) {
                    $rowsSkipped++;

                    continue;
                }

                $rowsRead++;

                // Omite renglones cancelados
                if (strcasecmp($parsed['status'], 'Cancelado') === 0) {
                    $rowsSkipped++;

                    continue;
                }

                // Omite renglones con monto cero
                if (! $parsed['amount'] || $parsed['amount'] <= 0.0) {
                    $rowsSkipped++;

                    continue;
                }

                try {
                    $branchName = $currentBranch ?? '';
                    $branch = array_key_exists($branchName, $branchCache)
                        ? $branchCache[$branchName]
                        : ($branchCache[$branchName] = $this->resolveBranch($branchName));

                    $employee = array_key_exists($parsed['employee'], $employeeCache)
                        ? $employeeCache[$parsed['employee']]
                        : ($employeeCache[$parsed['employee']] = $this->resolveEmployee($parsed['employee']));

                    $category = $this->resolveCategory($parsed['category'], $parsed['concept']);

                    $payload = [
                        'concept_raw' => $parsed['concept'],
                        'category_raw' => $parsed['category'],
                        'branch_origen' => $currentBranch,
                    ];
                    if ($category === 'Préstamos Intersucursales' || $category === 'Envío de utilidad a corporativo') {
                        $fondeoInfo = $this->parseFondeoDestino($parsed['concept']);
                        $payload['fondeo_destino_texto'] = $fondeoInfo['texto_raw'];
                        $payload['fondeo_destino_sucursal'] = $fondeoInfo['sucursal'];
                        $payload['fondeo_tipo'] = $fondeoInfo['tipo'];
                    }

                    Expense::query()->create([
                        'period_id' => $upload->period_id,
                        'report_upload_id' => $upload->id,
                        'employee_id' => $employee?->id,
                        'branch_id' => $branch?->id,
                        'category' => $category,
                        'concept' => $parsed['concept'] ?: 'Sin concepto',
                        // amount = Monto Gasto (solicitado)
                        // paid_amount = M pagado empresa = Monto Gasto en renglones Autorizados
                        // (M pagado empleado siempre es $0.00 en este PDF)
                        'amount' => $parsed['amount'],
                        'paid_amount' => $parsed['amount'],
                        'expense_date' => $parsed['date'],
                        'observations' => $parsed['employee'] ?: null,
                        'raw_payload' => $payload,
                    ]);

                    $rowsInserted++;
                } catch (\Throwable) {
                    $rowsErrors++;
                }

                if ($progress && $rowsRead % 100 === 0) {
                    $progress([
                        'rows_read' => $rowsRead,
                        'rows_inserted' => $rowsInserted,
                        'rows_skipped' => $rowsSkipped,
                        'rows_with_errors' => $rowsErrors,
                        'log' => "Gastos Lendus PDF: {$rowsRead} filas leídas…",
                    ]);
                }
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsErrors,
            'log' => sprintf(
                'Gastos Lendus PDF importados. Leídas: %d, insertadas: %d, omitidas: %d, errores: %d.',
                $rowsRead, $rowsInserted, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    /**
     * Parsea un renglón de datos del texto del PDF.
     *
     * smalot/pdfparser extrae cada renglón visual de la tabla con separadores TAB.
     * La estructura real es:
     *
     *   {DD/MM/YYYY}{EMPLEADO}\t${MONTO_GASTO}{CONCEPTO} {ESTATUS}\t${M_PAG_EMP}{FECHAHORA}\t${MONTO_CAJA}${M_PAG_EMP2}{CATEGORÍA}
     *
     * La fecha va primero (sin separador respecto al nombre del empleado) y luego un TAB.
     * Se usan ESTATUS (Autorizado/Cancelado) y CATEGORÍA (al final) como anclas.
     * En renglones Autorizados, Monto Gasto = monto pagado por la empresa.
     *
     * @param  string  $line  Línea de texto con al menos un "$".
     * @return array|null employee, category, concept, status, date (Y-m-d) y amount; null si no coincide con el formato.
     */
    private function parseDataLine(string $line): ?array
    {
        $cats = self::CATEGORIES_PATTERN;
        // {DD/MM/YYYY}{EMPLOYEE}\t${AMOUNT}{CONCEPT} {STATUS}\t...{CATEGORY}
        $pattern = '/^(\d{2}\/\d{2}\/\d{4})(.+?)\t\$\s*([\d,]+\.?\d*)\s*(.+?)\s+(Autorizado|Cancelado)\t.*?('.$cats.')\s*$/u';

        if (! preg_match($pattern, $line, $m)) {
            return null;
        }

        $rawDate = $m[1];
        $employee = trim($m[2]);
        $amount = $this->toDecimal($m[3]);
        $concept = trim($m[4]);
        $status = $m[5];
        $category = trim($m[6]);

        $date = null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $rawDate, $dm)) {
            $date = $dm[3].'-'.$dm[2].'-'.$dm[1];
        }

        return compact('employee', 'category', 'concept', 'status', 'date', 'amount');
    }

    /**
     * Indica si la línea es encabezado de página/columnas o pie de página de Lendus
     * (no contiene datos de gasto).
     *
     * @param  string  $line  Línea de texto recortada.
     * @return bool true si debe ignorarse.
     */
    private function isSkipLine(string $line): bool
    {
        if (str_starts_with($line, 'PRODUCTOS Y SERVICIOS')) {
            return true;
        }
        if (str_starts_with($line, 'REPORTE DE GASTOS')) {
            return true;
        }
        if (str_contains($line, 'Monto Gasto') || str_contains($line, 'M pagado')) {
            return true;
        }
        if (str_contains($line, 'Empleado') && str_contains($line, 'Categoría')) {
            return true;
        }
        // Pie de página: "jueves 07 mayo 2026Lendus 27Pagina 1 de" o "Lendus jueves... Página X de Y"
        if (str_contains($line, 'Lendus') && (str_contains($line, 'Pagina') || str_contains($line, 'Página'))) {
            return true;
        }

        return false;
    }

    /**
     * Indica si una línea sin "$" es el encabezado de una sección de sucursal: nombre
     * exacto de KNOWN_BRANCHES, o una sola palabra en mayúsculas. Las líneas de varias
     * palabras no conocidas se tratan como nombres de persona y no cambian la sucursal.
     *
     * @param  string  $line  Línea en mayúsculas.
     * @return bool true si es encabezado de sucursal.
     */
    private function isBranchHeader(string $line): bool
    {
        if (strlen($line) < 3) {
            return false;
        }

        // Coincidencia exacta con la lista de sucursales conocidas (cubre los nombres de varias palabras
        // como "TENANGO DEL VALLE", "SAN JUAN DEL RIO", "SAN LUIS POTOSI").
        if (in_array($line, self::KNOWN_BRANCHES, true)) {
            return true;
        }

        // Líneas de varias palabras fuera de KNOWN_BRANCHES casi siempre son nombres de empleado
        // (p. ej. "ANA KAREN BERNABE GAMBOA") — NO deben reemplazar currentBranch.
        // Solo se aceptan líneas de una palabra en mayúsculas como posibles sucursales desconocidas.
        if (str_contains($line, ' ')) {
            return false;
        }

        return (bool) preg_match('/^[A-ZÁÉÍÓÚÜÑ]+$/u', $line);
    }

    /**
     * Clasifica el gasto: primero envío de utilidad a corporativo (excedente/depósito),
     * luego fondeo/préstamo intersucursal; en otro caso delega en ExpenseCategoryMapper
     * con concepto + categoría del PDF.
     *
     * @param  string  $category  Categoría tal como viene en el PDF.
     * @param  string  $concept  Concepto del gasto.
     * @return string Categoría normalizada del sistema.
     */
    private function resolveCategory(string $category, string $concept): string
    {
        $cu = mb_strtoupper(trim($concept));

        // Excedente / envío a corporativo debe detectarse ANTES que fondeo para no
        // clasificar "DEPOSITO A CORPORATIVO" como fondeo entre sucursales.
        if (
            str_contains($cu, 'EXCEDENTE') ||
            str_contains($cu, 'DEPOSITO A CORPORATIVO') ||
            str_contains($cu, 'DEPÓSITO A CORPORATIVO') ||
            str_contains($cu, 'DEPOSITO CORPORATIVO') ||
            str_contains($cu, 'ENVIO CORPORATIVO') ||
            str_contains($cu, 'ENVÍO CORPORATIVO')
        ) {
            return 'Envío de utilidad a corporativo';
        }

        if (str_starts_with($cu, 'FONDEO A') || str_contains($cu, 'FONDEO') || str_contains($cu, 'PRESTAMO INTERSUCURSAL')) {
            return 'Préstamos Intersucursales';
        }

        // Usa el mapper con concepto + categoría del PDF para obtener la categoría normalizada
        return ExpenseCategoryMapper::fromFields($concept, $category);
    }

    /**
     * Interpreta la sucursal destino de un fondeo a partir del texto del concepto.
     * Si menciona corporativo/excedente es envío a corporativo; si no, limpia
     * palabras de ruido (FONDEO, A SUC., DEPÓSITO...) y busca el nombre de sucursal.
     *
     * @param  string  $concept  Concepto del gasto.
     * @return array{texto_raw:string,sucursal:string,tipo:string} Texto original, sucursal detectada ('No detectado' si no) y tipo (excedente_corporativo, fondeo_sucursal o no_clasificado).
     */
    private function parseFondeoDestino(string $concept): array
    {
        $cu = mb_strtoupper(trim($concept));
        // Normaliza: quita espacios dobles y acentos
        $cu = strtr($cu, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $cu = preg_replace('/\s+/', ' ', $cu) ?? $cu;

        // Primero se revisa corporativo
        if (
            str_contains($cu, 'CORPORATIVO') || str_contains($cu, 'EXCEDENTE')
        ) {
            return ['texto_raw' => $concept, 'sucursal' => 'CORPORATIVO', 'tipo' => 'excedente_corporativo'];
        }

        // Quita palabras de ruido para extraer el nombre de sucursal
        $noisePatterns = [
            '/^FONDEO\s+(A\s+)?SUC\.?\s+/i',
            '/^FONDEO\s+(A\s+)?SUCURSAL\s+/i',
            '/^FONDEO\s+A\s+/i',
            '/^FONDEO\s+/i',
            '/^A\s+SUC\.?\s+/i',
            '/^A\s+SUCURSAL\s+/i',
            '/^PRESTAMO\s+INTERSUCURSAL\s+/i',
            '/^PRESTAMO\s+A\s+/i',
            '/\s*FONDEO\s*/i',
            '/\s*DEPOSITO\s*/i',
            '/\s*DEPÓSITO\s*/i',
        ];

        $cleaned = $cu;
        foreach ($noisePatterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned) ?? $cleaned;
        }
        $cleaned = trim($cleaned);

        $sucursal = $this->matchBranchFromText($cleaned);

        return [
            'texto_raw' => $concept,
            'sucursal' => $sucursal ?? 'No detectado',
            'tipo' => $sucursal ? 'fondeo_sucursal' : 'no_clasificado',
        ];
    }

    /**
     * Busca, por contención de texto sin acentos, la primera sucursal oficial
     * mencionada (mapa nombre normalizado → nombre oficial con acentos).
     *
     * @param  string  $text  Texto ya limpio del concepto.
     * @return string|null Nombre oficial de la sucursal o null.
     */
    private function matchBranchFromText(string $text): ?string
    {
        // Key = nombre normalizado sin acentos; Value = nombre oficial con acentos
        static $branchMap = [
            'ATLACOMULCO' => 'ATLACOMULCO',
            'ATLIXCO' => 'ATLIXCO',
            'CORDOBA' => 'CÓRDOBA',
            'CUERNAVACA' => 'CUERNAVACA',
            'HUAMANTLA' => 'HUAMANTLA',
            'IXTLAHUACA' => 'IXTLAHUACA',
            'MIACATLAN' => 'MIACATLÁN',
            'ORIZABA' => 'ORIZABA',
            'SAN JUAN DEL RIO' => 'SAN JUAN DEL RÍO',
            'SAN JUAN' => 'SAN JUAN DEL RÍO',
            'SAN LUIS POTOSI' => 'SAN LUIS POTOSÍ',
            'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
            'TENANGO' => 'TENANGO DEL VALLE',
            'TLAXCALA' => 'TLAXCALA',
            'TULA' => 'TULA',
            'CHIHUAHUA' => 'CHIHUAHUA',
            'DURANGO' => 'DURANGO',
            'YAUTEPEC' => 'YAUTEPEC',
        ];

        $textNorm = strtr(mb_strtoupper(trim($text)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        foreach ($branchMap as $key => $official) {
            if (str_contains($textNorm, $key)) {
                return $official;
            }
        }

        return null;
    }

    /**
     * Resuelve la sucursal de la sección: por nombre normalizado; si no existe,
     * convirtiendo la ruta a sucursal oficial; y como último recurso crea la sucursal
     * solo si el encabezado está en KNOWN_BRANCHES (evita sucursales falsas).
     *
     * @param  string  $name  Encabezado de sección del PDF.
     * @return Branch|null Sucursal o null si no se reconoce (se registra warning).
     */
    private function resolveBranch(string $name): ?Branch
    {
        if (empty($name)) {
            return null;
        }

        $nameNorm = $this->normalize($name);

        $branch = Branch::query()
            ->whereRaw('LOWER(normalized_name) = ?', [$nameNorm])
            ->first();

        if ($branch) {
            return $branch;
        }

        // Intentar resolver vía route → nombre oficial
        $officialName = $this->branchResolver->resolveRealBranchFromRoute($name);
        if ($officialName) {
            return $this->branchResolver->findOrCreateBranchByName($officialName);
        }

        // Último recurso: el encabezado de sección del PDF ES el nombre oficial.
        // Solo aplica a nombres en KNOWN_BRANCHES (evitar crear sucursales falsas).
        $nameUp = mb_strtoupper(trim($name));
        if (in_array($nameUp, self::KNOWN_BRANCHES, true)) {
            return $this->branchResolver->findOrCreateBranchByName($name);
        }

        Log::warning('GastosLendusPdfImportService: sucursal no reconocida.', ['raw' => $name]);

        return null;
    }

    /**
     * Busca el empleado por nombre normalizado (coincidencia exacta).
     *
     * @param  string  $name  Nombre del empleado del renglón.
     * @return Employee|null Empleado o null.
     */
    private function resolveEmployee(string $name): ?Employee
    {
        if (empty($name)) {
            return null;
        }

        return Employee::query()
            ->where('normalized_name', $this->normalize($name))
            ->first();
    }

    /**
     * Minúsculas, sin acentos/ñ y espacios simples.
     *
     * @param  string  $value  Texto original.
     * @return string Texto normalizado.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /**
     * Convierte el monto del PDF (con comas) a float con 2 decimales.
     *
     * @param  mixed  $value  Texto del monto.
     * @return float|null Monto o null si no es numérico.
     */
    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        $clean = str_replace([',', ' '], '', (string) $value);

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }
}

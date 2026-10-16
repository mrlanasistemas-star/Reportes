<?php

namespace App\Services\Access;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\CanonicalEmployeeResolver;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importación del DIRECTORIO DE GERENCIAS (Usuarios → Importar usuarios).
 *
 * Lee la hoja "Directorio" del Excel, resuelve cada persona contra las
 * sucursales reales y las cuentas existentes, y arma una VISTA PREVIA con el
 * rol sugerido (siempre GERENCIAL), el alcance por sucursal y las observaciones
 * de conciliación — sin crear nada. La creación real es un segundo paso
 * explícito (confirmar) e IDEMPOTENTE: una cuenta existente (por correo) nunca
 * se recrea ni se le sobrescribe la contraseña o los permisos.
 *
 * Importante: NO se usan los contadores de la hoja (24/23, erróneos). Se leen
 * las filas reales del directorio. Las bandas de sección "▎ SUCURSAL …" y la
 * sección "NOTAS Y OBSERVACIONES" se ignoran.
 */
class GerenciasDirectoryImportService
{
    public const ROLE = User::ROLE_GERENCIAL;

    public const STATUS_CREATE = 'create';        // cuenta nueva, lista para crear
    public const STATUS_EXISTS = 'exists';         // ya existe (por correo) — no se toca
    public const STATUS_REVIEW = 'needs_review';   // crear, pero revisar algo (correo dudoso, sin NOI)
    public const STATUS_ERROR = 'error';           // no se puede crear (sucursal/correo inválido)

    /**
     * Lee y valida el archivo. Devuelve la vista previa completa.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>, sheet_found: bool}
     */
    public function preview(string $absolutePath): array
    {
        $raw = $this->readDirectoryRows($absolutePath);

        $branches = Branch::query()->get(['id', 'name', 'normalized_name']);
        $branchByNorm = $branches->keyBy(fn ($b) => $this->norm($b->name));

        // Índice canónico de colaboradores existentes (por normalized_name) para
        // sugerir vínculo laboral sin crear coincidencias inseguras por nombre.
        $employeeRows = Employee::query()->get(['id', 'full_name', 'normalized_name', 'employee_code', 'position', 'is_active']);
        $canonical = app(CanonicalEmployeeResolver::class)->canonicalize($employeeRows);
        $empByNorm = $canonical->keyBy(fn ($p) => $this->norm($p->full_name));
        $rowsById = $employeeRows->keyBy('id');
        $linkedEmployeeIds = User::query()->whereNotNull('employee_id')->pluck('employee_id')->map(fn ($id) => (int) $id)->flip();

        $existingEmails = User::query()->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [mb_strtolower(trim($email)) => $id]);

        $rows = [];
        $seenEmails = [];
        foreach ($raw as $person) {
            $rows[] = $this->evaluate($person, $branchByNorm, $empByNorm, $rowsById, $linkedEmployeeIds, $existingEmails, $seenEmails);
        }

        $summary = [
            'total' => count($rows),
            'create' => collect($rows)->where('status', self::STATUS_CREATE)->count(),
            'review' => collect($rows)->where('status', self::STATUS_REVIEW)->count(),
            'exists' => collect($rows)->where('status', self::STATUS_EXISTS)->count(),
            'error' => collect($rows)->where('status', self::STATUS_ERROR)->count(),
        ];

        return ['rows' => $rows, 'summary' => $summary, 'sheet_found' => $raw !== null];
    }

    /**
     * Evalúa una fila cruda del directorio contra sucursales y cuentas.
     *
     * @param  array{noi:?string, name:string, branch:string, position:string, email:string}  $person
     */
    private function evaluate(array $person, Collection $branchByNorm, Collection $empByNorm, Collection $rowsById, $linkedEmployeeIds, $existingEmails, array &$seenEmails): array
    {
        $observations = [];
        $emailRaw = trim($person['email']);
        $emailKey = mb_strtolower($emailRaw);
        $branch = $branchByNorm->get($this->norm($person['branch']));
        $noi = trim((string) ($person['noi'] ?? ''));

        // 1) Sucursal
        if (! $branch) {
            $observations[] = "Sucursal «{$person['branch']}» no existe en el sistema.";
        }

        // 2) Correo válido y único dentro del propio archivo
        $emailValid = $emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL) !== false;
        $emailRepeated = $emailValid && isset($seenEmails[$emailKey]);
        if (! $emailValid) {
            $observations[] = 'Correo institucional inválido o vacío.';
        } elseif ($emailRepeated) {
            $observations[] = 'Correo repetido dentro del archivo.';
        }
        if ($emailKey !== '') {
            $seenEmails[$emailKey] = true;
        }

        // 3) ¿Ya existe la cuenta? (idempotencia por correo)
        $existingUserId = $emailValid ? $existingEmails->get($emailKey) : null;

        // 4) Sin NOI → nueva persona; se crea igual (vínculo laboral pendiente).
        if ($noi === '') {
            $observations[] = 'Sin número NOI en el directorio: alta como persona nueva; vínculo laboral pendiente de conciliación.';
        }

        // 5) Vínculo laboral sugerido (identidad canónica por nombre; nunca forzado).
        $match = $empByNorm->get($this->norm($person['name']));
        $employeeId = null;
        if ($match) {
            $alreadyLinked = collect($match->employee_ids)->contains(fn ($id) => $linkedEmployeeIds->has((int) $id));
            if ($alreadyLinked) {
                $observations[] = 'El colaborador con ese nombre ya está vinculado a otra cuenta; se creará sin vínculo laboral.';
            } else {
                $employeeId = (int) $match->id;
                $observations[] = "Coincidencia laboral por nombre (registro #{$employeeId}); verificar antes de confirmar el vínculo.";
            }
        }

        // 6) Correo que, según la nota del directorio, podría no corresponder al nombre.
        $emailMismatch = $emailValid && $this->emailLooksMismatched($person['name'], $emailRaw);
        if ($emailMismatch) {
            $observations[] = 'El correo podría no corresponder al nombre; confirmar antes de enviar credenciales.';
        }

        // Estatus final (prioridad: existente > error > revisar > crear).
        if ($existingUserId) {
            $status = self::STATUS_EXISTS;
            $observations[] = 'Ya existe una cuenta con este correo: no se modifica (ni contraseña ni permisos).';
        } elseif (! $branch || ! $emailValid || $emailRepeated) {
            $status = self::STATUS_ERROR; // falta un dato imprescindible para crear
        } elseif ($noi === '' || $match !== null || $emailMismatch) {
            $status = self::STATUS_REVIEW; // se puede crear, pero hay algo que conciliar
        } else {
            $status = self::STATUS_CREATE;
        }

        return [
            'noi' => $noi !== '' ? $noi : null,
            'name' => $person['name'],
            'branch_name' => $person['branch'],
            'branch_id' => $branch?->id,
            'position' => $person['position'],
            'email' => $emailRaw,
            'role' => self::ROLE,
            'employee_id' => $employeeId,
            'status' => $status,
            'existing_user_id' => $existingUserId ? (int) $existingUserId : null,
            'observations' => $observations,
        ];
    }

    /**
     * Lee las filas de persona de la hoja "Directorio". Devuelve null si no se
     * encuentra la hoja/encabezado.
     *
     * @return array<int, array{noi:?string, name:string, branch:string, position:string, email:string}>|null
     */
    public function readDirectoryRows(string $absolutePath): ?array
    {
        @ini_set('memory_limit', '512M');

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);

        $sheet = null;
        foreach ($spreadsheet->getAllSheets() as $s) {
            if ($this->norm($s->getTitle()) === 'DIRECTORIO') {
                $sheet = $s;
                break;
            }
        }
        $sheet ??= $spreadsheet->getSheet(0);

        $matrix = $sheet->toArray(null, true, true, true); // claves por letra de columna

        // Localiza el encabezado (fila con "NOMBRE DEL COLABORADOR").
        $headerKey = null;
        foreach ($matrix as $key => $cols) {
            foreach ($cols as $v) {
                if ($this->norm((string) $v) === 'NOMBRE DEL COLABORADOR') {
                    $headerKey = $key;
                    break 2;
                }
            }
        }
        if ($headerKey === null) {
            return null;
        }

        $people = [];
        $started = false;
        foreach ($matrix as $key => $cols) {
            if ($key === $headerKey) {
                $started = true;
                continue;
            }
            if (! $started) {
                continue;
            }

            $a = trim((string) ($cols['A'] ?? ''));
            $name = trim((string) ($cols['B'] ?? ''));
            $branch = trim((string) ($cols['C'] ?? ''));
            $position = trim((string) ($cols['D'] ?? ''));
            $email = trim((string) ($cols['E'] ?? ''));

            // Fin del directorio: sección de notas.
            if ($this->norm($a) === 'NOTAS Y OBSERVACIONES' || str_starts_with($this->norm($a), 'NOTAS Y OBSERVACIONES')) {
                break;
            }
            // Banda de sucursal "▎ SUCURSAL …": col A con marca y sin nombre.
            if ($name === '' || str_contains($a, '▎')) {
                continue;
            }
            // Fila válida: necesita al menos nombre + sucursal + puesto.
            if ($branch === '' || $position === '') {
                continue;
            }

            $people[] = [
                'noi' => $a !== '' ? $a : null,
                'name' => $name,
                'branch' => $branch,
                'position' => $position,
                'email' => $email,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $people;
    }

    /** Normaliza texto para comparar: mayúsculas, sin acentos, espacios colapsados. */
    public function norm(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);

        return (string) preg_replace('/\s+/', ' ', $value);
    }

    /**
     * Heurística conservadora: ¿el usuario (parte local) del correo no contiene
     * ningún apellido/nombre del colaborador? Solo para MARCAR revisión, nunca
     * para bloquear. Cubre los casos de la nota del directorio (p. ej.
     * ANGELICA AZUCENA GONZALEZ → angelica.granados@).
     */
    private function emailLooksMismatched(string $name, string $email): bool
    {
        $local = $this->norm(strstr($email, '@', true) ?: $email);
        $local = (string) preg_replace('/[^A-Z]+/', ' ', $local);
        // Fragmentos significativos del correo (nombre.apellido…). Se ignoran
        // trozos de ≤3 letras (ej. iniciales).
        $localTokens = array_filter(explode(' ', $local), fn ($t) => mb_strlen($t) >= 4);
        if ($localTokens === []) {
            return false;
        }
        $nameTokens = array_filter(explode(' ', $this->norm($name)), fn ($t) => mb_strlen($t) >= 3);

        // Se marca revisión si ALGÚN fragmento del correo no corresponde a ningún
        // nombre/apellido del directorio (ej. un apellido "ajeno" como
        // angelica.GRANADOS para ANGELICA AZUCENA GONZALEZ).
        foreach ($localTokens as $lt) {
            $matches = false;
            foreach ($nameTokens as $nt) {
                if ($lt === $nt || str_contains($nt, $lt) || str_contains($lt, $nt)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                return true;
            }
        }

        return false;
    }
}

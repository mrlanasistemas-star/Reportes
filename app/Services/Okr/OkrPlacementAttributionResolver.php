<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Services\Catalog\BranchRouteCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Atribución SEGURA de un movimiento de colocación a un gestor (15-oct-2026,
 * prioridad 4 del cierre OKR).
 *
 * Defecto corregido: OkrPlacementImportService::resolveEmployeeByCode()
 * buscaba el código en `lendus_employee_directory` de CUALQUIER carga y tomaba
 * el primer `normalized_name` que devolviera la BD; luego resolvía por nombre.
 * Un código compartido por varias personas (dato real: HUAM18 → 3 gestores,
 * TLA11 → 2) atribuía el dinero a quien saliera primero, y un código sin
 * trabajador caía en silencio a la coincidencia por nombre.
 *
 * Orden de resolución (cada paso solo si el anterior no da UNA identidad):
 *   1. Número de empleado INEQUÍVOCO en el directorio Lendus vigente para la
 *      FECHA del movimiento (si no aparece en ese directorio, en todo el
 *      histórico, y solo si ahí también es inequívoco).
 *   2. Identidad canónica validada: el nombre del código debe existir como
 *      colaborador en Reportería (CanonicalEmployeeResolver: mismo
 *      normalized_name, id menor del grupo).
 *   3. Código de vendedor vinculado en la cartera Lendus
 *      (fact_portfolios.promoter_code → promoter_name), solo si es inequívoco.
 *   4. Asignación laboral del periodo (asignación de Reportería + nómina NOI).
 *   5. Ruta / sucursal oficial (BranchRouteCatalog): si el archivo NO trae
 *      sucursal y la asignación del periodo ubica a la persona en otra
 *      sucursal, el movimiento queda pendiente.
 *   6. Nombre: SOLO coincidencia exacta y sin contradicción con el paso 4/5;
 *      cualquier ambigüedad deja el movimiento "pendiente de atribución" y
 *      los nombres parecidos solo se ofrecen como sugerencia para conciliar.
 *
 * Un movimiento pendiente NUNCA se suma a ningún gestor, pero sí al total de la
 * sucursal (suma gestores + pendientes = total de la sucursal).
 */
class OkrPlacementAttributionResolver
{
    /** Movimiento atribuido a un gestor. */
    public const STATUS_ATTRIBUTED = 'attributed';

    /** Movimiento pendiente de atribución (solo suma al total de la sucursal). */
    public const STATUS_PENDING = 'pending';

    /**
     * Caché en memoria de las cargas del directorio Lendus con su periodo, ordenadas de la más reciente a la más antigua.
     *
     * @var array<int, array{upload_id:int, label:?string, start:?string, end:?string}>|null
     */
    private ?array $directoryUploads = null;

    /**
     * Caché "código|carga" => nombres normalizados encontrados y ámbito (current/history/portfolio).
     *
     * @var array<string, array>
     */
    private array $codeCache = [];

    /**
     * Caché nombre normalizado => ids de Employee (orden ascendente).
     *
     * @var array<string, int[]>
     */
    private array $employeeIdsByNorm = [];

    /**
     * Caché "empleado|fecha" => sucursales oficiales del periodo y etiqueta del periodo.
     *
     * @var array<string, array{branches:string[], label:?string}>
     */
    private array $periodBranchCache = [];

    /**
     * Caché normalized_name => full_name de todos los empleados (para sugerencias).
     *
     * @var array<int, string>|null
     */
    private ?array $allNames = null;

    /**
     * Atribuye un movimiento de colocación a un gestor siguiendo el orden de la
     * clase. Con número de empleado: identidad por código (directorio de la
     * fecha → histórico → cartera), validación como colaborador en Reportería
     * y, si el archivo no trae sucursal, coherencia con la sucursal asignada en
     * el periodo. Sin código: nombre exacto, sin varios códigos activos y sin
     * contradicción de sucursal (modo estricto). Cualquier duda → pendiente,
     * con sugerencias de nombres parecidos solo para conciliar.
     *
     * Efecto: lecturas de BD con cachés en memoria de la instancia (pensado
     * para procesar un archivo completo con la misma instancia).
     *
     * @param  string|null  $code  Número de empleado del archivo.
     * @param  string|null  $name  Nombre del gestor en el archivo.
     * @param  string|null  $referenceDate  Fecha del movimiento (Y-m-d).
     * @param  string|null  $objectiveBranchName  Sucursal del OKR al que se carga.
     * @param  bool  $rowHasBranch  Si la fila del archivo trae sucursal propia.
     * @return array{employee_id:?int, status:string, method:?string, note:?string, suggestions:string[]}
     */
    public function resolve(?string $code, ?string $name, ?string $referenceDate, ?string $objectiveBranchName, bool $rowHasBranch): array
    {
        $code = $code !== null ? mb_strtoupper(trim($code)) : null;
        $code = $code === '' ? null : $code;
        $normName = $name !== null ? self::normalizeName($name) : null;
        $normName = $normName === '' ? null : $normName;

        if ($code !== null) {
            $byCode = $this->identityFromCode($code, $normName, $referenceDate);
            if ($byCode['norm'] === null) {
                return $this->pending($byCode['note'], $normName);
            }

            $employeeId = $this->canonicalEmployeeId($byCode['norm']);
            if ($employeeId === null) {
                return $this->pending("El número de empleado {$code} corresponde a ".mb_strtoupper($byCode['norm']).', que no está registrado como colaborador en Reportería.', $normName);
            }

            if (! $rowHasBranch && ($mismatch = $this->periodBranchMismatch($employeeId, $referenceDate, $objectiveBranchName))) {
                return $this->pending("El número de empleado {$code} es de ".mb_strtoupper($byCode['norm']).", pero {$mismatch}. El archivo no trae sucursal: confirma a quién corresponde.", null);
            }

            $note = $byCode['note'];
            if ($normName !== null && $normName !== $byCode['norm']) {
                $note = trim(($note ? $note.' ' : '').'El nombre del archivo ('.mb_strtoupper($normName).') no coincide con el del número de empleado; se usó el número de empleado.');
            }

            return ['employee_id' => $employeeId, 'status' => self::STATUS_ATTRIBUTED, 'method' => $byCode['method'], 'note' => $note ?: null, 'suggestions' => []];
        }

        if ($normName === null) {
            return $this->pending('El movimiento no trae número de empleado ni nombre del gestor.', null);
        }

        $employeeId = $this->canonicalEmployeeId($normName);
        if ($employeeId === null) {
            return $this->pending('No hay un colaborador registrado con el nombre exacto '.mb_strtoupper($normName).'.', $normName);
        }

        $codes = $this->activeDirectoryCodesForName($normName, $referenceDate);
        if (count($codes) > 1) {
            return $this->pending('El nombre '.mb_strtoupper($normName).' tiene '.count($codes).' números de empleado activos ('.implode(', ', $codes).'); el archivo debe traer el número de empleado.', null);
        }

        if (($mismatch = $this->periodBranchMismatch($employeeId, $referenceDate, $objectiveBranchName, strict: true)) !== null) {
            return $this->pending('Por nombre coincide con '.mb_strtoupper($normName).", pero {$mismatch}. Confírmalo con su número de empleado.", null);
        }

        return ['employee_id' => $employeeId, 'status' => self::STATUS_ATTRIBUTED, 'method' => 'name_exact', 'note' => null, 'suggestions' => []];
    }

    /**
     * Misma normalización de nombre que Employee.normalized_name (minúsculas, sin acentos, espacios colapsados).
     *
     * @param  string  $value  Nombre original.
     * @return string Nombre normalizado.
     */
    public static function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /**
     * Pasos 1 y 3: identidad (normalized_name) de un número de empleado.
     * Busca el código en el directorio Lendus de la fecha; si no aparece, en
     * todo el histórico; si tampoco, como promoter_code en fact_portfolios.
     * Un código con varias personas solo se resuelve si el nombre exacto del
     * archivo es uno de sus titulares.
     *
     * @param  string  $code  Código en mayúsculas sin espacios extremos.
     * @param  string|null  $normName  Nombre normalizado del archivo (desempate en códigos compartidos).
     * @param  string|null  $referenceDate  Fecha del movimiento.
     * @return array{norm:?string, method:?string, note:?string}
     */
    private function identityFromCode(string $code, ?string $normName, ?string $referenceDate): array
    {
        $directory = $this->directoryUploadFor($referenceDate);
        $key = $code.'|'.($directory['upload_id'] ?? 0);

        if (! isset($this->codeCache[$key])) {
            $namesIn = fn (?int $uploadId) => DB::table('lendus_employee_directory')
                ->whereRaw('UPPER(TRIM(codigo)) = ?', [$code])
                ->when($uploadId !== null, fn ($q) => $q->where('report_upload_id', $uploadId))
                ->whereNotNull('normalized_name')
                ->pluck('normalized_name')
                ->map(fn ($n) => self::normalizeName((string) $n))
                ->unique()->values()->all();

            $current = $directory ? $namesIn($directory['upload_id']) : [];
            $scope = 'current';
            if ($current === []) {
                $current = $namesIn(null);
                $scope = 'history';
            }
            if ($current === []) {
                $current = DB::table('fact_portfolios')
                    ->whereRaw('UPPER(TRIM(promoter_code)) = ?', [$code])
                    ->whereNotNull('promoter_name')
                    ->distinct()
                    ->pluck('promoter_name')
                    ->map(fn ($n) => self::normalizeName((string) $n))
                    ->filter(fn ($n) => $n !== '')
                    ->unique()->values()->all();
                $scope = 'portfolio';
            }
            $this->codeCache[$key] = ['names' => $current, 'scope' => $scope];
        }

        ['names' => $names, 'scope' => $scope] = $this->codeCache[$key];
        $where = match ($scope) {
            'current' => 'en el directorio Lendus '.($directory['label'] ?? 'vigente'),
            'history' => 'en el histórico del directorio Lendus',
            default => 'en la cartera Lendus',
        };

        if ($names === []) {
            return ['norm' => null, 'method' => null, 'note' => "El número de empleado {$code} no corresponde a ningún trabajador registrado (directorio ni cartera Lendus)."];
        }

        $method = match ($scope) {
            'current' => 'employee_code',
            'history' => 'employee_code_history',
            default => 'promoter_code',
        };

        if (count($names) === 1) {
            return ['norm' => $names[0], 'method' => $method, 'note' => $scope === 'history' ? "Número de empleado {$code} validado {$where} (no aparece en el directorio del periodo del movimiento)." : null];
        }

        // Código compartido: el nombre exacto del archivo puede elegir a UNO de
        // los titulares de ese código — nunca a alguien fuera de esa lista.
        if ($normName !== null && in_array($normName, $names, true)) {
            return ['norm' => $normName, 'method' => $method.'+name', 'note' => "El número de empleado {$code} está registrado para ".count($names)." personas {$where}; se identificó por el nombre exacto del archivo."];
        }

        $list = implode(', ', array_map('mb_strtoupper', $names));

        return ['norm' => null, 'method' => null, 'note' => "El número de empleado {$code} está registrado para ".count($names)." personas distintas {$where} ({$list}). No se atribuye a nadie hasta conciliarlo."];
    }

    /**
     * Carga del directorio Lendus vigente para la FECHA del movimiento: la cuyo
     * periodo contiene la fecha; si no, la más reciente anterior; si la fecha
     * es anterior a todo, la última de la lista. Sin fecha: la más reciente.
     *
     * @param  string|null  $date  Fecha (Y-m-d).
     * @return array{upload_id:int, label:?string, start:?string, end:?string}|null Carga elegida, o null si no hay directorio.
     */
    private function directoryUploadFor(?string $date): ?array
    {
        if ($this->directoryUploads === null) {
            $this->directoryUploads = DB::table('lendus_employee_directory as d')
                ->join('report_uploads as u', 'u.id', '=', 'd.report_upload_id')
                ->leftJoin('periods as p', 'p.id', '=', 'u.period_id')
                ->select('d.report_upload_id', DB::raw("COALESCE(NULLIF(p.custom_name, ''), p.name) as name"), 'p.start_date', 'p.end_date')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['upload_id' => (int) $r->report_upload_id, 'label' => $r->name, 'start' => $r->start_date ? substr((string) $r->start_date, 0, 10) : null, 'end' => $r->end_date ? substr((string) $r->end_date, 0, 10) : null])
                ->sortBy([['end', 'desc'], ['upload_id', 'desc']])
                ->values()->all();
        }

        $uploads = $this->directoryUploads;
        if ($uploads === []) {
            return null;
        }
        if ($date === null) {
            return $uploads[0];
        }

        foreach ($uploads as $u) {
            if ($u['start'] !== null && $u['end'] !== null && $u['start'] <= $date && $u['end'] >= $date) {
                return $u;
            }
        }
        foreach ($uploads as $u) { // la más reciente ANTERIOR a la fecha
            if ($u['end'] !== null && $u['end'] < $date) {
                return $u;
            }
        }

        return end($uploads) ?: null; // fecha anterior a todo el histórico: la más antigua
    }

    /**
     * Códigos ACTIVOS distintos asociados a un nombre en el directorio de la
     * fecha (más de uno impide atribuir solo por nombre).
     *
     * @param  string  $normName  Nombre normalizado.
     * @param  string|null  $date  Fecha del movimiento.
     * @return string[] códigos ACTIVOS distintos del nombre en el directorio de la fecha.
     */
    private function activeDirectoryCodesForName(string $normName, ?string $date): array
    {
        $directory = $this->directoryUploadFor($date);
        if ($directory === null) {
            return [];
        }

        return DB::table('lendus_employee_directory')
            ->where('report_upload_id', $directory['upload_id'])
            ->where('normalized_name', $normName)
            ->whereRaw('UPPER(estatus) = ?', ['ACTIVO'])
            ->whereNotNull('codigo')->where('codigo', '!=', '')
            ->pluck('codigo')
            ->map(fn ($c) => mb_strtoupper(trim((string) $c)))
            ->unique()->values()->all();
    }

    /**
     * Paso 2: id canónico (menor id del grupo con el mismo normalized_name).
     *
     * @param  string  $normName  Nombre normalizado.
     * @return int|null Id canónico o null si no existe como colaborador.
     */
    private function canonicalEmployeeId(string $normName): ?int
    {
        if (! array_key_exists($normName, $this->employeeIdsByNorm)) {
            $this->employeeIdsByNorm[$normName] = Employee::query()->where('normalized_name', $normName)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return $this->employeeIdsByNorm[$normName][0] ?? null;
    }

    /**
     * Pasos 4/5: si la asignación laboral del periodo de la fecha ubica a la
     * persona en OTRA sucursal oficial, devuelve la explicación. Null si
     * coincide o si no hay dato del periodo (falta de dato no es contradicción).
     * Con $strict, dos sucursales distintas en el mismo periodo también son
     * contradicción (atribución por nombre).
     *
     * @param  int  $employeeId  Id canónico del colaborador.
     * @param  string|null  $date  Fecha del movimiento.
     * @param  string|null  $objectiveBranchName  Sucursal del OKR (se normaliza a sucursal oficial).
     * @param  bool  $strict  true en atribución por nombre.
     * @return string|null Explicación de la contradicción o null.
     */
    private function periodBranchMismatch(int $employeeId, ?string $date, ?string $objectiveBranchName, bool $strict = false): ?string
    {
        if ($date === null || $objectiveBranchName === null) {
            return null;
        }
        $target = BranchRouteCatalog::normalize(BranchRouteCatalog::officialBranchFor($objectiveBranchName) ?? $objectiveBranchName);
        $info = $this->periodBranches($employeeId, $date);
        if ($info['branches'] === []) {
            return null;
        }
        $period = $info['label'] ? "en {$info['label']}" : 'en el periodo del movimiento';

        if (count($info['branches']) > 1) {
            // Dos sucursales en el mismo periodo: solo bloquea la atribución por nombre.
            return $strict ? "{$period} aparece en ".implode(' y ', $info['branches']).' (información contradictoria)' : null;
        }

        return BranchRouteCatalog::normalize($info['branches'][0]) === $target ? null : "{$period} está asignado a {$info['branches'][0]}";
    }

    /**
     * Sucursales oficiales donde está la persona (todos sus ids canónicos) en
     * el periodo que contiene la fecha y tiene asignación o nómina para ella:
     * employee_branch_assignments + period_employee_rosters activos.
     *
     * @param  int  $employeeId  Id del colaborador.
     * @param  string  $date  Fecha del movimiento.
     * @return array{branches:string[], label:?string} Sucursales y nombre visible del periodo.
     */
    private function periodBranches(int $employeeId, string $date): array
    {
        $key = $employeeId.'|'.$date;
        if (isset($this->periodBranchCache[$key])) {
            return $this->periodBranchCache[$key];
        }

        $norm = Employee::query()->whereKey($employeeId)->value('normalized_name');
        $group = $norm ? $this->employeeIdsByNorm[$norm] ?? Employee::query()->where('normalized_name', $norm)->pluck('id')->map(fn ($v) => (int) $v)->all() : [$employeeId];

        $period = DB::table('periods')->where('start_date', '<=', $date)->where('end_date', '>=', $date)
            ->where(fn ($q) => $q
                ->whereIn('id', DB::table('employee_branch_assignments')->whereIn('employee_id', $group)->select('period_id'))
                ->orWhereIn('id', DB::table('period_employee_rosters')->whereIn('employee_id', $group)->select('period_id')))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first(['id', 'name', 'custom_name']);

        if ($period === null) {
            return $this->periodBranchCache[$key] = ['branches' => [], 'label' => null];
        }

        $names = DB::table('employee_branch_assignments as a')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->where('a.period_id', $period->id)->whereIn('a.employee_id', $group)->pluck('b.name')
            ->merge(DB::table('period_employee_rosters as r')->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')
                ->where('r.period_id', $period->id)->whereIn('r.employee_id', $group)->where('r.is_active_for_period', true)
                ->get(['b.name', 'r.branch_name'])->map(fn ($r) => $r->branch_name ?: $r->name))
            ->filter()
            ->map(fn ($n) => BranchRouteCatalog::officialBranchFor((string) $n) ?? mb_strtoupper(trim((string) $n)))
            ->unique()->values()->all();

        return $this->periodBranchCache[$key] = ['branches' => $names, 'label' => (trim((string) ($period->custom_name ?? '')) !== '' ? $period->custom_name : $period->name)];
    }

    /**
     * Resultado "pendiente de atribución", agregando a la nota nombres parecidos como sugerencia.
     *
     * @param  string  $note  Motivo en español.
     * @param  string|null  $normName  Nombre normalizado para buscar parecidos (null = sin sugerencias).
     * @return array{employee_id:null, status:string, method:null, note:string, suggestions:string[]}
     */
    private function pending(string $note, ?string $normName): array
    {
        $suggestions = $normName !== null ? $this->similarNames($normName) : [];
        if ($suggestions !== []) {
            $note .= ' Posibles coincidencias para conciliar (no se asignan automáticamente): '.implode(', ', $suggestions).'.';
        }

        return ['employee_id' => null, 'status' => self::STATUS_PENDING, 'method' => null, 'note' => $note, 'suggestions' => $suggestions];
    }

    /**
     * Nombres parecidos — SOLO ayuda para conciliar, jamás asignación.
     * Distancia Levenshtein ≤ 3, máximo 3 sugerencias.
     *
     * @param  string  $normName  Nombre normalizado de referencia.
     * @return string[] Nombres completos en mayúsculas.
     */
    private function similarNames(string $normName): array
    {
        $this->allNames ??= Employee::query()->distinct()->pluck('full_name', 'normalized_name')->all();
        $out = [];
        foreach ($this->allNames as $norm => $full) {
            $norm = (string) $norm;
            if ($norm === $normName || $norm === '') {
                continue;
            }
            if (levenshtein(substr($norm, 0, 255), substr($normName, 0, 255)) <= 3) {
                $out[] = mb_strtoupper((string) $full);
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }
}

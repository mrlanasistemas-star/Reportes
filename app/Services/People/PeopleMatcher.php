<?php

namespace App\Services\People;

use App\Services\Catalog\BranchRouteCatalog;
use App\Services\EmployeeNameCanonicalizer;

/**
 * Empata colaboradores de Reportería con la plantilla de People.
 *
 *  1. `clave_noi` de People = clave NOI de Reportería (`employee_code`).
 *  2. Si no, `numero_empleado` de People = `employee_code`.
 *  (en 1 y 2 además deben compartir 2 palabras del nombre).
 *  3. Si no, nombre normalizado (sin acentos ni signos), en el mismo orden o
 *     con las mismas palabras en otro orden («PEREZ LOPEZ JUAN» = «JUAN PEREZ LOPEZ»).
 * Un nombre que corresponde a 2+ personas activas en People NO se empata (nunca
 * se adivina). Solo enriquece la vista: no crea vínculos permanentes.
 */
class PeopleMatcher
{
    /** @var array<string, array> */
    private array $byNoi = [];

    /** @var array<string, array> */
    private array $byNumber = [];

    /** @var array<string, list<array>> */
    private array $byName = [];

    /** @var array<string, list<array>> */
    private array $bySortedName = [];

    public function __construct(private readonly EmployeeNameCanonicalizer $names) {}

    /** @param list<array> $people PeopleRosterClient::roster()['people'] */
    public function load(array $people): static
    {
        $this->byNoi = $this->byNumber = $this->byName = $this->bySortedName = [];

        foreach ($people as $p) {
            $this->index($this->byNoi, $p['noi'] ?? null, $p);
            $this->index($this->byNumber, $p['number'] ?? null, $p);

            $this->byName[$this->names->normalize($p['name'])][] = $p;
            $this->bySortedName[$this->sorted($p['name'])][] = $p;
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->byName === [];
    }

    /**
     * @return array{person:array, via:string}|null
     */
    public function match(?string $code, ?string $name): ?array
    {
        if ($code !== null && trim($code) !== '') {
            $num = ltrim(trim($code), '0') ?: '0';

            foreach ([$this->byNoi, $this->byNumber] as $map) {
                if (isset($map[$num]) && $this->sameish($map[$num]['name'], (string) $name)) {
                    return ['person' => $map[$num], 'via' => 'clave'];
                }
            }
        }

        if ($name === null || trim($name) === '') {
            return null;
        }

        foreach ([$this->byName[$this->names->normalize($name)] ?? [], $this->bySortedName[$this->sorted($name)] ?? []] as $candidates) {
            if ($person = $this->pick($candidates)) {
                return ['person' => $person, 'via' => 'nombre'];
            }
        }

        return null;
    }

    /**
     * Sucursal de People expresada como sucursal oficial de Reportería cuando el
     * catálogo la reconoce («Tula» → «TULA»); si no, el texto de People en mayúsculas.
     */
    public static function officialBranch(?string $peopleBranch): ?string
    {
        if ($peopleBranch === null || trim($peopleBranch) === '') {
            return null;
        }

        return BranchRouteCatalog::officialBranchFor($peopleBranch) ?? mb_strtoupper(trim($peopleBranch), 'UTF-8');
    }

    /** Puesto con formato uniforme («gerente de sucursal» → «GERENTE DE SUCURSAL»). */
    public static function position(?string $position): ?string
    {
        $p = preg_replace('/\s+/u', ' ', trim((string) $position));

        return $p === '' || $p === null ? null : mb_strtoupper($p, 'UTF-8');
    }

    /** Indexa por clave sin ceros a la izquierda; con clave repetida gana el registro activo. */
    private function index(array &$map, ?string $key, array $p): void
    {
        if ($key === null || trim($key) === '') {
            return;
        }

        $k = ltrim(trim($key), '0') ?: '0';

        if (! isset($map[$k]) || ($map[$k]['is_baja'] && ! $p['is_baja'])) {
            $map[$k] = $p;
        }
    }

    /** @param list<array> $candidates */
    private function pick(array $candidates): ?array
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $active = array_values(array_filter($candidates, fn ($p) => ! $p['is_baja']));

        return count($active) === 1 ? $active[0] : null;
    }

    /**
     * La clave NOI se reutiliza entre sistemas: además del número se exige que
     * el nombre comparta al menos 2 palabras (evita empatar a otra persona).
     */
    private function sameish(string $a, string $b): bool
    {
        if (trim($b) === '') {
            return true;
        }

        $wa = array_filter(explode(' ', $this->names->normalize($a)), fn ($w) => mb_strlen($w) > 2);
        $wb = array_filter(explode(' ', $this->names->normalize($b)), fn ($w) => mb_strlen($w) > 2);

        return count(array_intersect($wa, $wb)) >= min(2, count($wa), count($wb));
    }

    private function sorted(string $name): string
    {
        $words = array_filter(explode(' ', $this->names->normalize($name)));
        sort($words);

        return implode(' ', $words);
    }
}

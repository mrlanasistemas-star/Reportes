<?php

namespace App\Services;

/**
 * Centraliza la normalización de nombres de personas y la agrupación aproximada
 * ("fuzzy") de variantes del mismo nombre en una forma canónica.
 *
 * Sirve para que un mismo gestor/colaborador escrito distinto en Lendus, NOI o ERP
 * se cuente como una sola persona. Lo usan RadiographySnapshotBuilder,
 * EmployeeBranchAssignmentController, comandos de depuración y cualquier listado
 * de colaboradores/gestores. No toca BD.
 */
class EmployeeNameCanonicalizer
{
    /**
     * Normaliza un nombre: minúsculas, quita acentos y ñ, cambia caracteres especiales
     * por espacio y colapsa espacios repetidos.
     *
     * @param  string  $name  Nombre tal como viene de la fuente.
     * @return string Nombre normalizado (ej. "Peña Ruíz" → "pena ruiz").
     */
    public function normalize(string $name): string
    {
        $name = trim(mb_strtolower($name));
        $name = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $name,
        );
        $name = preg_replace('/[^a-z0-9\s]/', ' ', $name) ?? $name;

        return preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
    }

    /**
     * Construye el mapa nombreNormalizado → nombreCanónico para todos los nombres de $allNames.
     *
     * Regla: dos nombres se fusionan cuando su distancia Levenshtein es ≤ 2 Y comparten
     * ≥ 3 palabras de ≥ 3 letras. Como canónico se prefiere el nombre que aparece en
     * nómina ($payrollNames, fuente más confiable); si ninguno o ambos están, gana el
     * más largo. Nombres con menos de 2 palabras significativas no se fusionan.
     *
     * @param  string[]  $allNames  Nombres tal como se muestran, a considerar.
     * @param  string[]  $payrollNames  Nombres de NOI/nómina (preferidos como canónicos).
     * @return array<string,string> nombreNormalizado => nombreCanónicoNormalizado
     */
    public function buildCanonicalMap(array $allNames, array $payrollNames = []): array
    {
        $normAll = array_map(fn ($n) => $this->normalize($n), $allNames);
        $normPayroll = array_flip(array_map(fn ($n) => $this->normalize($n), $payrollNames));
        $uniqueKeys = array_values(array_unique($normAll));
        $assigned = [];

        foreach ($uniqueKeys as $idxA => $keyA) {
            if (isset($assigned[$keyA])) {
                continue;
            }

            $tokensA = array_values(array_filter(explode(' ', $keyA), fn ($t) => strlen($t) >= 3));
            if (count($tokensA) < 2) {
                $assigned[$keyA] = $keyA;

                continue;
            }

            foreach ($uniqueKeys as $idxB => $keyB) {
                if ($idxB <= $idxA || isset($assigned[$keyB])) {
                    continue;
                }

                $tokensB = array_values(array_filter(explode(' ', $keyB), fn ($t) => strlen($t) >= 3));
                if (count(array_intersect($tokensA, $tokensB)) < 3) {
                    continue;
                }

                if (levenshtein($keyA, $keyB) <= 2) {
                    if (isset($normPayroll[$keyA])) {
                        [$canonical, $alias] = [$keyA, $keyB];
                    } elseif (isset($normPayroll[$keyB])) {
                        [$canonical, $alias] = [$keyB, $keyA];
                    } elseif (strlen($keyA) >= strlen($keyB)) {
                        [$canonical, $alias] = [$keyA, $keyB];
                    } else {
                        [$canonical, $alias] = [$keyB, $keyA];
                    }
                    $assigned[$canonical] ??= $canonical;
                    $assigned[$alias] = $canonical;
                }
            }
            $assigned[$keyA] ??= $keyA;
        }

        foreach ($uniqueKeys as $k) {
            $assigned[$k] ??= $k;
        }

        return $assigned;
    }

    /**
     * Obtiene la clave canónica normalizada de un nombre, usando un mapa ya construido.
     *
     * @param  string  $displayName  Nombre sin normalizar.
     * @param  array<string,string>  $canonicalMap  Mapa devuelto por buildCanonicalMap().
     * @return string Clave canónica; si el nombre no está en el mapa, su forma normalizada.
     */
    public function canonicalKey(string $displayName, array $canonicalMap): string
    {
        $norm = $this->normalize($displayName);

        return $canonicalMap[$norm] ?? $norm;
    }

    /**
     * Devuelve todas las variantes (alias) normalizadas que apuntan a una clave canónica,
     * sin incluir la propia clave canónica.
     *
     * @param  string  $canonicalNorm  Clave canónica normalizada.
     * @param  array<string,string>  $canonicalMap  Mapa devuelto por buildCanonicalMap().
     * @return string[] Alias normalizados.
     */
    public function aliasesFor(string $canonicalNorm, array $canonicalMap): array
    {
        return array_values(array_filter(
            array_keys($canonicalMap),
            fn ($k) => $canonicalMap[$k] === $canonicalNorm && $k !== $canonicalNorm,
        ));
    }

    /**
     * Explica por qué dos claves ya normalizadas se fusionarían o no (diagnóstico para
     * comandos de depuración). Nombres idénticos (Levenshtein 0) se reportan como "no fusiona".
     *
     * @param  string  $keyA  Primera clave normalizada.
     * @param  string  $keyB  Segunda clave normalizada.
     * @return array{merges: bool, levenshtein: int, common_tokens: int, reason: string}
     */
    public function explainMerge(string $keyA, string $keyB): array
    {
        $tokA = array_values(array_filter(explode(' ', $keyA), fn ($t) => strlen($t) >= 3));
        $tokB = array_values(array_filter(explode(' ', $keyB), fn ($t) => strlen($t) >= 3));
        $common = count(array_intersect($tokA, $tokB));
        $lev = levenshtein($keyA, $keyB);
        $merges = $lev > 0 && $lev <= 2 && $common >= 3;

        return [
            'merges' => $merges,
            'levenshtein' => $lev,
            'common_tokens' => $common,
            'reason' => $merges
                ? "lev={$lev}≤2 + tokens_comunes={$common}≥3"
                : ($lev > 2 ? "lev={$lev}>2" : "tokens_comunes={$common}<3"),
        ];
    }
}

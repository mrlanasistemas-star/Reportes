<?php

namespace App\Support;

/**
 * Asigna una categoría normalizada a un gasto a partir del texto de su concepto (y,
 * opcionalmente, categoría/observaciones del archivo).
 *
 * Para qué sirve: los archivos de gastos (Lendus PDF/Excel y ERP) traen conceptos libres;
 * esta clase los traduce a las categorías que usa la radiografía (OPEX por línea, Nómina y
 * Capital Humano, Pólizas, Préstamos Intersucursales, etc.). Las reglas son por palabra
 * clave: el mapa está ordenado de lo más específico a lo más genérico y gana la PRIMERA
 * coincidencia (búsqueda por subcadena sobre el texto en mayúsculas y sin acentos).
 * Si nada coincide, la categoría es 'Gastos Operativos'.
 *
 * Quién la usa: GastosLendusPdfImportService, GastosLendusExcelImportService,
 * GastosErpExcelImportService (al importar) y ExpenseReviewQuery::categories() (catálogo de
 * destinos en la Revisión de gastos). Sin estado ni acceso a BD.
 */
class ExpenseCategoryMapper
{
    /**
     * Palabra clave (MAYÚSCULAS, sin acentos) => categoría. El orden importa: primera
     * coincidencia gana.
     */
    private const MAP = [
        // Excedente enviado a corporativo — debe ir antes del respaldo genérico
        'EXCEDENTE' => 'Envío de utilidad a corporativo',

        // Intersucursal / Fondeo — va al inicio (lo más específico)
        'PRESTAMO INTERSUCURSAL' => 'Préstamos Intersucursales',
        'PRESTAMO SUCURSAL' => 'Préstamos Intersucursales',
        'INTERSUCURSAL' => 'Préstamos Intersucursales',
        'FONDEO' => 'Préstamos Intersucursales',
        'FONDEA' => 'Préstamos Intersucursales',

        // Nómina y Capital Humano — antes de términos más genéricos
        'NOMINA' => 'Nómina y Capital Humano',
        'DEDUCCION' => 'Nómina y Capital Humano',
        'IMSS' => 'Nómina y Capital Humano',

        // Financiamiento — frases específicas antes de los genéricos "MOTO"/"CELULAR"
        'FINANCIAMIENTO MOTO' => 'Nómina y Capital Humano',
        'FIN MOTO' => 'Nómina y Capital Humano',
        'FINANCIAMIENTO DE MOTO' => 'Nómina y Capital Humano',
        'PAGO MOTO' => 'Nómina y Capital Humano',
        'FINANCIAMIENTO CELULAR' => 'Financiamiento Celular',
        'PAGO CELULAR' => 'Financiamiento Celular',
        'CELULAR' => 'Financiamiento Celular',

        // Recargas telefónicas
        'RECARGA' => 'Recargas Telefónicas',

        // Teléfono e Internet
        'INTERNET' => 'Teléfono e Internet',
        'TELEFONO' => 'Teléfono e Internet',
        'TELEFON' => 'Teléfono e Internet',
        'DATOS MOVILES' => 'Teléfono e Internet',

        // Software / Licencias
        'CHAT GPT' => 'Software Póliza Anual',
        'CHATGPT' => 'Software Póliza Anual',
        'ASPEL' => 'Software Póliza Anual',
        'SOFTWARE' => 'Software Póliza Anual',
        'LICENCIA' => 'Software Póliza Anual',

        // Pólizas / Seguros / Coberturas (incluye Savehearts, CRECE, Crédito Grupal)
        'POLIZA' => 'Pólizas',
        'SEGURO' => 'Pólizas',
        'COBERTURA' => 'Pólizas',
        'SAVEHEARTS' => 'Pólizas',

        // Cascos
        'CASCO' => 'Nómina y Capital Humano',

        // Papelería / Insumos
        'PAPELERIA' => 'Insumos de Papelería',
        'UTILES DE OFICINA' => 'Insumos de Papelería',
        'INSUMOS DE PAPELERIA' => 'Insumos de Papelería',
        'CAFETERIA' => 'Insumos de Cafetería',
        'INSUMOS DE CAFETERIA' => 'Insumos de Cafetería',

        // Limpieza (genérico → Servicios de Limpieza)
        'INSUMOS DE LIMPIEZA' => 'Insumos de Limpieza',
        'LIMPIEZA' => 'Servicios de Limpieza',

        // Mantenimiento / Fumigación
        'FUMIGACION' => 'Mantenimiento',
        'MANTENIMIENTO' => 'Mantenimiento',
        'MTTO' => 'Mantenimiento',

        // Renta
        'RENTA BODEGA' => 'Renta de Bodegas',
        'RENTA OFICINA' => 'Renta Oficina',
        'ARRENDAMIENTO' => 'Renta Oficina',
        'RENTA' => 'Renta Oficina',

        // Servicios básicos
        'ELECTRICIDAD' => 'Luz',
        'CFE' => 'Luz',
        'LUZ' => 'Luz',
        'GARRAFON' => 'Agua',
        'AGUA' => 'Agua',

        // Mobiliario y equipo
        'MOBILIARIO' => 'Mobiliario y Equipo',
        'EQUIPO DE COMPUTO' => 'Mobiliario y Equipo',
        'COMPUTADORA' => 'Mobiliario y Equipo',
        'LAPTOP' => 'Mobiliario y Equipo',
        'PROYECTOR' => 'Mobiliario y Equipo',

        // Gasolina (categoría propia, más específico que TRANSPORTE)
        'GASOLINA' => 'Gasolina',
        'COMBUSTIBLE' => 'Gasolina',

        // Transportes / Paquetería
        'DHL' => 'Paquetería',
        'FEDEX' => 'Paquetería',
        'GUIAS' => 'Paquetería',
        'TAXI' => 'Transportes',
        'TRANSPORTE' => 'Transportes',
        'DILIGENCIAS' => 'Viáticos',
        'VIATICO' => 'Viáticos',
        'FLETE' => 'Fletes',

        // Publicidad / Mercadeo
        'PUBLICIDAD' => 'Publicidad',
        'MARKETING' => 'Publicidad',
        'MERCADEO' => 'Publicidad',
        'PEGOTE' => 'Pegotes',

        // Motocicletas / Mecánicos
        'TALLER REPARACION MOTO' => 'Servicios de Motocicletas',
        'TALLER REPARACION DE MOTO' => 'Servicios de Motocicletas',
        'MOTOCICLETA' => 'Servicios de Motocicletas',
        'SERVICIO DE MOTO' => 'Servicios de Motocicletas',
        'MANO DE OBRA' => 'Servicios de Motocicletas',
        'SPROCKET' => 'Servicios de Motocicletas',
        'SERVICE MOTO' => 'Servicios de Motocicletas',
        'MOTO' => 'Servicios de Motocicletas',
        'MECANICO' => 'Mecánicos',
        'MECANICA' => 'Mecánicos',

        // Trámites / Permisos
        'PERMISO VEHICULAR' => 'Permisos Vehiculares',
        'TRAMITE' => 'Trámites Gubernamentales',
        'GOBIERNO' => 'Trámites Gubernamentales',
        'ACTA' => 'Trámites Gubernamentales',

        // Multas
        'MULTA' => 'Multas e Infracciones',
        'INFRACCION' => 'Multas e Infracciones',

        // Paquetería / Envíos
        'PAQUETERIA' => 'Paquetería',
        'MENSAJERIA' => 'Paquetería',
        'ENVIO' => 'Paquetería',

        // Eventos
        'EVENTO' => 'Eventos',

        // Señora limpieza
        'SENORA LIMPIEZA' => 'Señora Limpieza',
        'SENORA' => 'Señora Limpieza',

        // Oxxo
        'OXXO' => 'Comisiones Oxxo',

        // Gastos médicos — específicos de nómina → Nómina y Capital Humano
        'GASTOS MEDICOS' => 'Nómina y Capital Humano',
        'MEDICO' => 'Gastos médicos',

        // Finiquito
        'FINIQUITO' => 'Nómina y Capital Humano',

        // Préstamo Z / Zéptimo — anticipo de nómina, no es gasto operativo
        'PRESTAMO Z' => 'Nómina y Capital Humano',
        'PRÉSTAMO Z' => 'Nómina y Capital Humano',
        'PAGO PRESTAMO Z' => 'Nómina y Capital Humano',

        // Formatería
        'FORMATER' => 'Formatería',

        // Legal
        'GASTOS LEGALES' => 'Gastos legales',
        'LEGAL' => 'Gastos legales',
        'NOTARIO' => 'Gastos legales',
        'HONORARIO' => 'Gastos legales',
    ];

    /**
     * Categorías que hoy produce el mapeo automático (solo lectura — Revisión de gastos
     * las ofrece como destino para conceptos sin categoría). No cambia ninguna regla.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        $cats = array_values(array_unique(array_values(self::MAP)));
        $cats[] = 'Gastos Operativos';
        sort($cats);

        return array_values(array_unique($cats));
    }

    /**
     * Asigna la categoría a partir de un solo texto de concepto.
     *
     * @param  string|null  $concept  Concepto del gasto.
     * @return string Categoría (o 'Gastos Operativos' si no hay coincidencia).
     */
    public static function fromConcept(?string $concept): string
    {
        return self::fromFields($concept);
    }

    /**
     * Asigna la categoría a partir de varios campos de texto combinados (concepto, categoría,
     * observaciones…), unidos con espacio y normalizados a MAYÚSCULAS sin acentos. Gana la
     * primera palabra clave de MAP que aparezca como subcadena. Si el texto queda vacío o
     * nada coincide, devuelve 'Gastos Operativos'.
     *
     * @param  string|null  ...$parts  Campos de texto (los null se ignoran).
     * @return string Categoría normalizada.
     */
    public static function fromFields(?string ...$parts): string
    {
        $text = implode(' ', array_filter(array_map(fn ($p) => $p !== null ? trim($p) : null, $parts)));

        if ($text === '') {
            return 'Gastos Operativos';
        }

        $upper = strtr(mb_strtoupper($text), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        foreach (self::MAP as $keyword => $category) {
            if (str_contains($upper, $keyword)) {
                return $category;
            }
        }

        return 'Gastos Operativos';
    }
}

/**
 * Explicación en palabras simples de cada concepto del Estado de Resultados.
 * Se usa en tooltips de la cabecera, la cascada y la tabla; una sola redacción
 * para que el mismo concepto se explique igual en toda la pantalla.
 */
export const CONCEPT_HELP: Record<string, string> = {
    ingresos_operativos:
        'Todo lo que ingresó por la operación del negocio en el periodo. Es el punto de partida del resultado.',
    opex_total:
        'Gastos operativos (OPEX): lo que cuesta operar. Incluye gastos y nómina de sucursales y gastos y nómina corporativa (lo que toman Dirección General y los socios NO es OPEX). Se resta completo antes del EBITDA.',
    ebitda: 'Lo que queda de los ingresos después de pagar la operación, antes de depreciación, intereses e impuestos. Es la medida principal de qué tan rentable es operar.',
    depreciacion_amortizacion:
        'El desgaste del periodo de los activos (vehículos, equipo) y de los sistemas propios. No es salida de dinero, pero reduce el resultado.',
    ebit: 'EBITDA menos depreciación y amortización: el resultado de la operación antes de intereses e impuestos.',
    resultado_financiero_neto:
        'Intereses y otros conceptos financieros: lo que se paga resta y lo que se cobra suma.',
    ebt: 'Utilidad antes de impuestos (EBT): EBIT más o menos el resultado financiero.',
    isr: 'Impuesto sobre la renta del periodo. Mientras no se capture se muestra «Pendiente», nunca $0.',
    utilidad_neta:
        'Lo que realmente queda al final del periodo, después de depreciación, intereses e ISR.',
    retiros_dg_socios:
        'Lo que tomaron Dirección General y los socios en el periodo. No es OPEX ni cambia el EBITDA: se resta al final, después de la utilidad neta.',
    resultado_despues_retiros:
        'Lo que queda después de restar a la utilidad neta los retiros de Dirección General y socios (si el ISR está pendiente, se calcula sobre el EBT). En rojo, tomaron dinero del flujo siguiente.',
    margen_ebitda: 'Qué parte de cada peso de ingresos queda como EBITDA.',
    margen_ebit: 'Qué parte de cada peso de ingresos queda como EBIT.',
    margen_neto: 'Qué parte de cada peso de ingresos queda como utilidad neta.',
    margen_despues_retiros:
        'Qué parte de cada peso de ingresos queda después de los retiros de Dirección General y socios.',
};

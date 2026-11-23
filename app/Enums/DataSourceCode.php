<?php

namespace App\Enums;

/**
 * Catálogo de fuentes de datos que se pueden subir a un periodo de reportería.
 *
 * Cada caso identifica un tipo de archivo (NOI, Lendus, gastos ERP/Lendus,
 * IMSS, rotación, etc.). El valor se guarda en `report_uploads` y en
 * `data_sources.code`, y decide qué importador procesa el archivo
 * (ver DatabaseUpdateService, ReportUploadController y StoreReportUploadRequest).
 * Importante: los valores son persistentes en BD; no renombrarlos.
 */
enum DataSourceCode: string
{
    case NoiNomina = 'noi_nomina';
    case NoiNominaFiscal = 'noi_nomina_fiscal';
    case LendusMinistraciones = 'lendus_ministraciones';
    case LendusIngresosCobranza = 'lendus_ingresos_cobranza';
    case LendusSaldosCliente = 'lendus_saldos_cliente';
    case LendusSaldosClienteFoto = 'lendus_saldos_cliente_foto';
    case Gastos = 'gastos';
    case GastosLendus = 'gastos_lendus';
    case GastosErp = 'gastos_erp';
    case GastosLendusExcel = 'gastos_lendus_excel';
    case MacroAnalisis = 'macro_analisis';
    case LendusEmpleados = 'lendus_empleados';
    case Imss = 'imss';
    case Rotacion = 'rotacion';

}

<?php

namespace App\Services\EstadoResultados\Classification;

use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;

/**
 * Reglas AUTOMÁTICAS de clasificación de movimientos complementarios.
 *
 * Principio: sólo hay regla determinista cuando el texto Y el contexto (cargo/abono,
 * sucursal, conciliación) dan evidencia suficiente. Todo lo demás termina en
 * PENDIENTE (requiere revisión) con una SUGERENCIA que el usuario puede aceptar.
 * Ninguna regla convierte un abono en ingreso operativo ni un cargo ambiguo en OPEX.
 *
 * Cada resultado trae rule_code + reason legible: la UI responde "¿por qué sí/no sumó?".
 */
class MovementClassifier
{
    /**
     * Versión de las reglas automáticas. Se guarda en cada corrida (estado_resultados_runs.rules_version,
     * vía config estado_resultados.rules_version): si un periodo NO aprobado se procesó con otra
     * versión, RulesRefreshService lo reprocesa solo (al desplegar y al abrir el ER).
     * Súbala cada vez que cambie una regla de esta clase o de ErpOutsideClassifier.
     */
    public const RULES_VERSION = '2026-11-25.1';

    /** Rule codes de los retiros de Dirección General / socios (debajo de la Utilidad neta). */
    public const DG_RULES = ['retiro_dg', 'retiro_socios', 'retiro_dg_devolucion', 'erp_dg'];

    /** Servicios recurrentes de Corporativo que son OPEX corporativo (contables, auditor, redes, trámites, pipas). */
    public const CORPORATE_OPEX_PATTERN = '/SERV(ICIOS?)? CONTABLES|\bAUDITOR(IA)?\b|PAUTAS Y GENERACION DE CONTENIDO|CONTENIDO EN REDES|\bPAGO TRAMITES?\b|PIPAS? DE AGUA/';

    /** Conceptos personales de DG/socios (sin «DG» en el texto) que también son retiros. */
    public const PERSONAL_PATTERN = '/COLEGIATURA|ESCUELA|PASEADOR|VETERINARIO|JARDINERO|ALBERCA|LAVANDERIA|\bFAMILIA\b|ENTRENADOR|EQUITACION|JIUJITSU|CASA DG|RETORNO (DE )?INVERSION/';

    /**
     * Reglas activas del catálogo de conceptos (ConceptCatalogService::activeRules()). El
     * procesador las carga una vez por corrida. Ganan sobre las reglas automáticas por texto,
     * nunca sobre: fuera de periodo/duplicado, traspaso entre cuentas propias, conciliación con
     * ERP (total o parcial), duplicado UUID ni folios con diferencias contra el ERP.
     *
     * @var list<array<string,mixed>>
     */
    private array $catalog = [];

    /**
     * Palabras que indican que un ABONO es una aportación de sucursal (sobre texto normalizado).
     */
    public const CONTRIBUTION_KEYWORDS = '/\b(DEPOSITO|DEP|APORTACION|APORTE|TRANSFERENCIA|TRANSF|SPEI|PAGO)\b/';

    /**
     * Créditos registrados (FinancialExpenseService::classifierCredits()) con sus regex ya
     * compiladas: `pay` = cargo de pago al acreedor, `contrib` = abono de aportación. Se cargan una
     * vez por corrida (useCredits); sin créditos sólo aplican las reglas legacy.
     *
     * @var list<array{key:string, name:string, pay:?string, contrib:?string}>
     */
    private array $credits = [];

    /**
     * Carga los créditos para la corrida en curso (una consulta por corrida, no por movimiento).
     *
     * @param  list<array<string,mixed>>  $credits  FinancialExpenseService::classifierCredits()
     */
    public function useCredits(array $credits): static
    {
        $this->credits = array_values(array_map(fn (array $c) => [
            'key' => (string) $c['key'],
            'name' => (string) ($c['creditor'] ?? $c['name'] ?? $c['key']),
            'pay' => FinancialExpenseService::compilePattern($c['bank_pattern'] ?? null),
            'contrib' => FinancialExpenseService::compilePattern($c['contribution_pattern'] ?? null),
        ], $credits));

        return $this;
    }

    /**
     * Carga las reglas activas del catálogo para la corrida en curso.
     *
     * @param  list<array<string,mixed>>  $rules  ConceptCatalogService::activeRules()
     */
    public function useCatalog(array $rules): static
    {
        $this->catalog = $rules;

        return $this;
    }

    /**
     * Clasifica un movimiento. Precedencia: fuera de periodo/duplicado → traspaso entre cuentas
     * propias → (abonos) catálogo o reglas de abono → (cargos) conciliación ERP (total, parcial
     * con excedente pendiente, duplicado UUID, folios en revisión) → catálogo → reglas de cargo.
     *
     * @param  array<string,mixed>  $m  movimiento (columnas de estado_resultados_movements)
     * @param  array<string,mixed>|null  $rec  resultado del reconciliador (sólo cargos en periodo)
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    public function classify(array $m, ?array $rec): array
    {
        if (! ($m['in_period'] ?? true)) {
            return $this->out(T::FUERA_PERIODO, null, 'fuera_periodo', match ($m['placement'] ?? null) {
                'duplicado' => 'Este mismo movimiento ya viene en otro archivo cargado: se cuenta una sola vez, en el periodo de su fecha.',
                'sin_periodo' => 'La fecha del movimiento no pertenece a ningún periodo mensual creado; se sumará cuando exista ese periodo.',
                'periodo_cerrado' => 'La fecha del movimiento cae en un periodo ya aprobado; no se suma para no alterar la versión aprobada.',
                'periodo_existente' => 'La fecha del movimiento cae en un periodo que ya estaba cargado antes; ese periodo no se modifica.',
                default => 'La fecha del movimiento no cae dentro del periodo; se considera en el periodo al que pertenece.',
            });
        }

        $concept = $this->norm($m['concept'] ?? '');
        $obs = $this->norm($m['observations'] ?? '');
        $ref = $this->norm($m['bank_reference'] ?? '');
        $text = trim($concept.' '.$obs);
        $isCargo = (float) ($m['cargo'] ?? 0) > 0;
        $kind = (string) ($m['branch_kind'] ?? 'vacia');

        // ── Transferencias entre cuentas propias (ambos sentidos) ─────────
        if (str_contains($concept, 'TRASPASO ENTRE CUENTAS')
            || str_contains($ref, 'TRASPASO CUENTAS PROPIAS')
            || preg_match('/\b(EXCEDENTES|ADMINISTRATIVA|PROVISION)\s+A\s+(EXCEDENTES|ADMINISTRATIVA|PROVISION)\b/', $obs)) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'traspaso_cuentas_propias', 'Traspaso entre cuentas propias ('.($m['observations'] ?: 'TRASPASO ENTRE CUENTAS').'). No es ingreso ni gasto; se conserva para conciliar el flujo.');
        }

        if (! $isCargo) {
            return $this->fromCatalog($m) ?? $this->classifyAbono($concept, $text, trim($text.' '.$ref), $m, $kind);
        }

        // ── Cargos: primero el resultado de conciliación contra ERP ──────
        $status = $rec['status'] ?? R::SIN_FOLIO;
        if (in_array($status, R::CONCILIADOS, true)) {
            // Aplicación parcial con excedente bancario: sólo lo aplicado se cuenta desde el ERP;
            // el remanente NO se incluye ni se excluye en automático.
            if (isset($rec['pending']) && ! Money::isZeroCents($rec['pending'])) {
                return $this->out(T::PENDIENTE, $this->suggestForCargo($concept, $kind, $m), 'conciliado_parcial_excedente', (string) ($rec['message'] ?? 'Conciliación parcial.'), true);
            }

            return $this->out(T::CONCILIADO_ERP, null, $status, (string) ($rec['message'] ?? 'Conciliado con ERP.'));
        }
        if ($status === R::DUPLICADO_UUID) {
            return $this->out(T::DUPLICADO, null, $status, (string) $rec['message']);
        }
        if (in_array($status, R::REVISION, true)) {
            return $this->out(T::PENDIENTE, $this->suggestForCargo($concept, $kind, $m), $status, (string) $rec['message'], true);
        }

        // Dirección General y socios: lo que toman es un RETIRO (debajo de la Utilidad neta), nunca
        // OPEX. Gana sobre el catálogo salvo que la regla del catálogo nombre a DG explícitamente.
        if (($withdrawal = $this->withdrawal($concept, $kind, $m)) !== null && ! $this->catalogMentionsDg($m)) {
            return $withdrawal;
        }

        if ($catalog = $this->fromCatalog($m)) {
            return $catalog;
        }

        return $this->classifyCargo($concept, $text, $kind, $m, $status, $rec);
    }

    /**
     * Reglas para abonos (entradas). Ninguna convierte un abono en ingreso operativo: excedentes,
     * provisiones y fondeo de deuda son flujo interno; descuentos son recuperación; reembolsos,
     * posibles ingresos no operativos y el resto quedan pendientes; pólizas son puente.
     *
     * @param  string  $concept  concepto normalizado
     * @param  string  $text  concepto + observaciones normalizados
     * @param  string  $full  concepto + observaciones + referencia normalizados
     * @param  array  $m  movimiento
     * @param  string  $kind  tipo de sucursal
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    private function classifyAbono(string $concept, string $text, string $full = '', array $m = [], string $kind = 'vacia'): array
    {
        $noIngreso = ' Un abono bancario nunca se registra como ingreso operativo en automático.';

        if (str_contains($concept, 'RETIRO EXCEDENTE') || str_contains($concept, 'EXCEDENTE')) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'excedente_sucursal', 'Excedente de efectivo enviado por la sucursal a Corporativo (en Radiografía es "Envío de utilidad a corporativo"). Flujo interno, no ingreso.');
        }
        if (str_contains($concept, 'PROVISION IMSS') || str_contains($concept, 'PROVISION')) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'provision_sucursal', 'Provisión depositada por la sucursal (IMSS). Flujo interno; el IMSS patronal ya está en Nómina y Capital Humano de sucursales.');
        }
        if ($this->credits !== [] && ($hit = $this->creditContribution($full !== '' ? $full : $text, $m, $kind))) {
            return $hit;
        }
        // Legacy (sin créditos cargados, o «CREDITO LEROM» sin palabra de aportación).
        if (preg_match('/DEPOSITO.*CREDITO|CREDITO (LEROM|BBVA)/', $concept)) {
            return $this->out(T::TRANSFERENCIA_INTERNA, null, 'fondeo_servicio_deuda', 'Depósito de sucursal para cubrir el servicio de deuda (LEROM/BBVA). Flujo interno, no ingreso.');
        }
        if ($this->hasDg($concept)) {
            return $this->out(T::PERSONAL_SOCIOS, null, 'retiro_dg_devolucion', 'Dinero que regresa Dirección General: reduce los retiros de DG y socios (no es ingreso).');
        }
        if (preg_match('/^PRUEBA SPEI\b/', $concept)) {
            return $this->out(T::BALANCE, null, 'prueba_spei', 'Prueba SPEI (centavos): validación de cuenta, fuera del ER.');
        }
        if (preg_match('/DEPOSITO ERRONEO|ERROR EN (EL )?DEPOSITO/', $text)) {
            return $this->out(T::BALANCE, null, 'error_bancario', 'Depósito erróneo o su devolución: error bancario que se revierte, fuera del ER.');
        }
        if (preg_match('/\bPRESTAMOS?\b/', $concept) && ! preg_match('/^DESC|LEROM|BBVA|CREDITO/', $concept)) {
            return $this->out(T::RECUPERABLE, null, 'prestamo', 'Préstamo o su devolución: cuenta por cobrar/pagar (balance), fuera del ER.');
        }
        if (preg_match('/^DISPERSION\b/', $concept) && ! in_array($kind, ['operativa', 'agrupado'], true)) {
            return $this->out(T::BALANCE, null, 'dispersion_creditos', 'Dispersión de créditos (colocación): movimiento de cartera/balance, fuera del ER.');
        }
        if (preg_match('/^DESC(UENTO)?\b|DESC PRESTAMO|DESC VENTA/', $concept)) {
            return $this->out(T::RECUPERABLE, null, 'descuento_recuperado', 'Recuperación de préstamo/venta a colaborador vía descuento. Es cobro de una cuenta por cobrar, no ingreso.');
        }
        if (preg_match('/DEVOLUCION|REEMBOLSO|REINTEGRO/', $text)) {
            // El procesador lo vincula con el gasto que reembolsa (mismo folio); sin vínculo queda pendiente.
            return $this->out(T::PENDIENTE, T::RECUPERABLE, 'reembolso_sin_vinculo', 'Reintegro/devolución sin gasto reconocido vinculado (mismo folio). No es ingreso; confirme si reduce un gasto o es una cuenta por cobrar.'.$noIngreso, true);
        }
        if (str_contains($concept, 'POLIZA')) {
            return $this->out(T::PUENTE_TERCEROS, null, 'poliza_puente', 'Cobro de pólizas por cuenta de la aseguradora (puente). Mismo criterio que Radiografía: no es ingreso.');
        }
        if (preg_match('/INDEMNIZACION|INTERES(ES)? GANADO|RENDIMIENTO/', $text)) {
            return $this->out(T::PENDIENTE, T::INGRESO_NO_OPERATIVO, 'abono_posible_no_operativo', 'Abono que podría ser ingreso no operativo (indemnización/rendimiento). Requiere confirmación.'.$noIngreso, true);
        }

        return $this->out(T::PENDIENTE, null, 'abono_sin_regla', 'Abono sin regla de clasificación.'.$noIngreso, true);
    }

    /**
     * Aportación de sucursal para el servicio de deuda (ABONO). Requiere palabra de aportación
     * (DEPÓSITO, APORTACIÓN, TRANSFERENCIA, PAGO…) + el acreedor de UN crédito registrado
     * (contribution_pattern) + sucursal operativa/agrupada identificada ⇒ aportacion_credito
     * (transferencia interna). Acreedor sin sucursal, dos acreedores o «DEPÓSITO CRÉDITO» sin
     * acreedor ⇒ pendiente con sugerencia (aportacion_credito_sugerida). null = no aplica.
     *
     * @param  string  $full  texto normalizado (concepto + observaciones + referencia)
     * @param  array  $m  movimiento
     * @param  string  $kind  tipo de sucursal
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}|null
     */
    private function creditContribution(string $full, array $m, string $kind): ?array
    {
        if (! preg_match(self::CONTRIBUTION_KEYWORDS, $full)) {
            return null;
        }
        $matched = array_values(array_filter($this->credits, fn ($c) => $c['contrib'] !== null && preg_match($c['contrib'], $full) === 1));
        $flow = ' Flujo interno: no es ingreso ni gasto; sirve para el prorrateo gerencial del costo financiero.';
        if ($matched === []) {
            if (preg_match('/\b(DEPOSITO|APORTACION)\b.*\bCREDITO\b/', $full)) {
                return $this->out(T::PENDIENTE, T::TRANSFERENCIA_INTERNA, FinancialExpenseService::RULE_APORTACION_SUGERIDA,
                    'Parece una aportación para un crédito, pero no menciona a qué acreedor (LEROM, BBVA…). Confirma el crédito.'.$flow, true);
            }

            return null;
        }
        $branch = trim((string) ($m['branch_name'] ?? ''));
        if (count($matched) > 1) {
            return $this->out(T::PENDIENTE, T::TRANSFERENCIA_INTERNA, FinancialExpenseService::RULE_APORTACION_SUGERIDA,
                'Aportación que menciona más de un acreedor ('.implode(', ', array_column($matched, 'name')).'). Confirma a qué crédito corresponde.'.$flow, true);
        }
        $credit = $matched[0]['name'];
        if ($branch === '' || ! in_array($kind, ['operativa', 'agrupado'], true)) {
            // Se clasifica solo (nunca queda pendiente): sin sucursal cuenta en «Sin asignar» del prorrateo.
            return $this->out(T::TRANSFERENCIA_INTERNA, null, FinancialExpenseService::RULE_APORTACION,
                'Aportación para el crédito '.$credit.' sin sucursal identificada; cuenta como «Sin asignar» en el prorrateo.'.$flow);
        }

        return $this->out(T::TRANSFERENCIA_INTERNA, null, FinancialExpenseService::RULE_APORTACION,
            'Aportación de '.$branch.' para el crédito '.$credit.'.'.$flow);
    }

    /**
     * ¿El texto es un pago a un acreedor registrado (bank_pattern de un crédito)?
     *
     * @param  string  $text  texto normalizado
     * @return string|null nombre del acreedor o null
     */
    private function creditorPayment(string $text): ?string
    {
        foreach ($this->credits as $c) {
            if ($c['pay'] !== null && preg_match($c['pay'], $text) === 1) {
                return $c['name'];
            }
        }

        return null;
    }

    /**
     * Reglas para cargos NO conciliados con ERP: retenciones (balance), servicio de deuda
     * (capital, intereses vía tabla de amortización), pólizas puente; en sucursal operativa la
     * nómina ya está en Radiografía (NOI) y lo demás queda pendiente; en Corporativo DG queda
     * pendiente, nómina/finiquito es nómina corporativa y lo demás pendiente; sin sucursal,
     * pendiente.
     *
     * @param  string  $concept  concepto normalizado
     * @param  string  $text  concepto + observaciones normalizados
     * @param  string  $kind  tipo de sucursal (operativa, agrupado, corporativo, desconocida, vacia)
     * @param  array  $m  movimiento
     * @param  string  $status  estado de conciliación ERP
     * @param  array|null  $rec  resultado del reconciliador
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    private function classifyCargo(string $concept, string $text, string $kind, array $m, string $status, ?array $rec): array
    {
        // Préstamos ("PAGO PRESTAMOS QUINCENA 17") NUNCA son nómina aunque mencionen la quincena.
        $isNomina = ! str_contains($concept, 'PRESTAMO')
            && (bool) preg_match('/\bPAGO NOM\b|\bNOM(INA)?\b|\bQUINCENA\b|\bNOM SEM\b/', $concept);
        $suggested = $this->suggestForCargo($concept, $kind, $m);

        // Retenciones / pasivos de terceros: no son gasto de la empresa.
        if (preg_match('/SAT RETENCION|RETENCIONES|CEDULA FONACOT|FONACOT|PENSION ALIMENTICIA|INFONAVIT/', $concept)) {
            return $this->out(T::BALANCE, null, 'retenciones_terceros', 'Entero de retenciones o descuentos de terceros (SAT/FONACOT/pensión/Infonavit). Es un pasivo que se liquida, no un gasto.');
        }
        // Servicio de deuda bancaria: el pago completo NO es gasto financiero.
        if (preg_match('/CREDITO (LEROM|BBVA)|PAGO \d+ CREDITO|AMORTIZACION (DE )?CREDITO/', $concept)
            || (! str_contains($text, 'DEPOSITO') && $this->creditorPayment($text) !== null)) {
            return $this->out(T::CAPITAL_DEUDA, null, 'servicio_deuda', 'Pago a acreedor ('.($m['concept'] ?? '').'). El capital no es gasto; sólo los intereses entran entre EBIT y EBT, tomados de la tabla de amortización del crédito (pestaña «Gastos financieros»); el pago completo nunca se registra como gasto financiero.');
        }
        // Pólizas cobradas por cuenta de terceros.
        if (preg_match('/POLIZAS? (CRECE|GRUPAL|GALNAG)|PAGO POLIZAS GALNAG/', $concept)) {
            return $this->out(T::PUENTE_TERCEROS, null, 'poliza_puente', 'Pago de pólizas cobradas a clientes (puente a aseguradora). Mismo criterio que Radiografía: no es OPEX.');
        }

        // Movimientos de balance y financieros recurrentes (25-nov-2026): nunca son OPEX.
        if (preg_match('/^PRUEBA SPEI\b/', $concept)) {
            return $this->out(T::BALANCE, null, 'prueba_spei', 'Prueba SPEI (centavos): validación de cuenta, fuera del ER.');
        }
        if (preg_match('/DEPOSITO ERRONEO|ERROR EN (EL )?DEPOSITO/', $text)) {
            return $this->out(T::BALANCE, null, 'error_bancario', 'Devolución de un depósito erróneo: error bancario que se revierte, fuera del ER.');
        }
        if (preg_match('/\bPRESTAMOS?\b/', $concept)) {
            return $this->out(T::RECUPERABLE, null, 'prestamo', 'Préstamo (o su pago/devolución): cuenta por cobrar, fuera del ER. No es nómina ni gasto.');
        }
        if (preg_match('/^DISPERSION\b/', $concept) && ! $isNomina && ! in_array($kind, ['operativa', 'agrupado'], true)) {
            return $this->out(T::BALANCE, null, 'dispersion_creditos', 'Dispersión de créditos a clientes (colocación): es cartera/balance, fuera del ER.');
        }
        if (preg_match('/COMISION(ES)? BANCARIA/', $concept)) {
            return $this->out(T::GASTO_FINANCIERO, null, 'comisiones_bancarias', 'Comisiones bancarias: otros gastos financieros (resultado financiero, no OPEX).');
        }

        if ($kind === 'operativa' || $kind === 'agrupado') {
            if ($isNomina) {
                return $this->out(T::CUBIERTO_RADIOGRAFIA, null, 'nomina_sucursal_noi', 'Dispersión de nómina de sucursal: el costo ya está en Nómina y Capital Humano (NOI) de Radiografía. Sumarlo otra vez lo duplicaría.');
            }

            $why = $status === R::FOLIO_SIN_ERP
                ? 'Movimiento de sucursal con folio que no aparece en el ERP cargado.'
                : 'Movimiento de sucursal sin conciliación con el ERP.';

            return $this->out(T::PENDIENTE, $suggested, $status === R::FOLIO_SIN_ERP ? 'sucursal_folio_sin_erp' : 'sucursal_sin_erp', $why.' Radiografía ya integra ERP/Lendus/NOI de las sucursales: confirme que no está registrado antes de sumarlo al ER.', true);
        }

        if ($kind === 'corporativo') {
            if (preg_match(self::CORPORATE_OPEX_PATTERN, $concept)) {
                return $this->out(T::OPEX_CORPORATIVO, null, 'opex_corporativo_recurrente', 'Servicio recurrente de Corporativo ('.($m['concept'] ?? '').'): OPEX corporativo.');
            }
            if ($isNomina || preg_match('/\bFINIQUITO\b/', $concept)) {
                return $this->out(T::NOMINA_CORPORATIVA, null, 'nomina_corporativa', 'Pago de nómina/finiquito del personal de Corporativo (no está en el NOI de sucursales). Reduce el EBITDA corporativo.');
            }
            if ($status === R::FOLIO_SIN_ERP) {
                return $this->out(T::PENDIENTE, $suggested, 'corporativo_folio_sin_erp', 'Requisición de Corporativo cuyo folio no está en el ERP cargado. Confirme que no se registró con otro folio/periodo antes de sumarla.', true);
            }

            return $this->out(T::PENDIENTE, $suggested, 'corporativo_sin_folio', 'Pago de Corporativo sin requisición (S/R). El concepto por sí solo no es evidencia suficiente para clasificarlo en automático.', true);
        }

        return $this->out(T::PENDIENTE, $suggested, 'sin_sucursal', 'Cargo sin sucursal reconocible ('.($m['sucursal_raw'] ?: 'vacía').'). Requiere revisión.', true);
    }

    /**
     * Sugerencia (NUNCA aplicada en automático) para un cargo que requiere revisión.
     *
     * @param  string  $concept  concepto normalizado
     * @param  string  $kind  tipo de sucursal
     * @param  array  $m  movimiento
     * @return string|null tratamiento sugerido o null sin evidencia
     */
    private function suggestForCargo(string $concept, string $kind, array $m): ?string
    {
        if (preg_match(self::PERSONAL_PATTERN, $concept) || $this->hasDg($concept)) {
            return T::PERSONAL_SOCIOS;
        }
        if (preg_match('/COMPUTADORA|EQ(UIPO)? (DE )?COMPUTO|LAPTOP|ROUTER|MOBILIARIO|\bBYD\b|FIRMA CAR|VEHICULO|AUTOMOVIL/', $concept)) {
            return T::CAPEX;
        }
        if (preg_match('/PRESTAMO/', $concept)) {
            return T::RECUPERABLE;
        }
        if ($kind === 'operativa' || $kind === 'agrupado') {
            return preg_match('/FINIQUITO|\bNOM/', $concept) ? T::NOMINA_SUCURSAL : T::OPEX_SUCURSAL;
        }
        if ($kind === 'corporativo') {
            if (preg_match('/COMISION/', $concept)) {
                return T::NOMINA_CORPORATIVA;
            }
            if ($concept === 'PAGO' || $concept === '') {
                return null; // "PAGO" genérico: sin evidencia ni para sugerir
            }

            return T::OPEX_CORPORATIVO;
        }

        return null;
    }

    /**
     * Retiro de Dirección General / socios (CARGO no conciliado): cualquier concepto con «DG» o un
     * gasto personal de socios (colegiatura, jardinero, alberca, familia, retorno de inversión…).
     * En sucursal operativa, un gasto personal SIN «DG» queda en revisión. null = no aplica.
     *
     * @param  string  $concept  concepto normalizado
     * @param  string  $kind  tipo de sucursal
     * @param  array  $m  movimiento
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}|null
     */
    private function withdrawal(string $concept, string $kind, array $m): ?array
    {
        if ($this->hasDg($concept)) {
            return $this->out(T::PERSONAL_SOCIOS, null, 'retiro_dg', 'Dinero que toma Dirección General: no es OPEX; se resta después de la Utilidad neta.');
        }
        if (preg_match(self::PERSONAL_PATTERN, $concept) && ! in_array($kind, ['operativa', 'agrupado'], true)) {
            return $this->out(T::PERSONAL_SOCIOS, null, 'retiro_socios', 'Gasto personal de socios: no es OPEX; se resta después de la Utilidad neta.');
        }

        return null;
    }

    /**
     * ¿La regla del catálogo que gana para el movimiento nombra a Dirección General? Entonces manda
     * el catálogo: el usuario lo decidió explícitamente para ese concepto DG.
     *
     * @param  array  $m  movimiento
     */
    private function catalogMentionsDg(array $m): bool
    {
        if ($this->catalog === []) {
            return false;
        }
        $hit = ConceptCatalogService::match($m, $this->catalog)['rule'];

        return $hit !== null && $this->hasDg((string) ($hit['norm'] ?? ''));
    }

    /**
     * ¿El concepto menciona a Dirección General («DG» como palabra o «DIRECCION GENERAL»)?
     *
     * @param  string  $concept  concepto normalizado
     */
    private function hasDg(string $concept): bool
    {
        return (bool) preg_match('/\bDG\b|DIRECCION GENERAL/', $concept);
    }

    /**
     * Regla del catálogo de conceptos que gana para el movimiento (null = ninguna coincide).
     *
     * @param  array  $m  movimiento
     * @return array|null resultado de ConceptCatalogService::outcome()
     */
    private function fromCatalog(array $m): ?array
    {
        if ($this->catalog === []) {
            return null;
        }
        $hit = ConceptCatalogService::match($m, $this->catalog);

        return $hit['rule'] !== null ? ConceptCatalogService::outcome($hit['rule'], $hit['others']) : null;
    }

    /**
     * Normaliza texto (mayúsculas, sin acentos, espacios simples).
     *
     * @param  string|null  $v  texto
     */
    private function norm(?string $v): string
    {
        return ConceptCatalogService::normalize($v);
    }

    /**
     * Resultado de clasificación con la forma estándar.
     *
     * @param  string  $treatment  tratamiento asignado
     * @param  string|null  $suggested  sugerencia para revisión
     * @param  string  $rule  código de regla
     * @param  string  $reason  explicación en español
     * @param  bool  $review  requiere revisión
     * @return array{treatment:string, suggested:?string, rule:string, reason:string, review:bool}
     */
    private function out(string $treatment, ?string $suggested, string $rule, string $reason, bool $review = false): array
    {
        return ['treatment' => $treatment, 'suggested' => $suggested, 'rule' => $rule, 'reason' => $reason, 'review' => $review];
    }
}

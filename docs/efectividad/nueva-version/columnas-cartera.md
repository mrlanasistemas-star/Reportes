# Fotografía de cartera — mapa de las 156 columnas

Reporte **Saldos por Cliente** de Lendus tal como lo exporta el sistema: 156 columnas y 4 filas de título; el importador busca el encabezado en las primeras 30 filas. Generado a partir de los encabezados del libro de referencia (solo los nombres de las columnas; ningún dato) y de `EfectividadV4ColumnMap::FOTO`.

Si un encabezado aparece repetido, se toma la **primera** columna, igual que `ColumnaEncabezado` de la macro. La comparación ignora mayúsculas, acentos y espacios dobles.

| # | Encabezado Lendus | Campo V4 | Columna BD (`fact_portfolio_snapshot_rows`) | Uso |
|---:|---|---|---|---|
| 1 | Cliente | `cliente` | `client_code` | Se guarda para diagnóstico; la macro no la usa |
| 2 | Nombre del cliente | — | — | Dato personal — **no se importa** |
| 3 | Acreditado | `acreditado` | `acreditado` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 4 | Apellido paterno | — | — | Dato personal — **no se importa** |
| 5 | Apellido materno | — | — | Dato personal — **no se importa** |
| 6 | Nombre(s) | — | — | Dato personal — **no se importa** |
| 7 | Nombre completo | — | — | Dato personal — **no se importa** |
| 8 | Ciclo acreditado | — | — | No se usa (no se importa) |
| 9 | Actividad | — | — | No se usa (no se importa) |
| 10 | Concepto actividad | — | — | No se usa (no se importa) |
| 11 | Dirección | — | — | Dato personal — **no se importa** |
| 12 | Municipio | — | — | Dato personal — **no se importa** |
| 13 | Estado | — | — | No se usa (no se importa) |
| 14 | Latitud | — | — | Dato personal — **no se importa** |
| 15 | Longitud | — | — | Dato personal — **no se importa** |
| 16 | Direccion del negocio | — | — | Dato personal — **no se importa** |
| 17 | Teléfono | — | — | Dato personal — **no se importa** |
| 18 | Nombre aval | — | — | Dato personal — **no se importa** |
| 19 | Dirección aval | — | — | Dato personal — **no se importa** |
| 20 | Teléfono aval | — | — | Dato personal — **no se importa** |
| 21 | H | — | — | No se usa (no se importa) |
| 22 | M | — | — | No se usa (no se importa) |
| 23 | Oficina | `oficina` | `office_name_raw` | **Cálculo — obligatoria** (error 920 si falta) |
| 24 | Zona | — | — | No se usa (no se importa) |
| 25 | Coordinador | — | — | No se usa (no se importa) |
| 26 | Nombre coordinador | — | — | No se usa (no se importa) |
| 27 | Promotor | `promotor_codigo` | `promoter_code` | Se guarda para diagnóstico; la macro no la usa |
| 28 | Nombre promotor | `nombre_promotor` | `promoter_name` | **Cálculo — obligatoria** (error 920 si falta) |
| 29 | RFC Promotor | — | — | Dato personal — **no se importa** |
| 30 | Gestor de cobranza | — | — | No se usa (no se importa) |
| 31 | Nombre gestor de cobranza | — | — | No se usa (no se importa) |
| 32 | Comisionista | — | — | No se usa (no se importa) |
| 33 | Tipo cliente | — | — | No se usa (no se importa) |
| 34 | Tipo de crédito | — | — | No se usa (no se importa) |
| 35 | Producto de crédito | `producto` | `product_name_raw` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 36 | Financiamiento | — | — | No se usa (no se importa) |
| 37 | Destino de crédito | — | — | No se usa (no se importa) |
| 38 | Código Empleador | — | — | No se usa (no se importa) |
| 39 | Empleador | — | — | No se usa (no se importa) |
| 40 | Número de empleado | — | — | Dato personal — **no se importa** |
| 41 | RECA | — | — | No se usa (no se importa) |
| 42 | Origen crédito | `origen` | `origin` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 43 | Ciclo | — | — | No se usa (no se importa) |
| 44 | Contrato | `contrato` | `contract` | **Cálculo — obligatoria** (error 908 si falta; sin Contrato la fila se omite) |
| 45 | Estatus | `estatus` | `status` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 46 | Substatus | `substatus` | `substatus` | **Cálculo — obligatoria** (error 920 si falta) |
| 47 | Tasa anual | — | — | No se usa (no se importa) |
| 48 | Tasa mensual | — | — | No se usa (no se importa) |
| 49 | Fecha desembolso | — | — | No se usa (no se importa) |
| 50 | Fecha primer pago | `fecha_primer_pago` | `first_payment_date` | **Cálculo — obligatoria** (error 920 si falta) |
| 51 | Fecha vencimiento | `fecha_vencimiento` | `contract_due_date` | **Cálculo — obligatoria** (error 920 si falta) |
| 52 | Cuota | — | — | No se usa (no se importa) |
| 53 | Cuota cargos calendario | — | — | No se usa (no se importa) |
| 54 | Cuota total | `cuota_total` | `installment_total` | **Cálculo — obligatoria** (error 920 si falta) |
| 55 | Capital | — | — | No se usa (no se importa) |
| 56 | Interés | — | — | No se usa (no se importa) |
| 57 | Impuesto | — | — | No se usa (no se importa) |
| 58 | Cargos primer pago | — | — | No se usa (no se importa) |
| 59 | Impuesto cargos primer pago | — | — | No se usa (no se importa) |
| 60 | Cargos calendario | — | — | No se usa (no se importa) |
| 61 | Impuesto cargos calendario | — | — | No se usa (no se importa) |
| 62 | Interés moratorio | — | — | No se usa (no se importa) |
| 63 | Impuesto interés moratorio | — | — | No se usa (no se importa) |
| 64 | Otros cargos vencimiento | — | — | No se usa (no se importa) |
| 65 | Impuesto otros cargos vencimiento | — | — | No se usa (no se importa) |
| 66 | Cargos evento | — | — | No se usa (no se importa) |
| 67 | Impuesto cargos evento | — | — | No se usa (no se importa) |
| 68 | Total a pagar | — | — | No se usa (no se importa) |
| 69 | Total pagado | — | — | No se usa (no se importa) |
| 70 | Total condonado | — | — | No se usa (no se importa) |
| 71 | Total ajustado | — | — | No se usa (no se importa) |
| 72 | Saldo actual | `saldo_actual` | `current_balance` | Se guarda para diagnóstico; la macro no la usa |
| 73 | Tasa Moratoria | — | — | No se usa (no se importa) |
| 74 | Plazo meses | — | — | No se usa (no se importa) |
| 75 | Periodicidad | `periodicidad` | `periodicity` | **Cálculo — obligatoria** (error 920 si falta) |
| 76 | Pagos | `pagos` | `num_payments` | **Cálculo — obligatoria** (error 920 si falta) |
| 77 | Pagos realizados | — | — | No se usa (no se importa) |
| 78 | Capital pagado | — | — | No se usa (no se importa) |
| 79 | Capital condonado | — | — | No se usa (no se importa) |
| 80 | Capital ajustado | — | — | No se usa (no se importa) |
| 81 | Saldo capital | `saldo_capital` | `saldo_capital` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 82 | Interés pagado | — | — | No se usa (no se importa) |
| 83 | Interés condonado | — | — | No se usa (no se importa) |
| 84 | Interés ajustado | — | — | No se usa (no se importa) |
| 85 | Saldo interés | `saldo_interes` | `saldo_interes` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 86 | Impuesto pagado | — | — | No se usa (no se importa) |
| 87 | Impuesto condonado | — | — | No se usa (no se importa) |
| 88 | Impuesto ajustado | — | — | No se usa (no se importa) |
| 89 | Saldo impuesto | `saldo_impuesto` | `saldo_impuesto` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 90 | Cargos primer pago pagado | — | — | No se usa (no se importa) |
| 91 | Cargos primer pago condonado | — | — | No se usa (no se importa) |
| 92 | Cargos primer pago ajustado | — | — | No se usa (no se importa) |
| 93 | Saldo cargos primer pago | — | — | No se usa (no se importa) |
| 94 | Impuesto cargos primer pago pagado | — | — | No se usa (no se importa) |
| 95 | Impuesto cargos primer pago condonado | — | — | No se usa (no se importa) |
| 96 | Impuesto cargos primer pago ajustado | — | — | No se usa (no se importa) |
| 97 | Saldo impuesto cargos primer pago | — | — | No se usa (no se importa) |
| 98 | Cargos calendario pagado | — | — | No se usa (no se importa) |
| 99 | Cargos calendario condonado | — | — | No se usa (no se importa) |
| 100 | Cargos calendario ajustado | — | — | No se usa (no se importa) |
| 101 | Saldo cargos calendario | `saldo_cargos_calendario` | `saldo_cargos_calendario` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 102 | Impuesto cargos calendario pagado | — | — | No se usa (no se importa) |
| 103 | Impuesto cargos calendario condonado | — | — | No se usa (no se importa) |
| 104 | Impuesto cargos calendario ajustado | — | — | No se usa (no se importa) |
| 105 | Saldo impuesto cargos calendario | `saldo_impuesto_cargos_calendario` | `saldo_impuesto_cargos_calendario` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 106 | Interés moratorio pagado | — | — | No se usa (no se importa) |
| 107 | Interés moratorio condonado | — | — | No se usa (no se importa) |
| 108 | Interés moratorio ajustado | — | — | No se usa (no se importa) |
| 109 | Saldo interés moratorio | — | — | No se usa (no se importa) |
| 110 | Impuesto interés moratorio pagado | — | — | No se usa (no se importa) |
| 111 | Impuesto interés moratorio condonado | — | — | No se usa (no se importa) |
| 112 | Impuesto interés moratorio ajustado | — | — | No se usa (no se importa) |
| 113 | Saldo impuesto interés moratorio | — | — | No se usa (no se importa) |
| 114 | Otros cargos vencimiento pagado | — | — | No se usa (no se importa) |
| 115 | Otros cargos vencimiento condonado | — | — | No se usa (no se importa) |
| 116 | Saldo otros cargos vencimiento | — | — | No se usa (no se importa) |
| 117 | Impuesto otros cargos vencimiento pagado | — | — | No se usa (no se importa) |
| 118 | Impuesto otros cargos vencimiento condonado | — | — | No se usa (no se importa) |
| 119 | Saldo impuesto otros cargos vencimiento | — | — | No se usa (no se importa) |
| 120 | Cargos evento pagado | — | — | No se usa (no se importa) |
| 121 | Cargos evento condonado | — | — | No se usa (no se importa) |
| 122 | Saldo cargos evento | — | — | No se usa (no se importa) |
| 123 | Impuesto cargos evento pagado | — | — | No se usa (no se importa) |
| 124 | Impuesto cargos evento condonado | — | — | No se usa (no se importa) |
| 125 | Saldo impuesto cargos evento | — | — | No se usa (no se importa) |
| 126 | Excedente pagado | — | — | No se usa (no se importa) |
| 127 | Pagos pendientes | — | — | No se usa (no se importa) |
| 128 | Capital pendiente | — | — | No se usa (no se importa) |
| 129 | Interés pendiente | — | — | No se usa (no se importa) |
| 130 | Impuesto pendiente | — | — | No se usa (no se importa) |
| 131 | Cargos calendario pendiente | — | — | No se usa (no se importa) |
| 132 | Impuesto cargos calendario pendiente | — | — | No se usa (no se importa) |
| 133 | Total pendiente | `total_pendiente` | `total_pending` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 134 | Pagos atrasados | `pagos_atrasados` | `overdue_installments` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 135 | Capital atrasado | — | — | No se usa (no se importa) |
| 136 | Interés atrasado | — | — | No se usa (no se importa) |
| 137 | Impuesto atrasado | — | — | No se usa (no se importa) |
| 138 | Cargos calendario atrasado | — | — | No se usa (no se importa) |
| 139 | Impuesto cargos calendario atrasado | — | — | No se usa (no se importa) |
| 140 | Total atrasado | `total_atrasado` | `total_overdue` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 141 | Fecha primer pago realizado | — | — | No se usa (no se importa) |
| 142 | Fecha última transacción | `fecha_ultima_transaccion` | `— (solo validación)` | Validación de la fecha declarada de la fotografía (no entra al cálculo) |
| 143 | Fecha último pago realizado | — | — | No se usa (no se importa) |
| 144 | Número siguiente pago | — | — | No se usa (no se importa) |
| 145 | Fecha siguiente pago | `fecha_siguiente_pago` | `next_payment_date` | Cálculo — opcional (columna ausente ≠ columna en cero) |
| 146 | Días mora | — | — | No se usa (no se importa) |
| 147 | Días Atraso | `dias_atraso` | `days_overdue` | Se guarda para diagnóstico; la macro no la usa |
| 148 | Días Vencido | `dias_vencido` | `days_past_due` | Se guarda para diagnóstico; la macro no la usa |
| 149 | Garantía Líquida | — | — | No se usa (no se importa) |
| 150 | Comentario | — | — | Dato personal — **no se importa** |
| 151 | Etiquetas de solicitud | — | — | No se usa (no se importa) |
| 152 | Etiquetas de contrato | — | — | No se usa (no se importa) |
| 153 | Adquisición de bienes | — | — | No se usa (no se importa) |
| 154 | Dispersado | — | — | No se usa (no se importa) |
| 155 | Fecha dispersión | — | — | No se usa (no se importa) |
| 156 | Fecha dispersión | — | — | No se usa (no se importa) |

Resumen: 22 columnas de cálculo, 5 de diagnóstico, 1 de validación de fecha, 17 con datos personales que no se importan y 111 sin uso.

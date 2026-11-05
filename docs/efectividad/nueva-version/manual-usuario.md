# Manual de uso — Efectividad de Cobranza

Para usuarios con rol **admin** o **gerencial**. Un colaborador no tiene acceso a este
módulo.

Este manual explica la **calculadora vigente** (versión V12.9.4). El procedimiento anterior,
con ejecuciones en segundo plano, se conserva al final como [anexo](#anexo--flujo-anterior-ejecuciones-en-segundo-plano).

## 1. Qué archivos se necesitan

| Archivo | De dónde sale |
|---|---|
| **Saldos por Cliente** (cartera) de cada día | Lendus, descargado al cierre de cada día |
| **Ingresos Cobranza** que cubra el periodo | Lendus |

Cuántas carteras pide cada modalidad:

| Modalidad | Qué mide | Carteras |
|---|---|---|
| **Diario** | La cobranza de un solo día | Solo **2**: la del día anterior y la del día elegido |
| **Acumulado** | Del lunes al día elegido | Del domingo anterior al día elegido |
| **Semanal** | Lunes a domingo | Las **8**: domingo base, lunes a sábado y domingo de corte |
| **Mensual** | Suma de las semanas completas del mes | Las 8 carteras de cada semana |

Además, los Ingresos Cobranza deben cubrir todos los días que se miden.

**Recomendado:** agrega también los Ingresos Cobranza de las semanas anteriores. Algunos
clientes adelantan pagos; esos anticipos solo se descuentan de la meta si el sistema ve
el pago. Si no los cargas, el resultado lo avisa y la meta puede quedar ligeramente más
alta.

## 2. Cómo calcular

1. Abre **Efectividad de Cobranza**.
2. **Modalidad**: elige Diario, Acumulado, Semanal o Mensual.
3. **Cargar archivos**: sube las carteras y los Ingresos Cobranza (puedes agregar más en
   cualquier momento). El sistema reconoce solo el tipo y la fecha de cada archivo.
4. **Elegir corte y calcular**: cada corte posible muestra qué archivos tiene y cuáles
   faltan. Solo se puede calcular un corte completo.
5. Revisa el resultado, filtra por **Sucursal → Ruta → Promotor** y descarga PNG, PDF o
   Excel (el Excel requiere permiso).

Los archivos se guardan solo de forma temporal para ese cálculo; no se mezclan con otros
módulos.

## 3. Cómo leer el reporte

### 3.1 Columnas

| Grupo | Columna | Qué significa |
|---|---|---|
| **Sano** | Por cobrar | La **meta** del periodo: lo que vencía en el periodo y seguía pendiente al empezar. Si el cliente ya había adelantado parte de la cuota, solo cuenta lo que faltaba. |
| | Cobrado | Lo cobrado de esas cuotas a tiempo |
| | % Aporte | Cobrado sano / **Sano por cobrar** |
| **Vigente con atraso** | Por cobrar | Referencia de control: lo cobrado de atraso + lo que sigue atrasado al corte |
| | Cobrado | Lo cobrado de cuotas que ya estaban atrasadas |
| | % Aporte | Cobrado de atraso / **Sano por cobrar** |
| **Vencida** | Por cobrar | Referencia de control: lo cobrado de cartera vencida + lo que sigue vencido al corte |
| | Cobrado | Lo cobrado de contratos ya vencidos |
| | % Aporte | Cobrado vencido / **Sano por cobrar** |
| **Cobranza total** | Cobrado | Sano + atraso + vencido cobrado |
| | % Efectividad | Cobranza total / **Sano por cobrar** (= suma de los tres % Aporte) |
| **Mora** | | Sano por cobrar − Cobranza total |

Puntos clave:

- **Todos los porcentajes se calculan contra el Sano por cobrar.** Por eso se llaman
  *% Aporte*: dicen cuánto aporta cada tipo de cobranza a la meta, y los tres suman la
  efectividad.
- Los *Por cobrar* de atraso y vencida **no** se suman a la meta; solo sirven de control.
- No se cobra doble: una deuda que pasa de sana a atrasada no genera una meta nueva.
- Una cuota que vence hoy sigue siendo sana hasta que termina el día.
- Solo cuentan capital, interés e IVA. Seguros, coberturas, condonaciones y moratorios no
  son cobranza.

### 3.2 Casos que pueden sorprender

- **Porcentaje vacío.** Si un gestor no tenía meta (Sano por cobrar en cero), no hay base
  para calcular un porcentaje: la celda queda vacía, no en 0 %. Si ese gestor sí cobró
  atrasos o vencidos, aparece en la tabla y **su cobranza sí suma** a los totales.
- **Efectividad mayor a 100 %.** Es posible y correcto: pasa cuando, además de la meta, se
  recuperan atrasos o cartera vencida. No hay tope.
- **Mora negativa.** Si se cobró más que la meta, la mora sale negativa: indica que la
  meta se superó, no un error.
- **Cambio de gestor en la semana.** Solo lo que faltaba por cobrar pasa al nuevo gestor;
  lo ya cobrado se queda con quien lo cobró.
- **Refinanciamientos.** Las cuotas futuras canceladas por un refinanciamiento no se exigen
  ni cuentan como cobranza; solo se respeta el abono real que el cliente hizo antes.

### 3.3 Estado del reporte: PRELIMINAR o CONCILIADO

Junto al título aparece una insignia:

- **CONCILIADO**: toda la meta tiene respaldo directo en los archivos y la cartera de
  cierre cuadra.
- **PRELIMINAR**: el cálculo está completo, pero hay puntos que conviene revisar **antes
  de pagar comisiones**: metas estimadas a partir del saldo de cartera, contratos que
  salieron de cartera sin evidencia de pago o diferencias al cierre.

PRELIMINAR no significa que las cifras estén mal; significa que alguna parte se dedujo y
debe confirmarse.

### 3.4 Panel "Control y trazabilidad"

Muestra el detalle de esas revisiones:

| Renglón | Qué indica |
|---|---|
| Contratos ajustados | Contratos cuya meta se ajustó con evidencia de los archivos |
| Cuotas con meta inferida / Importe inferido | Metas estimadas por el saldo de cartera, sin cuota explícita |
| Contratos con diferencias al cierre / Diferencia sin asignar | El saldo final no cuadra con la cartera inicial y los pagos; la diferencia **no** se reparte |
| Desapariciones sin soporte / Saldo sin soporte | Contratos que salieron de cartera sin pago que lo respalde; **no** se asumen liquidados |
| Cierres de refinanciamiento comprobados | Contratos cerrados por refinanciamiento con evidencia completa |

Los renglones marcados para revisar son los que dejan el reporte en PRELIMINAR. Nada de lo
que aparece aquí cambia las cifras de la tabla.

### 3.5 Avisos

- *No se cargaron ingresos anteriores al periodo*: agrega los Ingresos Cobranza de semanas
  previas para descontar anticipos (ver §1).
- *Rutas fuera del catálogo*: se muestran en "SIN SUCURSAL ASIGNADA"; sus importes sí se
  calculan.

---

## Anexo — flujo anterior (ejecuciones en segundo plano)

Procedimiento de la versión anterior (V4), que se conserva para los reportes ya generados.

### Antes de empezar: qué archivos se necesitan

Para una **semana operativa** (lunes a domingo):

| Archivo | Cuántos | Dónde se carga |
|---|---|---|
| **Saldos por Cliente** de Lendus, descargado **cada día** | 8: domingo anterior (domingo base), lunes, martes, miércoles, jueves, viernes, sábado y domingo (corte) | Efectividad de Cobranza › Disponibilidad de información |
| **Ingresos Cobranza** de Lendus (formato completo de 35 columnas, con *Fecha transacción*, *Fecha cuota* y *Oficina*) | El que cubra la semana (puede ser el del mes) | Histórico General, en el **mes operativo** que contiene la semana |

Recomendado: cargar también los Ingresos de la semana anterior. Sin ellos no se reconocen
los anticipos pagados antes de la semana.

La semana debe existir en **Periodos**, como periodo semanal lunes-domingo.

### A.1 Cargar las fotografías diarias

1. Abre **Efectividad de Cobranza**.
2. Elige la **Semana operativa** (y la sucursal, si aplica).
3. En **Disponibilidad de información** aparece una tarjeta por día: *Domingo base*,
   *Lunes* … *Domingo corte*, cada una con su fecha.
4. En la tarjeta de cada día pulsa **Cargar fotografía** y elige el Excel de Saldos por
   Cliente **de ese día**. La fecha la pone la tarjeta; no hay que escribirla.
5. La tarjeta pasa a **Procesando** y luego a **Disponible**, con el número de contratos y
   oficinas. Si muestra **Error al procesar**, lee el motivo en la misma tarjeta. Los más
   comunes:
   - *Fecha incompatible*: el archivo tiene transacciones posteriores a ese día. Lo más
     probable es que corresponda a otro día.
   - *Columnas obligatorias faltantes*: el archivo no es el Saldos por Cliente completo.
6. **Reemplazar** sustituye la fotografía de ese día. La anterior no se borra: queda en el
   historial como sustituida. Los reportes ya generados con ella muestran
   «Obsoleto — cambiaron fotografías o ingresos después de generarlo».

Reglas importantes:

- El sistema **no inventa** fotografías. Si falta un día, no se puede generar la semana.
- El mismo archivo no puede usarse para dos días distintos.
- La fotografía del domingo de corte sirve también como domingo base de la semana
  siguiente.

### A.2 Cargar Ingresos Cobranza

Como siempre: **Histórico General** › mes operativo › fuente *Ingresos Cobranza* › subir
y analizar. Si después agregas o reprocesas Ingresos, los reportes ya generados de esas
fechas muestran el mismo aviso de obsoleto.

### A.3 Revisar la disponibilidad

En **Disponibilidad de información** revisa que:

- Las 8 tarjetas estén en **Disponible**.
- El resumen de Ingresos muestre movimientos en los días de la semana.
- No haya avisos críticos.

Si los Ingresos tienen pocos días (3 o menos) o empiezan después del lunes, el sistema
pedirá **confirmar que la semana está completa** antes de generar. Así lo exige la
calculadora V4.

### A.4 Generar el reporte

1. Elige el **Modo**:
   - **Semanal (lunes a domingo)**: el reporte de la semana completa.
   - **Diario (un día de la semana)**: elige además la **Fecha de corte**. Solo se usan los
     Ingresos y las cuotas de ese día, con las fotografías desde el domingo base hasta ese
     día.
2. Pulsa **Generar reporte**. El cálculo corre en segundo plano en **9 etapas**:
   validación de fotografías e ingresos, verificación de encabezados, preparación de las
   fotografías, procesamiento de ingresos, cálculo, comparación de totales, auditoría de
   incidencias, generación de Excel y finalización.
3. Puedes salir a otro módulo o cerrar el navegador. Al volver, la página recupera la
   ejecución desde el servidor. Al terminar recibes una **notificación interna** (campana)
   y un **correo**.

Si pides el mismo reporte mientras ya se está generando, no se duplica: quedas suscrito a
esa ejecución.

### A.5 Resultados

- Tabla A-M por oficina y promotor: Sano (por cobrar, cobrado, %), Vigentes con atraso,
  Vencidos con atraso, Cobranza total y Mora. Incluye un total por oficina y el
  **GRAN TOTAL**.
- Todos los porcentajes son sobre el **Sano por cobrar**.
- **Descargar Excel**: hoja `EFECTIVIDAD` (con el control de corte en P-Q),
  `_AUDITORIA_EFECTIVIDAD` y `FUENTES` (fotografías e ingresos usados).

### A.6 Incidencias

La sección de incidencias lista los movimientos que la calculadora excluyó y su motivo:
`NO-CARTERA`, `CUOTA`, `DUP`, `GRP`, `FOTO-DUP`, `PROM` y `FECHA`. Cada una trae una
**acción recomendada**. Puedes filtrar por código o contrato y **Exportar CSV**, que abre
directo en Excel.

Ninguna incidencia se "corrige" forzando el movimiento al cálculo.

### A.7 Estados de una ejecución

| Estado | Qué significa |
|---|---|
| En cola / Procesando / Reintentando / Retrasado / Sin actividad del worker | Se está generando, o el servidor de colas no lo ha tomado (los dos últimos los recupera el sistema automáticamente cada 10 min) |
| Terminado | Resultado vigente y Excel disponible |
| Bloqueado por datos | Faltan datos. El mensaje indica qué fotografía o archivo y su fecha. No es un 0%. |
| Error | Falla técnica. Vuelve a intentar; si persiste, avisa a sistemas con el número de ejecución |
| «Obsoleto — cambiaron fotografías o ingresos después de generarlo» | Cambió alguna fotografía o los Ingresos después de generarlo; el resultado sigue visible pero ya no es vigente. Genera de nuevo |
| Obsoleto | Hay una versión más reciente del mismo reporte, o se generó con la calculadora anterior |

### A.8 Historial y permisos

- **Admin**: ve todas las ejecuciones y puede filtrar por solicitante.
- **Gerencial**: ve las ejecuciones que solicitó o a las que quedó suscrito.

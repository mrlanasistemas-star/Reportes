# Manual de uso — Efectividad de Cobranza V4

Para usuarios con rol **admin** o **gerencial**. Un colaborador no tiene acceso a este
módulo.

## Antes de empezar: qué archivos se necesitan

Para una **semana operativa** (lunes a domingo):

| Archivo | Cuántos | Dónde se carga |
|---|---|---|
| **Saldos por Cliente** de Lendus, descargado **cada día** | 8: domingo anterior (domingo base), lunes, martes, miércoles, jueves, viernes, sábado y domingo (corte) | Efectividad de Cobranza › Disponibilidad de información |
| **Ingresos Cobranza** de Lendus (formato completo de 35 columnas, con *Fecha transacción*, *Fecha cuota* y *Oficina*) | El que cubra la semana (puede ser el del mes) | Histórico General, en el **mes operativo** que contiene la semana |

Recomendado: cargar también los Ingresos de la semana anterior. Sin ellos no se reconocen
los anticipos pagados antes de la semana.

La semana debe existir en **Periodos**, como periodo semanal lunes-domingo.

## 1. Cargar las fotografías diarias

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

## 2. Cargar Ingresos Cobranza

Como siempre: **Histórico General** › mes operativo › fuente *Ingresos Cobranza* › subir
y analizar. Si después agregas o reprocesas Ingresos, los reportes ya generados de esas
fechas muestran el mismo aviso de obsoleto.

## 3. Revisar la disponibilidad

En **Disponibilidad de información** revisa que:

- Las 8 tarjetas estén en **Disponible**.
- El resumen de Ingresos muestre movimientos en los días de la semana.
- No haya avisos críticos.

Si los Ingresos tienen pocos días (3 o menos) o empiezan después del lunes, el sistema
pedirá **confirmar que la semana está completa** antes de generar. Así lo exige la
calculadora V4.

## 4. Generar el reporte

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

## 5. Resultados

- Tabla A-M por oficina y promotor: Sano (por cobrar, cobrado, %), Vigentes con atraso,
  Vencidos con atraso, Cobranza total y Mora. Incluye un total por oficina y el
  **GRAN TOTAL**.
- Todos los porcentajes son sobre el **Sano por cobrar**.
- **Descargar Excel**: hoja `EFECTIVIDAD` (con el control de corte en P-Q),
  `_AUDITORIA_EFECTIVIDAD` y `FUENTES` (fotografías e ingresos usados).

## 6. Incidencias

La sección de incidencias lista los movimientos que la calculadora excluyó y su motivo:
`NO-CARTERA`, `CUOTA`, `DUP`, `GRP`, `FOTO-DUP`, `PROM` y `FECHA`. Cada una trae una
**acción recomendada**. Puedes filtrar por código o contrato y **Exportar CSV**, que abre
directo en Excel.

Ninguna incidencia se "corrige" forzando el movimiento al cálculo.

## 7. Estados de una ejecución

| Estado | Qué significa |
|---|---|
| En cola / Procesando / Reintentando / Retrasado / Sin actividad del worker | Se está generando, o el servidor de colas no lo ha tomado (los dos últimos los recupera el sistema automáticamente cada 10 min) |
| Terminado | Resultado vigente y Excel disponible |
| Bloqueado por datos | Faltan datos. El mensaje indica qué fotografía o archivo y su fecha. No es un 0%. |
| Error | Falla técnica. Vuelve a intentar; si persiste, avisa a sistemas con el número de ejecución |
| «Obsoleto — cambiaron fotografías o ingresos después de generarlo» | Cambió alguna fotografía o los Ingresos después de generarlo; el resultado sigue visible pero ya no es vigente. Genera de nuevo |
| Obsoleto | Hay una versión más reciente del mismo reporte, o se generó con la calculadora anterior |

## 8. Historial y permisos

- **Admin**: ve todas las ejecuciones y puede filtrar por solicitante.
- **Gerencial**: ve las ejecuciones que solicitó o a las que quedó suscrito.

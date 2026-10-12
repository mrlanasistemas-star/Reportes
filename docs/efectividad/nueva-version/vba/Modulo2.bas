Attribute VB_Name = "Módulo2"
Option Explicit
 
' ================================================================
' MR LANA | Complemento para la calculadora de efectividad
' Hoja origen: EFECTIVIDAD
' Contenido exportado: columnas A:M y filas del reporte generado
' NO modifica los calculos ni las hojas de CARTERA e INGRESOS.
' ================================================================
 
' Encuentra el reporte que ya fue generado por la macro principal.
' Prioriza la fila GRAN TOTAL para no capturar filas decorativas.
Private Function ObtenerRangoEfectividad() As Range
    Dim ws As Worksheet
    Dim celUltima As Range
    Dim celTotal As Range
    Dim filaFinal As Long
 
    Set ws = ThisWorkbook.Worksheets("EFECTIVIDAD")
 
    Set celUltima = ws.Range("A:M").Find( _
        What:="*", _
        After:=ws.Range("A1"), _
        LookIn:=xlValues, _
        LookAt:=xlPart, _
        SearchOrder:=xlByRows, _
        SearchDirection:=xlPrevious, _
        MatchCase:=False)
 
    If celUltima Is Nothing Then Exit Function
    filaFinal = celUltima.Row
 
    Set celTotal = ws.Columns("A").Find( _
        What:="GRAN TOTAL", _
        After:=ws.Range("A1"), _
        LookIn:=xlValues, _
        LookAt:=xlPart, _
        SearchOrder:=xlByRows, _
        SearchDirection:=xlPrevious, _
        MatchCase:=False)
 
    If Not celTotal Is Nothing Then
        If celTotal.Row >= 6 Then filaFinal = celTotal.Row
    End If
 
    ' Evita exportar encabezados vacios cuando aun no hay reporte.
    If filaFinal < 6 Then Exit Function
    If Application.WorksheetFunction.CountA(ws.Range("A6:M" & filaFinal)) = 0 Then Exit Function
 
    Set ObtenerRangoEfectividad = ws.Range("A1:M" & filaFinal)
End Function
 
' Boton 1: GUARDA UNA IMAGEN PNG REAL PARA WHATSAPP.
' El archivo se crea en Descargas de Windows con fecha y hora.
Public Sub Generar_Imagen_Para_WhatsApp()
    Dim rango As Range
    Dim ws As Worksheet
    Dim objetoGrafico As ChartObject
    Dim hojaAnterior As Worksheet
    Dim carpeta As String
    Dim archivo As String
    Dim exportado As Boolean
    Dim detalleError As String
 
    On Error GoTo ManejarError
 
    Set rango = ObtenerRangoEfectividad()
    If rango Is Nothing Then
        MsgBox "Primero ejecuta CALCULA EFECTIVIDAD para generar el reporte.", _
               vbExclamation, "MR LANA"
        Exit Sub
    End If
 
    Set ws = rango.Worksheet
    Set hojaAnterior = ActiveSheet
 
    carpeta = Environ$("USERPROFILE") & "\Downloads"
    If Len(Dir$(carpeta, vbDirectory)) = 0 Then
        carpeta = Environ$("TEMP")
    End If
    If Len(carpeta) = 0 Then
        Err.Raise vbObjectError + 513, , "No se encontro una carpeta de destino."
    End If
 
    archivo = carpeta & "\MR_LANA_EFECTIVIDAD_" & _
              Format$(Now, "yyyymmdd_hhnnss") & ".png"
 
    ' CopyPicture reproduce el formato visible de Excel.
    ' Excel de escritorio debe estar abierto y la hoja visible.
    Application.ScreenUpdating = True
    ws.Activate
    rango.CopyPicture Appearance:=xlScreen, Format:=xlPicture
    DoEvents
 
    ' Se utiliza un grafico temporal solamente como exportador PNG.
    ' Se coloca fuera de A:M, por lo que no forma parte del reporte.
    Set objetoGrafico = ws.ChartObjects.Add( _
        Left:=ws.Cells(2, 16).Left, _
        Top:=ws.Cells(2, 16).Top, _
        Width:=rango.Width, _
        Height:=rango.Height)
 
    objetoGrafico.Activate
    objetoGrafico.Chart.Paste
    DoEvents
 
    exportado = objetoGrafico.Chart.Export( _
        Filename:=archivo, FilterName:="PNG")
 
    If Not exportado Then
        Err.Raise vbObjectError + 514, , "Excel no pudo exportar el PNG."
    End If
 
    objetoGrafico.Delete
    Set objetoGrafico = Nothing
    Application.CutCopyMode = False
    hojaAnterior.Activate
 
    MsgBox "Imagen generada correctamente." & vbCrLf & vbCrLf & _
           "Archivo: " & archivo & vbCrLf & vbCrLf & _
           "Adjuntala como imagen en el grupo de WhatsApp.", _
           vbInformation, "MR LANA | Reporte listo"
    Exit Sub
 
ManejarError:
    detalleError = Err.Description
    On Error Resume Next
    If Not objetoGrafico Is Nothing Then objetoGrafico.Delete
    Application.CutCopyMode = False
    If Not hojaAnterior Is Nothing Then hojaAnterior.Activate
    On Error GoTo 0
    MsgBox "No se pudo generar la imagen." & vbCrLf & _
           "Detalle: " & detalleError & vbCrLf & vbCrLf & _
           "Verifica que el reporte este calculado y Excel este visible.", _
           vbExclamation, "MR LANA"
End Sub
 
' Boton 2 (opcional): COPIA EL REPORTE COMO IMAGEN AL PORTAPAPELES.
' En WhatsApp Web se puede intentar pegar con Ctrl+V.
' Para una entrega consistente se recomienda el boton que guarda PNG.
Public Sub Copiar_Reporte_Como_Imagen()
    Dim rango As Range
    Dim hojaAnterior As Worksheet
 
    On Error GoTo ManejarError
    Set rango = ObtenerRangoEfectividad()
    If rango Is Nothing Then
        MsgBox "Primero ejecuta CALCULA EFECTIVIDAD.", vbExclamation, "MR LANA"
        Exit Sub
    End If
 
    Set hojaAnterior = ActiveSheet
    Application.ScreenUpdating = True
    rango.Worksheet.Activate
    rango.CopyPicture Appearance:=xlScreen, Format:=xlBitmap
 
    MsgBox "El reporte esta copiado como imagen." & vbCrLf & _
           "Abre WhatsApp Web y prueba pegarlo con Ctrl+V.", _
           vbInformation, "MR LANA"
    Exit Sub
 
ManejarError:
    MsgBox "No fue posible copiar el reporte: " & Err.Description, _
           vbExclamation, "MR LANA"
End Sub



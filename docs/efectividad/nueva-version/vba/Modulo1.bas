Attribute VB_Name = "Módulo1"
Option Explicit

' MR LANA - EFECTIVIDAD DINAMICA - V4 (Octubre 2026)
' AJUSTE V4: auditoria con carteras de CORDOBA (sin filtrar por ciudad fija).
' Valida contratos contra fotografias para aislar historiales ajenos.
' Calcula vencido desde Total atrasado y estado del corte,
' clasifica pagos usando atraso/vencido al dia de reconocimiento.
' Módulo completo para reemplazar el modulo principal anterior.
' Conservar INTACTO el modulo Generar_Imagen_Para_WhatsApp.
'
' HOJAS VISIBLES (encabezados del core SIEMPRE en fila 1):
' CARTERA DOMINGO, CARTERA LUNES, CARTERA MARTES,
' CARTERA MIERCOLES, CARTERA JUEVES, CARTERA VIERNES,
' CARTERA SABADO, CARTERA DOMINGO CORTE, INGRESOS,
' EFECTIVIDAD.
'
' CONTROL (hoja EFECTIVIDAD):
' Q2 = DIARIO / SEMANAL
' Q3 = domingo anterior (fecha real, por ejemplo 04/10/2026)
' Q4 = fecha de corte (05/10 a 11/10/2026)
'
' REGLA CLAVE: el cobro por atraso o vencida subsidia el sano.
' EFECTIVIDAD = (sano cobrado + atraso cobrado + vencido cobrado)
'             / sano programado dinamico del periodo.
'
' Los pagos de refinanciamiento/descuento reconocidos son
' EXCLUSIVAMENTE los de la semana en que ocurrio el refinanciamiento
' y en la FECHA EXIGIBLE o fecha posterior de transaccion.
'
' El saldo en VIGENTE CON ATRASO y VENCIDO proviene EXCLUSIVAMENTE
' de la fotografia del corte; no se suman saldos diarios.

Public Sub Crear_Hojas_Efectividad_Dinamica()
    ' No elimina ni reemplaza datos de otras hojas.
    Dim nombres As Variant, n As Variant, ws As Worksheet
    nombres = Array("CARTERA DOMINGO", "CARTERA LUNES", _
        "CARTERA MARTES", "CARTERA MIERCOLES", "CARTERA JUEVES", _
        "CARTERA VIERNES", "CARTERA SABADO", _
        "CARTERA DOMINGO CORTE", "INGRESOS", "EFECTIVIDAD")
    For Each n In nombres
        Set ws = ObtenerHoja(CStr(n))
        If ws Is Nothing Then
            Set ws = ThisWorkbook.Worksheets.Add(After:= _
                ThisWorkbook.Worksheets(ThisWorkbook.Worksheets.Count))
            ws.Name = CStr(n)
        End If
    Next n
    Preparar_Configuracion_Efectividad
End Sub

Public Sub Preparar_Configuracion_Efectividad()
    Dim ws As Worksheet
    Set ws = ObtenerHoja("EFECTIVIDAD")
    If ws Is Nothing Then
        MsgBox "No se encuentra EFECTIVIDAD.", vbExclamation: Exit Sub
    End If
    ws.Range("P1:Q1").Merge
    ws.Range("P1").Value = "CONTROL DE CORTE | MR LANA"
    ws.Range("P2").Value = "MODO"
    ws.Range("P3").Value = "DOMINGO BASE"
    ws.Range("P4").Value = "FECHA CORTE"
    If Trim$(CStr(ws.Range("Q2").Value)) = "" Then ws.Range("Q2").Value = "SEMANAL"
    ws.Range("Q3:Q4").NumberFormat = "dd/mm/yyyy"
    ws.Range("P1:Q1").Interior.Color = RGB(0, 0, 0)
    ws.Range("P1:Q1").Font.Color = RGB(160, 224, 78)
    ws.Range("P1:Q4").Font.Name = "Montserrat"
    ws.Range("P1:P4").Font.Bold = True
    ws.Columns("P").ColumnWidth = 22
    ws.Columns("Q").ColumnWidth = 20
    On Error Resume Next
    ws.Range("Q2").Validation.Delete
    ws.Range("Q2").Validation.Add Type:=xlValidateList, _
        AlertStyle:=xlValidAlertStop, Formula1:="DIARIO,SEMANAL"
    On Error GoTo 0
    MsgBox "El periodo se detectara automaticamente desde INGRESOS al calcular.", _
        vbInformation, "MR LANA"
End Sub

Public Sub Generar_Efectividad_Cobranza()
    Dim wsEf As Worksheet, wsIng As Worksheet, wsHist As Worksheet
    Dim fBase As Date, fIni As Date, fFin As Date
    Dim fCorte As Date, fRepIni As Date, fRepFin As Date
    Dim modo As String, nombres As Variant, i As Long, pasos As Long
    Dim car As Worksheet, dRutaPromo As Object, dGrupos As Object
    Dim dOficina As Object, dPromotor As Object, dNombreGrupo As Object
    Dim dContratoGrupo As Object, dContratoVenc As Object
    Dim dPrimeraFoto As Object, dFirmaContrato As Object
    Dim dCuotaMonto As Object, dCuotaGrupo As Object
    Dim dEstadoDia As Object, dAtrasosDia As Object
    Dim dObservados As Object, dExcluidos As Object
    Dim dStockAtraso As Object, dStockVencido As Object
    Dim dSanoPC As Object, dAtrasoPC As Object, dVencidoPC As Object
    Dim dCobSano As Object, dCobAtraso As Object, dCobVencido As Object
    Dim dIncidencias As Object, dCobroUnico As Object
    Dim k As Variant, g As String, sw As Boolean
    Dim calcAnterior As XlCalculation
    Dim alertsAnterior As Boolean, eventsAnterior As Boolean, screenAnterior As Boolean
    Dim msg As String
    On Error GoTo ManejarError

    Set wsEf = ObtenerHoja("EFECTIVIDAD")
    Set wsIng = ObtenerHoja("INGRESOS")
    If wsEf Is Nothing Or wsIng Is Nothing Then
        Err.Raise vbObjectError + 900, , "Falta EFECTIVIDAD o INGRESOS."
    End If
    MR_AutoconfigurarCorte wsIng, wsEf
    If Not LeerFecha(wsEf.Range("Q3").Value, fBase) Then
        Err.Raise vbObjectError + 901, , _
            "Captura en EFECTIVIDAD!Q3 el DOMINGO BASE, como fecha de Excel."
    End If
    If Weekday(fBase, vbMonday) <> 7 Then
        Err.Raise vbObjectError + 902, , "EFECTIVIDAD!Q3 debe ser domingo."
    End If
    If Not LeerFecha(wsEf.Range("Q4").Value, fCorte) Then
        Err.Raise vbObjectError + 903, , "Captura en Q4 la FECHA CORTE."
    End If
    modo = Normalizar(wsEf.Range("Q2").Value)
    If modo <> "DIARIO" And modo <> "SEMANAL" Then
        Err.Raise vbObjectError + 904, , "Q2 debe decir DIARIO o SEMANAL."
    End If
    fIni = DateAdd("d", 1, fBase)
    fFin = DateAdd("d", 7, fBase)
    If fCorte < fIni Or fCorte > fFin Then
        Err.Raise vbObjectError + 905, , _
            "Q4 debe estar entre " & Format$(fIni, "dd/mm/yyyy") & _
            " y " & Format$(fFin, "dd/mm/yyyy")
    End If
    If modo = "SEMANAL" And fCorte <> fFin Then
        Err.Raise vbObjectError + 906, , _
            "El corte SEMANAL debe ser el domingo siguiente al domingo base."
    End If
    If modo = "DIARIO" Then
        fRepIni = fCorte: fRepFin = fCorte
    Else
        fRepIni = fIni: fRepFin = fFin
    End If

    nombres = Array("CARTERA DOMINGO", "CARTERA LUNES", "CARTERA MARTES", _
      "CARTERA MIERCOLES", "CARTERA JUEVES", "CARTERA VIERNES", _
      "CARTERA SABADO", "CARTERA DOMINGO CORTE")
    pasos = DateDiff("d", fBase, fCorte)
    For i = 0 To pasos
        Set car = ObtenerHoja(CStr(nombres(i)))
        If car Is Nothing Then
            Err.Raise vbObjectError + 907, , "Falta " & CStr(nombres(i))
        End If
        If ColumnaEncabezado(car, "Contrato") = 0 Then
            Err.Raise vbObjectError + 908, , _
                "Encabezados invalidos en " & CStr(nombres(i)) & _
                ". Elimina las primeras 4 filas originales del exportado."
        End If
        If car.Cells(car.Rows.Count, ColumnaEncabezado(car, "Contrato")).End(xlUp).Row < 2 Then
            Err.Raise vbObjectError + 909, , "Cartera sin datos: " & CStr(nombres(i))
        End If
    Next i
    If ColumnaEncabezado(wsIng, "Contrato") = 0 Then
        Err.Raise vbObjectError + 910, , _
            "Encabezados invalidos en INGRESOS. Elimina 3 filas originales."
    End If
    ValidarFechasIngresos wsIng, fRepIni, fRepFin

    screenAnterior = Application.ScreenUpdating
    eventsAnterior = Application.EnableEvents
    alertsAnterior = Application.DisplayAlerts
    calcAnterior = Application.Calculation
    sw = True
    Application.ScreenUpdating = False
    Application.EnableEvents = False
    Application.DisplayAlerts = False
    Application.Calculation = xlCalculationManual

    Set dRutaPromo = CrearMapaResponsableRuta(wsIng, fRepIni, fRepFin)
    ComplementarResponsables _
        ObtenerHoja(CStr(nombres(pasos))), dRutaPromo
    Set dGrupos = CreateObject("Scripting.Dictionary")
    Set dOficina = CreateObject("Scripting.Dictionary")
    Set dPromotor = CreateObject("Scripting.Dictionary")
    Set dNombreGrupo = CreateObject("Scripting.Dictionary")
    Set dContratoGrupo = CreateObject("Scripting.Dictionary")
    Set dContratoVenc = CreateObject("Scripting.Dictionary")
    Set dPrimeraFoto = CreateObject("Scripting.Dictionary")
    Set dFirmaContrato = CreateObject("Scripting.Dictionary")
    Set dCuotaMonto = CreateObject("Scripting.Dictionary")
    Set dCuotaGrupo = CreateObject("Scripting.Dictionary")
    Set dEstadoDia = CreateObject("Scripting.Dictionary")
    Set dAtrasosDia = CreateObject("Scripting.Dictionary")
    Set dObservados = CreateObject("Scripting.Dictionary")
    Set dExcluidos = CreateObject("Scripting.Dictionary")
    Set dIncidencias = CreateObject("Scripting.Dictionary")
    Set dStockAtraso = CreateObject("Scripting.Dictionary")
    Set dStockVencido = CreateObject("Scripting.Dictionary")
    Set dSanoPC = CreateObject("Scripting.Dictionary")
    Set dAtrasoPC = CreateObject("Scripting.Dictionary")
    Set dVencidoPC = CreateObject("Scripting.Dictionary")
    Set dCobSano = CreateObject("Scripting.Dictionary")
    Set dCobAtraso = CreateObject("Scripting.Dictionary")
    Set dCobVencido = CreateObject("Scripting.Dictionary")
    Set dCobroUnico = CreateObject("Scripting.Dictionary")

    ' Foto del domingo base y evolucion diaria sin acumular stocks.
    For i = 0 To pasos
        Set car = ObtenerHoja(CStr(nombres(i)))
        dStockAtraso.RemoveAll
        dStockVencido.RemoveAll
        ProcesarFoto car, DateAdd("d", i, fBase), fIni, fFin, _
            dRutaPromo, dGrupos, dOficina, dPromotor, dNombreGrupo, _
            dContratoGrupo, dContratoVenc, dPrimeraFoto, dFirmaContrato, _
            dCuotaMonto, dCuotaGrupo, dEstadoDia, dAtrasosDia, _
            dObservados, dExcluidos, dStockAtraso, dStockVencido, _
            dIncidencias
    Next i

    ' Denominador diario: solo las cuotas cuyo vencimiento es ese dia.
    ' Denominador semanal: todas las cuotas exigibles de lunes a domingo.
    For Each k In dCuotaMonto.Keys
        If FechaDeClave(CStr(k)) >= fRepIni And _
           FechaDeClave(CStr(k)) <= fRepFin Then
            g = CStr(dCuotaGrupo(k))
            SumarDic dSanoPC, g, CDbl(dCuotaMonto(k))
        End If
    Next k
    For Each k In dStockAtraso.Keys
        g = GrupoContrato(Split(CStr(k), "#")(0), dContratoGrupo)
        SumarDic dAtrasoPC, g, CDbl(dStockAtraso(k))
    Next k
    For Each k In dStockVencido.Keys
        g = GrupoContrato(Split(CStr(k), "#")(0), dContratoGrupo)
        SumarDic dVencidoPC, g, CDbl(dStockVencido(k))
    Next k

    ' Historial conserva anticipos ordinarios de otras semanas.
    ' Al importar DIARIO solo reemplaza los movimientos de ese dia.
    ' Al importar SEMANAL reemplaza toda la semana cargada.
    Set wsHist = ActualizarHistorialIngresos(wsIng, fRepIni, fRepFin, modo)
    ProcesarCobros wsHist, fIni, fFin, fRepIni, fRepFin, _
        dRutaPromo, dGrupos, dOficina, dPromotor, dNombreGrupo, _
        dContratoGrupo, dContratoVenc, dEstadoDia, dAtrasosDia, _
        dObservados, dExcluidos, dCuotaMonto, _
        dCobSano, dCobAtraso, dCobVencido, _
        dCobroUnico, dIncidencias

    GenerarReporte wsEf, fRepIni, fRepFin, dGrupos, dOficina, _
        dPromotor, dSanoPC, dAtrasoPC, dVencidoPC, _
        dCobSano, dCobAtraso, dCobVencido
    GenerarBitacora dIncidencias, fBase, fCorte

    msg = "EFECTIVIDAD " & modo & " generada: " & _
        Format$(fRepIni, "dd/mm/yyyy") & " a " & _
        Format$(fRepFin, "dd/mm/yyyy") & vbCrLf & _
        "Incidencias por revisar: " & dIncidencias.Count & vbCrLf & _
        "Consulta _AUDITORIA_EFECTIVIDAD si necesitas validar excepciones."
    MsgBox msg, vbInformation, "MR LANA"

Salida:
    If sw Then
        Application.Calculation = calcAnterior
        Application.DisplayAlerts = alertsAnterior
        Application.EnableEvents = eventsAnterior
        Application.ScreenUpdating = screenAnterior
    End If
    Exit Sub
ManejarError:
    MsgBox "No se genero el reporte." & vbCrLf & _
        "Error " & Err.Number & ": " & Err.Description, vbCritical, "MR LANA"
    Resume Salida
End Sub


' ==============================================================
' MR LANA V3 - AUTOCONFIGURACION RECUPERABLE DEL CORTE
' Reconstruye Q2:Q6 desde INGRESOS en CADA ejecucion.
' Si un gerente borra EFECTIVIDAD, el control vuelve a aparecer.
' No altera las cuotas, cobros, substatus ni reglas de cartera.
' ==============================================================
Private Sub MR_AutoconfigurarCorte(ByVal wsIng As Worksheet, _
    ByVal wsEf As Worksheet)
    Dim cFecha As Long, cContrato As Long, ultima As Long, r As Long
    Dim f As Date, fMin As Date, fMax As Date
    Dim fLunes As Date, fBaseAuto As Date, fCorteAuto As Date
    Dim modoAuto As String, clave As String, respuesta As VbMsgBoxResult
    Dim fechas As Object, nMov As Long, respuestaTexto As String
    Dim fSinMov As Date

    Set fechas = CreateObject("Scripting.Dictionary")
    cFecha = ColumnaEncabezado(wsIng, "Fecha transaccion", "Fecha transacción")
    cContrato = ColumnaEncabezado(wsIng, "Contrato")
    If cFecha = 0 Or cContrato = 0 Then
        Err.Raise vbObjectError + 980, , _
            "INGRESOS debe tener Contrato y Fecha transaccion en la fila 1."
    End If

    ultima = wsIng.Cells(wsIng.Rows.Count, cContrato).End(xlUp).Row
    For r = 2 To ultima
        If Trim$(CStr(wsIng.Cells(r, cContrato).Value)) <> "" Then
            If Not LeerFecha(wsIng.Cells(r, cFecha).Value, f) Then
                Err.Raise vbObjectError + 981, , _
                    "Fecha invalida en INGRESOS, fila " & CStr(r)
            End If
            If nMov = 0 Then
                fMin = f
                fMax = f
            Else
                If f < fMin Then fMin = f
                If f > fMax Then fMax = f
            End If
            nMov = nMov + 1
            clave = Format$(f, "yyyymmdd")
            If Not fechas.Exists(clave) Then fechas.Add clave, True
        End If
    Next r

    If nMov = 0 Then
        ' Una hoja sin movimientos NO permite inferir por si sola el dia.
        respuestaTexto = InputBox( _
            "INGRESOS no tiene movimientos. Captura la fecha de corte (dd/mm/aaaa)." & vbCrLf & _
            "La calculadora no usara una fecha vieja por error.", _
            "MR LANA | CORTE SIN MOVIMIENTOS")
        If Not MR_LeerFechaMexicana(respuestaTexto, fSinMov) Then
            Err.Raise vbObjectError + 982, , _
                "Sin movimientos se necesita una fecha de corte valida."
        End If
        respuesta = MsgBox("¿El corte SIN MOVIMIENTOS es SEMANAL?" & vbCrLf & _
            "SI = semana completa; NO = un solo dia; CANCELAR = no calcular.", _
            vbYesNoCancel + vbQuestion, "MR LANA | TIPO DE CORTE")
        If respuesta = vbCancel Then
            Err.Raise vbObjectError + 983, , "Se cancelo el calculo sin movimientos."
        End If
        fMin = fSinMov
        fMax = fSinMov
        If respuesta = vbYes Then
            modoAuto = "SEMANAL"
        Else
            modoAuto = "DIARIO"
        End If
    ElseIf fechas.Count = 1 Then
        modoAuto = "DIARIO"
    Else
        modoAuto = "SEMANAL"
    End If

    ' La semana operativa va SIEMPRE de lunes a domingo.
    fLunes = DateAdd("d", 1 - Weekday(fMax, vbMonday), fMax)
    fBaseAuto = DateAdd("d", -1, fLunes)
    If fMin < fLunes Then
        Err.Raise vbObjectError + 984, , _
            "INGRESOS mezcla semanas. Descarga solo lunes a domingo " & _
            "de una misma semana (o un solo dia para DIARIO)."
    End If

    If modoAuto = "SEMANAL" Then
        fCorteAuto = DateAdd("d", 6, fLunes)
        If nMov > 0 And (fechas.Count <= 3 Or fMin > fLunes) Then
            respuesta = MsgBox( _
                "INGRESOS contiene movimientos solo entre " & _
                Format$(fMin, "dd/mm/yyyy") & " y " & _
                Format$(fMax, "dd/mm/yyyy") & "." & vbCrLf & _
                "¿Confirmas que descargaste la SEMANA COMPLETA del " & _
                Format$(fLunes, "dd/mm/yyyy") & " al " & _
                Format$(fCorteAuto, "dd/mm/yyyy") & "?" & vbCrLf & _
                "NO cancela el reporte para evitar calcular una semana incompleta.", _
                vbYesNo + vbExclamation, "MR LANA | CONFIRMAR SEMANA")
            If respuesta <> vbYes Then
                Err.Raise vbObjectError + 985, , _
                    "Semana no confirmada: revisa el archivo de INGRESOS."
            End If
        End If
    Else
        fCorteAuto = fMax
    End If

    ' Rehacer siempre el control, aunque el usuario haya borrado Q2:Q4.
    wsEf.Range("P1").Value = "CONTROL DE CORTE AUTOMATICO | MR LANA"
    wsEf.Range("P2").Value = "MODO"
    wsEf.Range("P3").Value = "DOMINGO BASE"
    wsEf.Range("P4").Value = "FECHA DE CORTE"
    wsEf.Range("P5").Value = "INICIO DE INGRESOS"
    wsEf.Range("P6").Value = "FIN DE INGRESOS"
    wsEf.Range("P7").Value = "CONTROL RESTAURADO AL CALCULAR"
    wsEf.Range("Q2").Value = modoAuto
    wsEf.Range("Q3").Value = fBaseAuto
    wsEf.Range("Q4").Value = fCorteAuto
    If modoAuto = "SEMANAL" Then
        wsEf.Range("Q5").Value = fLunes
        wsEf.Range("Q6").Value = fCorteAuto
    Else
        wsEf.Range("Q5").Value = fMax
        wsEf.Range("Q6").Value = fMax
    End If
    wsEf.Range("Q3:Q6").NumberFormat = "dd/mm/yyyy"
    wsEf.Range("P1:Q7").Font.Name = "Montserrat"
    wsEf.Range("P1:P7").Font.Bold = True
    wsEf.Columns("P").ColumnWidth = 35
    wsEf.Columns("Q").ColumnWidth = 26

    ' La opcion manual de la version previa ya no puede mantener
    ' una configuracion vieja de otra semana.
    wsEf.Range("Q9").Value = "AUTO"
End Sub

Private Function MR_LeerFechaMexicana(ByVal texto As String, _
    ByRef f As Date) As Boolean
    Dim partes As Variant, dd As Long, mm As Long, aaaa As Long
    On Error GoTo FechaInvalida
    partes = Split(Trim$(texto), "/")
    If UBound(partes) <> 2 Then GoTo FechaInvalida
    If Not IsNumeric(partes(0)) Or Not IsNumeric(partes(1)) Or _
       Not IsNumeric(partes(2)) Then GoTo FechaInvalida
    dd = CLng(partes(0))
    mm = CLng(partes(1))
    aaaa = CLng(partes(2))
    If aaaa < 2000 Or aaaa > 2100 Then GoTo FechaInvalida
    f = DateSerial(aaaa, mm, dd)
    If Year(f) <> aaaa Or Month(f) <> mm Or Day(f) <> dd Then _
        GoTo FechaInvalida
    MR_LeerFechaMexicana = True
    Exit Function
FechaInvalida:
    MR_LeerFechaMexicana = False
End Function

Private Sub ValidarFechasIngresos(ByVal ws As Worksheet, _
    ByVal fInicio As Date, ByVal fFinal As Date)
    Dim cFecha As Long, cContrato As Long, r As Long, fin As Long, f As Date
    Dim n As Long
    cFecha = ColumnaEncabezado(ws, "Fecha transaccion", "Fecha transacción")
    cContrato = ColumnaEncabezado(ws, "Contrato")
    If cFecha = 0 Or cContrato = 0 Then
        Err.Raise vbObjectError + 911, , "INGRESOS sin fecha o contrato."
    End If
    fin = ws.Cells(ws.Rows.Count, cContrato).End(xlUp).Row
    For r = 2 To fin
        If Trim$(CStr(ws.Cells(r, cContrato).Value)) <> "" Then
            If LeerFecha(ws.Cells(r, cFecha).Value, f) Then
                If f < fInicio Or f > fFinal Then
                    Err.Raise vbObjectError + 912, , _
                        "INGRESOS incluye fechas fuera del corte en fila " & r & _
                        ". Carga solo el dia o semana indicados."
                End If
                n = n + 1
            Else
                Err.Raise vbObjectError + 913, , _
                    "Fecha de transaccion incorrecta en INGRESOS fila " & r
            End If
        End If
    Next r
    ' Cero movimientos tambien es un corte diario legitimo.
End Sub

Private Sub ComplementarResponsables(ByVal ws As Worksheet, _
    ByVal mapa As Object)
    ' Si hoy no hubo cobros, usar el promotor predominante en
    ' contratos ACTIVOS de la ultima fotografia disponible.
    Dim cOf As Long, cProm As Long, cEst As Long, cCt As Long
    Dim f As Long, r As Long, ruta As String, prom As String
    Dim est As String, clave As String, k As Variant, mayor As Object
    Dim conteo As Object, real As Object
    Set mayor = CreateObject("Scripting.Dictionary")
    Set conteo = CreateObject("Scripting.Dictionary")
    Set real = CreateObject("Scripting.Dictionary")
    cOf = ColumnaEncabezado(ws, "Oficina")
    cProm = ColumnaEncabezado(ws, "Nombre promotor")
    cEst = ColumnaEncabezado(ws, "Estatus")
    cCt = ColumnaEncabezado(ws, "Contrato")
    If cOf = 0 Or cProm = 0 Or cCt = 0 Then Exit Sub
    f = ws.Cells(ws.Rows.Count, cCt).End(xlUp).Row
    For r = 2 To f
        ruta = Normalizar(ws.Cells(r, cOf).Value)
        prom = Trim$(CStr(ws.Cells(r, cProm).Value))
        est = ""
        If cEst > 0 Then est = Normalizar(ws.Cells(r, cEst).Value)
        If ruta <> "" And prom <> "" And _
            InStr(est, "INACTIVO") = 0 And InStr(est, "CERRADO") = 0 Then
            clave = ruta & "|" & Normalizar(prom)
            If Not conteo.Exists(clave) Then
                conteo.Add clave, CLng(0)
                real.Add clave, prom
            End If
            conteo(clave) = CLng(conteo(clave)) + 1
        End If
    Next r
    For Each k In conteo.Keys
        ruta = Split(CStr(k), "|")(0)
        If Not mayor.Exists(ruta) Then mayor.Add ruta, CLng(-1)
        If CLng(conteo(k)) > CLng(mayor(ruta)) Then
            mayor(ruta) = CLng(conteo(k))
            real(ruta & "|ELEGIDO") = CStr(real(k))
        End If
    Next k
    For Each k In mayor.Keys
        If Not mapa.Exists(CStr(k)) Then
            mapa.Add CStr(k), CStr(real(CStr(k) & "|ELEGIDO"))
        End If
    Next k
End Sub

Private Function FechaDeClave(ByVal clave As String) As Date
    Dim s As String
    s = Right$(clave, 8)
    FechaDeClave = DateSerial(CInt(Left$(s, 4)), _
        CInt(Mid$(s, 5, 2)), CInt(Right$(s, 2)))
End Function

Private Function GrupoContrato(ByVal claveContrato As String, _
    ByVal dContratoGrupo As Object) As String
    If dContratoGrupo.Exists(claveContrato) Then
        GrupoContrato = CStr(dContratoGrupo(claveContrato))
    Else
        GrupoContrato = ""
    End If
End Function

Private Sub Incidencia(ByVal d As Object, ByVal clave As String, _
    ByVal descripcion As String)
    If Not d.Exists(clave) Then d.Add clave, descripcion
End Sub

Private Function MovimientoExcluido(ByVal texto As String) As Boolean
    Dim s As String
    s = Normalizar(texto)
    MovimientoExcluido = (InStr(s, "REESTRUCT") > 0 Or _
        InStr(s, "UNIFICAC") > 0 Or InStr(s, "CONDONAC") > 0 Or _
        InStr(s, "COBERTURA") > 0)
End Function

Private Sub DescartarCuotasFuturas(ByVal identidad As String, _
    ByVal fFoto As Date, ByVal dMonto As Object, ByVal dGrupo As Object)
    Dim claves As Variant, k As Variant
    claves = dMonto.Keys
    For Each k In claves
        If Left$(CStr(k), Len(identidad) + 1) = identidad & "|" Then
            If FechaDeClave(CStr(k)) >= fFoto Then
                dMonto.Remove CStr(k)
                dGrupo.Remove CStr(k)
            End If
        End If
    Next k
End Sub

Private Sub ProcesarFoto(ByVal ws As Worksheet, ByVal fFoto As Date, _
    ByVal fSemanaIni As Date, ByVal fSemanaFin As Date, _
    ByVal dRutaPromo As Object, ByVal dGrupos As Object, _
    ByVal dOficina As Object, ByVal dPromotor As Object, _
    ByVal dNombreGrupo As Object, ByVal dContratoGrupo As Object, _
    ByVal dContratoVenc As Object, ByVal dPrimeraFoto As Object, _
    ByVal dFirmaContrato As Object, ByVal dCuotaMonto As Object, _
    ByVal dCuotaGrupo As Object, _
    ByVal dEstadoDia As Object, ByVal dAtrasosDia As Object, _
    ByVal dObservados As Object, ByVal dExcluidos As Object, _
    ByVal dStockAtraso As Object, ByVal dStockVencido As Object, _
    ByVal dIncidencias As Object)

    Dim cc As Long, co As Long, cp As Long, cs As Long, ce As Long
    Dim cProd As Long, cOrigen As Long, cAcred As Long
    Dim cFPrim As Long, cVenc As Long
    Dim cSig As Long, cCuota As Long, cPeriod As Long, cPagos As Long
    Dim cAtr As Long, cAtraPagos As Long, cPen As Long
    Dim cSaldoCap As Long, cSaldoInt As Long
    Dim cSaldoImp As Long, cSaldoCar As Long, cSaldoImpCar As Long
    Dim ultima As Long, r As Long
    Dim contrato As String, oficina As String, prom As String, grp As String
    Dim sube As String, est As String, k As String, ident As String
    Dim firma As String, cred As String, acreditado As String
    Dim fPrimer As Date, fMatura As Date, fSig As Date
    Dim tienePrimer As Boolean, tieneVenc As Boolean
    Dim cuota As Double, pendiente As Double, atraso As Double, vencido As Double
    Dim componentes As Boolean, periodicidad As String, pagos As Long
    Dim sumaComponentes As Double, fechaClave As String
    Dim activa As Boolean, excluido As Boolean
    Dim dFotoIdent As Object
    Set dFotoIdent = CreateObject("Scripting.Dictionary")

    cc = ColumnaEncabezado(ws, "Contrato")
    co = ColumnaEncabezado(ws, "Oficina")
    cp = ColumnaEncabezado(ws, "Nombre promotor", "Nombre del promotor")
    cs = ColumnaEncabezado(ws, "Substatus", "Subestatus")
    ce = ColumnaEncabezado(ws, "Estatus")
    cProd = ColumnaEncabezado(ws, "Producto de credito", "Producto de crédito")
    cAcred = ColumnaEncabezado(ws, "Acreditado")
    cOrigen = ColumnaEncabezado(ws, "Origen credito", "Origen crédito")
    cFPrim = ColumnaEncabezado(ws, "Fecha primer pago")
    cVenc = ColumnaEncabezado(ws, "Fecha vencimiento")
    cSig = ColumnaEncabezado(ws, "Fecha siguiente pago")
    cCuota = ColumnaEncabezado(ws, "Cuota total")
    cPeriod = ColumnaEncabezado(ws, "Periodicidad")
    cPagos = ColumnaEncabezado(ws, "Pagos")
    cAtr = ColumnaEncabezado(ws, "Total atrasado")
    cAtraPagos = ColumnaEncabezado(ws, "Pagos atrasados")
    cPen = ColumnaEncabezado(ws, "Total pendiente")
    cSaldoCap = ColumnaEncabezado(ws, "Saldo capital")
    cSaldoInt = ColumnaEncabezado(ws, "Saldo interes", "Saldo interés")
    cSaldoImp = ColumnaEncabezado(ws, "Saldo impuesto")
    cSaldoCar = ColumnaEncabezado(ws, "Saldo cargos calendario")
    cSaldoImpCar = ColumnaEncabezado(ws, "Saldo impuesto cargos calendario")
    If co = 0 Or cp = 0 Or cFPrim = 0 Or cVenc = 0 Or _
       cCuota = 0 Or cPeriod = 0 Or cPagos = 0 Or cs = 0 Then
        Err.Raise vbObjectError + 920, , _
            "Columnas obligatorias faltantes en " & ws.Name
    End If
    ultima = ws.Cells(ws.Rows.Count, cc).End(xlUp).Row
    For r = 2 To ultima
        contrato = Trim$(CStr(ws.Cells(r, cc).Value))
        If contrato = "" Then GoTo SigFoto
        k = Normalizar(contrato)
        acreditado = ""
        If cAcred > 0 Then acreditado = Normalizar(ws.Cells(r, cAcred).Value)
        ident = k & "#" & acreditado
        If dFotoIdent.Exists(ident) Then
            Incidencia dIncidencias, "FOTO-DUP|" & ws.Name & "|" & ident, _
                "Contrato/acreditado repetido en " & ws.Name & ": " & contrato
            GoTo SigFoto
        End If
        dFotoIdent.Add ident, True
        dObservados(k) = True  ' Evidencia de contrato en alguna fotografia
        oficina = Trim$(CStr(ws.Cells(r, co).Value))
        If oficina = "" Then GoTo SigFoto
        prom = Trim$(CStr(ws.Cells(r, cp).Value))
        prom = ObtenerPromotorRuta(oficina, prom, dRutaPromo)
        If prom = "" Then
            Incidencia dIncidencias, "PROM|" & k, _
                "Promotor no identificado: " & contrato & ", " & ws.Name
            GoTo SigFoto
        End If
        grp = RegistrarGrupo(oficina, prom, dGrupos, dOficina, _
            dPromotor, dNombreGrupo)
        If Not dContratoGrupo.Exists(k) Then dContratoGrupo.Add k, grp
        est = "": sube = "": cred = ""
        If ce > 0 Then est = Normalizar(ws.Cells(r, ce).Value)
        If cs > 0 Then sube = Normalizar(ws.Cells(r, cs).Value)
        If cProd > 0 Then cred = Normalizar(ws.Cells(r, cProd).Value)
        excluido = MovimientoExcluido(cred)
        ' "Origen credito = REFINANCIAMIENTO" es normal: NO excluir.
        If excluido Then dExcluidos(k) = True
        activa = (InStr(est, "INACTIVO") = 0 And _
            InStr(est, "CERRADO") = 0 And InStr(sube, "INACTIVO") = 0)
        fechaClave = ident & "|" & Format$(fFoto, "yyyymmdd")
        dEstadoDia(fechaClave) = sube
        atraso = 0
        If cAtr > 0 Then atraso = Positivo(ws.Cells(r, cAtr).Value)
        If cAtraPagos > 0 Then
            If Numero(ws.Cells(r, cAtraPagos).Value) > 0 And atraso <= 0 Then
                atraso = 0.01 ' Bandera: existen cuotas atrasadas, sin importe util
            End If
        End If
        dAtrasosDia(fechaClave) = atraso
        tieneVenc = LeerFecha(ws.Cells(r, cVenc).Value, fMatura)
        If tieneVenc Then dContratoVenc(k) = fMatura
        If excluido Or Not activa Then GoTo SigFoto
        If InStr(sube, "VENCIDO") > 0 Then
            ' LENDUS: en VENCIDO puede mostrar Total pendiente = 0,
            ' aunque Total atrasado y los saldos siguen siendo exigibles.
            ' Total atrasado manda; saldo por componentes es respaldo.
            vencido = 0
            If cAtr > 0 Then vencido = Positivo(ws.Cells(r, cAtr).Value)
            componentes = (cSaldoCap > 0 Or cSaldoInt > 0 Or _
                cSaldoImp > 0 Or cSaldoCar > 0 Or cSaldoImpCar > 0)
            sumaComponentes = 0
            If cSaldoCap > 0 Then sumaComponentes = sumaComponentes + _
                Numero(ws.Cells(r, cSaldoCap).Value)
            If cSaldoInt > 0 Then sumaComponentes = sumaComponentes + _
                Numero(ws.Cells(r, cSaldoInt).Value)
            If cSaldoImp > 0 Then sumaComponentes = sumaComponentes + _
                Numero(ws.Cells(r, cSaldoImp).Value)
            If cSaldoCar > 0 Then sumaComponentes = sumaComponentes + _
                Numero(ws.Cells(r, cSaldoCar).Value)
            If cSaldoImpCar > 0 Then sumaComponentes = sumaComponentes + _
                Numero(ws.Cells(r, cSaldoImpCar).Value)
            If vencido <= 0.01 And componentes Then
                If sumaComponentes > 0 Then vencido = sumaComponentes
            End If
            If vencido <= 0.01 And cPen > 0 Then
                vencido = Positivo(ws.Cells(r, cPen).Value)
            End If
            If vencido > 0.01 Then dStockVencido(ident) = vencido
        Else
            If atraso > 0.01 Then dStockAtraso(ident) = atraso
        End If
        ' Contratos VENCIDOS de inicio no crean nuevas cuotas sanas.
        If InStr(sube, "VENCIDO") > 0 Then GoTo SigFoto
        cuota = Positivo(ws.Cells(r, cCuota).Value)
        If cuota <= 0 Then GoTo SigFoto
        pagos = CLng(Numero(ws.Cells(r, cPagos).Value))
        If pagos <= 0 Then GoTo SigFoto
        periodicidad = Trim$(CStr(ws.Cells(r, cPeriod).Value))
        tienePrimer = LeerFecha(ws.Cells(r, cFPrim).Value, fPrimer)
        If Not tienePrimer And cSig > 0 Then
            tienePrimer = LeerFecha(ws.Cells(r, cSig).Value, fSig)
            If tienePrimer Then fPrimer = fSig
        End If
        If Not tienePrimer Then
            Incidencia dIncidencias, "FECHA|" & k, _
                "Contrato sin fecha primer pago: " & contrato
            GoTo SigFoto
        End If
        If Not dPrimeraFoto.Exists(ident) Then dPrimeraFoto.Add ident, fFoto
        firma = Format$(fPrimer, "yyyymmdd") & "|" & _
            CStr(pagos) & "|" & Normalizar(periodicidad) & "|" & _
            Format$(cuota, "0.0000")
        If tieneVenc Then firma = firma & "|" & Format$(fMatura, "yyyymmdd")
        If dFirmaContrato.Exists(ident) Then
            If CStr(dFirmaContrato(ident)) <> firma Then
                DescartarCuotasFuturas ident, fFoto, dCuotaMonto, dCuotaGrupo
            End If
            dFirmaContrato(ident) = firma
        Else
            dFirmaContrato.Add ident, firma
        End If
        ProgramarCuotas ident, grp, fPrimer, fMatura, tieneVenc, _
            periodicidad, pagos, cuota, fFoto, fSemanaIni, fSemanaFin, _
            dCuotaMonto, dCuotaGrupo
SigFoto:
    Next r
End Sub

Private Sub ProgramarCuotas(ByVal k As String, ByVal grp As String, _
    ByVal fPrim As Date, ByVal fMatura As Date, _
    ByVal tieneVenc As Boolean, ByVal periodicidad As String, _
    ByVal pagos As Long, ByVal cuota As Double, ByVal fFoto As Date, _
    ByVal fIni As Date, ByVal fFin As Date, _
    ByVal dCuotaMonto As Object, ByVal dCuotaGrupo As Object)
    Dim n As Long, f As Date, sig As Date, key As String
    f = fPrim
    For n = 1 To pagos
        If tieneVenc Then
            If f > fMatura Then Exit For
        End If
        If f > fFin Then Exit For
        If f >= fIni Then
            key = k & "|" & Format$(f, "yyyymmdd")
            If Not dCuotaMonto.Exists(key) Then
                dCuotaMonto.Add key, cuota
                dCuotaGrupo.Add key, grp
            ElseIf f >= fFoto Then
                ' Cambios contractuales aplican solo prospectivamente.
                dCuotaMonto(key) = cuota
            End If
        End If
        sig = SiguienteFechaCuota(f, periodicidad)
        If sig <= f Then Exit For
        f = sig
    Next n
End Sub

Private Sub ProcesarCobros(ByVal ws As Worksheet, _
    ByVal fSemanaIni As Date, ByVal fSemanaFin As Date, _
    ByVal fRepIni As Date, ByVal fRepFin As Date, _
    ByVal dRutaPromo As Object, ByVal dGrupos As Object, _
    ByVal dOficina As Object, ByVal dPromotor As Object, _
    ByVal dNombreGrupo As Object, ByVal dContratoGrupo As Object, _
    ByVal dContratoVenc As Object, ByVal dEstadoDia As Object, _
    ByVal dAtrasosDia As Object, ByVal dObservados As Object, _
    ByVal dExcluidos As Object, ByVal dCuotaMonto As Object, _
    ByVal dCobSano As Object, ByVal dCobAtraso As Object, _
    ByVal dCobVencido As Object, ByVal dUnicos As Object, _
    ByVal dIncidencias As Object)

    Dim cOf As Long, cProm As Long, cContrato As Long, cOp As Long
    Dim cTr As Long, cFTrans As Long, cFCuota As Long, cFVen As Long
    Dim cCap As Long, colInteres As Long, cIva As Long, cCargo As Long
    Dim cProducto As Long, cConcepto As Long, cNumCuota As Long
    Dim cAcred As Long
    Dim r As Long, ultima As Long, importe As Double
    Dim op As String, tr As String, ct As String, grp As String
    Dim ofic As String, prom As String, k As String, ident As String
    Dim acreditado As String, claveDedup As String
    Dim keyCuota As String, status As String, esRef As Boolean
    Dim fTrans As Date, fCuota As Date, fRec As Date, fVen As Date
    Dim tieneVen As Boolean, bVenc As Boolean, bAtraso As Boolean
    Dim fFotoEstado As Date, llaveEstado As String, atrasoDia As Double

    cOf = ColumnaEncabezado(ws, "Oficina")
    cProm = ColumnaEncabezado(ws, "Promotor", "Nombre promotor")
    cContrato = ColumnaEncabezado(ws, "Contrato")
    cOp = ColumnaEncabezado(ws, "Operacion", "Operación")
    cTr = ColumnaEncabezado(ws, "Transaccion", "Transacción")
    cFTrans = ColumnaEncabezado(ws, "Fecha transaccion", "Fecha transacción")
    cFCuota = ColumnaEncabezado(ws, "Fecha cuota")
    cFVen = ColumnaEncabezado(ws, "Fecha vencimiento")
    cCap = ColumnaEncabezado(ws, "Capital")
    colInteres = ColumnaEncabezado(ws, "Interes", "Interés")
    cIva = ColumnaEncabezado(ws, "Impuesto")
    cCargo = ColumnaEncabezado(ws, "Cargos calendario")
    cProducto = ColumnaEncabezado(ws, "Producto de credito", "Producto de crédito")
    cConcepto = ColumnaEncabezado(ws, "Concepto")
    cNumCuota = ColumnaEncabezado(ws, "Numero cuota", "Número cuota")
    cAcred = ColumnaEncabezado(ws, "Acreditado")
    If cOf = 0 Or cContrato = 0 Or cOp = 0 Or cTr = 0 Or _
       cFTrans = 0 Or cFCuota = 0 Or cCap = 0 Or colInteres = 0 Or cIva = 0 Then
        Err.Raise vbObjectError + 940, , "Encabezados faltantes en INGRESOS/historial."
    End If
    ultima = ws.Cells(ws.Rows.Count, cContrato).End(xlUp).Row
    For r = 2 To ultima
        ct = Trim$(CStr(ws.Cells(r, cContrato).Value))
        If ct = "" Then GoTo SigCobro
        k = Normalizar(ct)
        acreditado = ""
        If cAcred > 0 Then acreditado = Normalizar(ws.Cells(r, cAcred).Value)
        ident = k & "#" & acreditado
        op = Normalizar(ws.Cells(r, cOp).Value)
        tr = Normalizar(ws.Cells(r, cTr).Value)
        esRef = (op = "REFINANCIAMIENTO" And tr = "DESCUENTO")
        If Not esRef And Not (op = "PAGO A CONTRATO" And tr = "PAGO") Then GoTo SigCobro
        If dExcluidos.Exists(k) Then GoTo SigCobro
        If cProducto > 0 Then
            If MovimientoExcluido(CStr(ws.Cells(r, cProducto).Value)) Then GoTo SigCobro
        End If
        If cConcepto > 0 Then
            If MovimientoExcluido(CStr(ws.Cells(r, cConcepto).Value)) Then GoTo SigCobro
        End If
        If Not LeerFecha(ws.Cells(r, cFTrans).Value, fTrans) Then GoTo SigCobro
        If Not LeerFecha(ws.Cells(r, cFCuota).Value, fCuota) Then GoTo SigCobro
        importe = Numero(ws.Cells(r, cCap).Value) + _
            Numero(ws.Cells(r, colInteres).Value) + Numero(ws.Cells(r, cIva).Value)
        If cCargo > 0 Then importe = importe + Numero(ws.Cells(r, cCargo).Value)
        If importe <= 0 Then GoTo SigCobro
        fRec = fCuota
        If fTrans > fCuota Then fRec = fTrans
        If esRef Then
            ' Renovacion: solo vencimientos de LA MISMA semana de transaccion.
            If fTrans < fSemanaIni Or fTrans > fSemanaFin Then GoTo SigCobro
            If fCuota < fSemanaIni Or fCuota > fSemanaFin Then GoTo SigCobro
        End If
        If fRec < fRepIni Or fRec > fRepFin Then GoTo SigCobro

        ' No mezclar anticipos de otra sucursal desde _HIST_INGRESOS.
        ' No se fija CORDOBA en el codigo: vale para cualquier sucursal.
        If Not dObservados.Exists(k) Then
            Incidencia dIncidencias, "NO-CARTERA|" & k & "|" & CStr(r), _
                "Contrato sin respaldo en fotos del periodo: " & ct & _
                "; validar antes de contabilizar anticipo/pago."
            GoTo SigCobro
        End If

        ofic = Trim$(CStr(ws.Cells(r, cOf).Value))
        prom = ""
        If cProm > 0 Then prom = Trim$(CStr(ws.Cells(r, cProm).Value))
        If dContratoGrupo.Exists(k) Then
            grp = CStr(dContratoGrupo(k))
        Else
            prom = ObtenerPromotorRuta(ofic, prom, dRutaPromo)
            If ofic = "" Or prom = "" Then
                Incidencia dIncidencias, "GRP|" & k & "|" & CStr(r), _
                    "Ingreso sin responsable de ruta: " & ct
                GoTo SigCobro
            End If
            grp = RegistrarGrupo(ofic, prom, dGrupos, dOficina, _
                dPromotor, dNombreGrupo)
            dContratoGrupo.Add k, grp
        End If
        keyCuota = ident & "|" & Format$(fCuota, "yyyymmdd")
        If Not dCuotaMonto.Exists(keyCuota) Then
            If dCuotaMonto.Exists(k & "#|" & Format$(fCuota, "yyyymmdd")) Then
                keyCuota = k & "#|" & Format$(fCuota, "yyyymmdd")
            End If
        End If
        claveDedup = ident & "|" & Format$(fTrans, "yyyymmdd") & "|" & _
            Format$(fCuota, "yyyymmdd") & "|" & op & "|" & tr & "|" & _
            Format$(importe, "0.0000")
        If cNumCuota > 0 Then claveDedup = claveDedup & "|" & _
            CStr(ws.Cells(r, cNumCuota).Value)
        If dUnicos.Exists(claveDedup) Then
            Incidencia dIncidencias, "DUP|" & claveDedup, _
                "Movimiento identico duplicado, contrato " & ct
            GoTo SigCobro
        End If
        dUnicos.Add claveDedup, True
        status = "": atrasoDia = 0
        ' Usar foto del dia de RECONOCIMIENTO; si un credito desaparecio
        ' por cierre/reno, buscar la ultima foto previa, NO una futura.
        ' El domingo base aporta estado para reconocer el lunes.
        fFotoEstado = fRec
        Do While fFotoEstado >= DateAdd("d", -1, fSemanaIni)
            llaveEstado = ident & "|" & Format$(fFotoEstado, "yyyymmdd")
            If Not dEstadoDia.Exists(llaveEstado) Then
                llaveEstado = k & "#|" & Format$(fFotoEstado, "yyyymmdd")
            End If
            If dEstadoDia.Exists(llaveEstado) Then
                status = CStr(dEstadoDia(llaveEstado))
                If dAtrasosDia.Exists(llaveEstado) Then
                    atrasoDia = CDbl(dAtrasosDia(llaveEstado))
                End If
                Exit Do
            End If
            fFotoEstado = DateAdd("d", -1, fFotoEstado)
        Loop
        tieneVen = False
        If cFVen > 0 Then tieneVen = LeerFecha(ws.Cells(r, cFVen).Value, fVen)
        If Not tieneVen And dContratoVenc.Exists(k) Then
            fVen = CDate(dContratoVenc(k)): tieneVen = True
        End If
        bVenc = (InStr(status, "VENCIDO") > 0)
        If Not bVenc And tieneVen Then
            If fRec > fVen Then bVenc = True
        End If
        bAtraso = (atrasoDia > 0.001)

        ' Las cuotas de un credito YA VENCIDO pueden recuperarse sin
        ' tener cuotas sanas programadas; nunca excluir ese dinero.
        ' Para uno vigente, exigir respaldo de su cuota actual.
        If fTrans <= fCuota And fCuota >= fSemanaIni And _
           fCuota <= fSemanaFin And Not dCuotaMonto.Exists(keyCuota) Then
            If Not bVenc Then
                Incidencia dIncidencias, "CUOTA|" & k & "|" & CStr(r), _
                    "Pago sin cuota programada verificable: " & ct & _
                    ", cuota " & Format$(fCuota, "dd/mm/yyyy")
                GoTo SigCobro
            End If
        End If
        If bVenc Then
            SumarDic dCobVencido, grp, importe
        ElseIf bAtraso Or fTrans > fCuota Then
            SumarDic dCobAtraso, grp, importe
        Else
            SumarDic dCobSano, grp, importe
        End If
SigCobro:
    Next r
End Sub

Private Sub GenerarBitacora(ByVal dIncidencias As Object, _
    ByVal fBase As Date, ByVal fCorte As Date)
    Dim ws As Worksheet, k As Variant, r As Long
    On Error Resume Next
    Set ws = ThisWorkbook.Worksheets("_AUDITORIA_EFECTIVIDAD")
    On Error GoTo 0
    If ws Is Nothing Then
        Set ws = ThisWorkbook.Worksheets.Add(After:= _
            ThisWorkbook.Worksheets(ThisWorkbook.Worksheets.Count))
        ws.Name = "_AUDITORIA_EFECTIVIDAD"
    End If
    ws.Cells.Clear
    ws.Range("A1").Value = "INCIDENCIAS DE CALCULO | MR LANA"
    ws.Range("A2").Value = "DOMINGO BASE"
    ws.Range("B2").Value = fBase
    ws.Range("A3").Value = "CORTE"
    ws.Range("B3").Value = fCorte
    ws.Range("B2:B3").NumberFormat = "dd/mm/yyyy"
    ws.Range("A5").Value = "ID"
    ws.Range("B5").Value = "DETALLE / MOTIVO DE EXCLUSION"
    r = 6
    For Each k In dIncidencias.Keys
        ws.Cells(r, 1).Value = CStr(k)
        ws.Cells(r, 2).Value = CStr(dIncidencias(k))
        r = r + 1
    Next k
    ws.Columns("A").ColumnWidth = 38
    ws.Columns("B").ColumnWidth = 90
    ws.Range("A1:B5").Font.Bold = True
    ws.Range("A1:B5").Font.Name = "Montserrat"
    ws.Visible = xlSheetVeryHidden
End Sub


Private Function MR_Verde() As Long
    MR_Verde = RGB(160, 224, 78)
End Function

Private Function MR_Negro() As Long
    MR_Negro = RGB(0, 0, 0)
End Function

Private Function MR_Blanco() As Long
    MR_Blanco = RGB(255, 255, 255)
End Function

Private Function COB_AzulOscuro() As Long
    COB_AzulOscuro = RGB(18, 54, 86)
End Function

Private Function COB_Verde() As Long
    COB_Verde = RGB(70, 168, 143)
End Function

Private Function COB_Amarillo() As Long
    COB_Amarillo = RGB(255, 229, 152)
End Function

Private Function COB_Rojo() As Long
    COB_Rojo = RGB(192, 0, 0)
End Function

Private Function COB_Azul() As Long
    COB_Azul = RGB(24, 93, 123)
End Function

Private Function SEM_VerdeFondo() As Long
    SEM_VerdeFondo = RGB(198, 239, 206)
End Function

Private Function SEM_VerdeTexto() As Long
    SEM_VerdeTexto = RGB(0, 97, 0)
End Function

Private Function SEM_AmarilloFondo() As Long
    SEM_AmarilloFondo = RGB(255, 235, 156)
End Function

Private Function SEM_AmarilloTexto() As Long
    SEM_AmarilloTexto = RGB(156, 101, 0)
End Function

Private Function SEM_RojoFondo() As Long
    SEM_RojoFondo = RGB(255, 199, 206)
End Function

Private Function SEM_RojoTexto() As Long
    SEM_RojoTexto = RGB(156, 0, 6)
End Function

Private Function MORA_VerdeTexto() As Long
    MORA_VerdeTexto = RGB(0, 128, 0)
End Function

Private Function MORA_RojoTexto() As Long
    MORA_RojoTexto = RGB(192, 0, 0)
End Function

Private Function ObtenerHoja(ByVal nombre As String) As Worksheet

    On Error Resume Next
    Set ObtenerHoja = ThisWorkbook.Worksheets(nombre)
    On Error GoTo 0

End Function


' ==============================================================
' NORMALIZAR
' ==============================================================

Private Function Normalizar(ByVal valor As Variant) As String

    Dim s As String

    If IsError(valor) Then
        Normalizar = ""
        Exit Function
    End If

    s = UCase(Trim(CStr(valor)))

    s = Replace(s, Chr(160), " ")
    s = Replace(s, vbCr, " ")
    s = Replace(s, vbLf, " ")

    s = Replace(s, "Á", "A")
    s = Replace(s, "É", "E")
    s = Replace(s, "Í", "I")
    s = Replace(s, "Ó", "O")
    s = Replace(s, "Ú", "U")
    s = Replace(s, "Ü", "U")
    s = Replace(s, "Ñ", "N")

    Do While InStr(s, "  ") > 0
        s = Replace(s, "  ", " ")
    Loop

    Normalizar = s

End Function


' ==============================================================
' ENCABEZADO
' ==============================================================

Private Function ColumnaEncabezado( _
    ByVal ws As Worksheet, _
    ParamArray nombres() As Variant) As Long

    Dim ultimaCol As Long
    Dim c As Long
    Dim i As Long
    Dim texto As String

    ultimaCol = _
        ws.Cells(1, ws.Columns.Count).End(xlToLeft).Column

    For c = 1 To ultimaCol

        texto = Normalizar(ws.Cells(1, c).Value)

        If texto <> "" Then

            For i = LBound(nombres) To UBound(nombres)

                If texto = Normalizar(nombres(i)) Then

                    ColumnaEncabezado = c
                    Exit Function

                End If

            Next i

        End If

    Next c

    ColumnaEncabezado = 0

End Function


' ==============================================================
' NÚMERO
' ==============================================================

Private Function Numero(ByVal valor As Variant) As Double

    Dim s As String

    If IsError(valor) Or IsEmpty(valor) Then
        Numero = 0
        Exit Function
    End If

    If IsNumeric(valor) Then
        Numero = CDbl(valor)
        Exit Function
    End If

    s = Trim(CStr(valor))

    If s = "" Then
        Numero = 0
        Exit Function
    End If

    s = Replace(s, "$", "")
    s = Replace(s, ",", "")
    s = Replace(s, " ", "")

    If Left(s, 1) = "(" And Right(s, 1) = ")" Then
        s = "-" & Mid(s, 2, Len(s) - 2)
    End If

    If IsNumeric(s) Then
        Numero = CDbl(s)
    Else
        Numero = 0
    End If

End Function


Private Function Positivo(ByVal valor As Variant) As Double

    Dim n As Double

    n = Numero(valor)

    If n > 0 Then
        Positivo = n
    Else
        Positivo = 0
    End If

End Function


' ==============================================================
' FECHA
' ==============================================================

Private Function LeerFecha( _
    ByVal valor As Variant, _
    ByRef resultado As Date) As Boolean

    On Error GoTo FechaIncorrecta

    If IsDate(valor) Then

        resultado = DateValue(CDate(valor))
        LeerFecha = True

        Exit Function

    End If

    If IsNumeric(valor) Then

        If CDbl(valor) > 1 Then

            resultado = DateValue(CDate(CDbl(valor)))
            LeerFecha = True

            Exit Function

        End If

    End If


FechaIncorrecta:

    LeerFecha = False

End Function

Private Function CrearMapaResponsableRuta( _
    ByVal ws As Worksheet, _
    ByVal fechaIni As Date, _
    ByVal fechaFin As Date) As Object

    Dim dConteo As Object
    Dim dRutaClave As Object
    Dim dNombreReal As Object

    Dim dMax As Object
    Dim dResultado As Object

    Dim cOficina As Long
    Dim cPromotor As Long
    Dim cOperacion As Long
    Dim cTransaccion As Long
    Dim cFecha As Long

    Dim ultimaFila As Long
    Dim r As Long

    Dim oficina As String
    Dim promotor As String

    Dim rutaN As String
    Dim promoN As String
    Dim clave As String

    Dim operacion As String
    Dim transaccion As String

    Dim f As Date
    Dim k As Variant

    Set dConteo = CreateObject("Scripting.Dictionary")
    Set dRutaClave = CreateObject("Scripting.Dictionary")
    Set dNombreReal = CreateObject("Scripting.Dictionary")

    Set dMax = CreateObject("Scripting.Dictionary")
    Set dResultado = CreateObject("Scripting.Dictionary")


    cOficina = ColumnaEncabezado(ws, "Oficina")

    cPromotor = ColumnaEncabezado( _
        ws, _
        "Promotor", _
        "Nombre promotor")

    cOperacion = ColumnaEncabezado( _
        ws, _
        "Operación", _
        "Operacion")

    cTransaccion = ColumnaEncabezado( _
        ws, _
        "Transacción", _
        "Transaccion")

    cFecha = ColumnaEncabezado( _
        ws, _
        "Fecha transacción", _
        "Fecha transaccion")


    If cOficina = 0 _
       Or cPromotor = 0 _
       Or cOperacion = 0 _
       Or cTransaccion = 0 _
       Or cFecha = 0 Then

        Set CrearMapaResponsableRuta = dResultado
        Exit Function

    End If


    ultimaFila = _
        ws.Cells(ws.Rows.Count, cFecha).End(xlUp).Row


    For r = 2 To ultimaFila

        If Not LeerFecha(ws.Cells(r, cFecha).Value, f) Then
            GoTo SiguienteRuta
        End If

        If f < fechaIni Or f > fechaFin Then
            GoTo SiguienteRuta
        End If


        operacion = _
            Normalizar(ws.Cells(r, cOperacion).Value)

        transaccion = _
            Normalizar(ws.Cells(r, cTransaccion).Value)


        If Not ( _
            (operacion = "PAGO A CONTRATO" _
             And transaccion = "PAGO") _
            Or _
            (operacion = "REFINANCIAMIENTO" _
             And transaccion = "DESCUENTO") _
            ) Then

            GoTo SiguienteRuta

        End If


        oficina = _
            Trim(CStr(ws.Cells(r, cOficina).Value))

        promotor = _
            Trim(CStr(ws.Cells(r, cPromotor).Value))


        If Normalizar(oficina) = "" _
           Or Normalizar(promotor) = "" Then

            GoTo SiguienteRuta

        End If


        rutaN = Normalizar(oficina)
        promoN = Normalizar(promotor)

        clave = _
            rutaN & "|" & promoN


        If dConteo.Exists(clave) Then

            dConteo(clave) = _
                CLng(dConteo(clave)) + 1

        Else

            dConteo.Add clave, 1
            dRutaClave.Add clave, rutaN
            dNombreReal.Add clave, promotor

        End If


SiguienteRuta:

    Next r


    ' ==========================================================
    ' ELEGIR EL PROMOTOR CON MÁS MOVIMIENTOS POR RUTA
    ' ==========================================================

    For Each k In dConteo.Keys

        rutaN = _
            CStr(dRutaClave(k))


        If Not dMax.Exists(rutaN) Then

            dMax.Add _
                rutaN, _
                CLng(dConteo(k))

            dResultado.Add _
                rutaN, _
                CStr(dNombreReal(k))

        ElseIf CLng(dConteo(k)) > CLng(dMax(rutaN)) Then

            dMax(rutaN) = _
                CLng(dConteo(k))

            dResultado(rutaN) = _
                CStr(dNombreReal(k))

        End If

    Next k


    Set CrearMapaResponsableRuta = dResultado

End Function


' ==============================================================
' PROMOTOR ACTUAL DE LA RUTA
' ==============================================================

Private Function ObtenerPromotorRuta( _
    ByVal oficina As String, _
    ByVal promotorOriginal As String, _
    ByVal dRutaPromotor As Object) As String

    Dim ruta As String

    ruta = Normalizar(oficina)

    If ruta <> "" Then

        If dRutaPromotor.Exists(ruta) Then

            ObtenerPromotorRuta = _
                CStr(dRutaPromotor(ruta))

            Exit Function

        End If

    End If

    ObtenerPromotorRuta = promotorOriginal

End Function

Private Function ActualizarHistorialIngresos( _
    ByVal wsIng As Worksheet, _
    ByVal fechaIni As Date, _
    ByVal fechaFin As Date, _
    ByVal modo As String) As Worksheet

    Dim wsH As Worksheet

    Dim ultimaCol As Long
    Dim ultimaFila As Long
    Dim ultimaHist As Long

    Dim cFecha As Long
    Dim cContrato As Long

    Dim r As Long
    Dim f As Date

    On Error Resume Next

    Set wsH = _
        ThisWorkbook.Worksheets("_HIST_INGRESOS")

    On Error GoTo 0


    If wsH Is Nothing Then

        Set wsH = _
            ThisWorkbook.Worksheets.Add( _
                After:=ThisWorkbook.Worksheets( _
                    ThisWorkbook.Worksheets.Count))

        wsH.Name = "_HIST_INGRESOS"

    End If


    ultimaCol = _
        wsIng.Cells(1, wsIng.Columns.Count). _
        End(xlToLeft).Column


    If Application.WorksheetFunction.CountA(wsH.Rows(1)) = 0 Then

        wsH.Cells(1, 1).Resize(1, ultimaCol).Value = _
            wsIng.Cells(1, 1).Resize(1, ultimaCol).Value

    End If


    cFecha = ColumnaEncabezado( _
        wsH, _
        "Fecha transacción", _
        "Fecha transaccion", _
        "Fecha de transacción", _
        "Fecha de transaccion")

    cContrato = _
        ColumnaEncabezado(wsH, "Contrato")


    If cFecha = 0 Or cContrato = 0 Then

        wsH.Cells.Clear

        wsH.Cells(1, 1).Resize(1, ultimaCol).Value = _
            wsIng.Cells(1, 1).Resize(1, ultimaCol).Value

        cFecha = ColumnaEncabezado( _
            wsH, _
            "Fecha transacción", _
            "Fecha transaccion")

        cContrato = _
            ColumnaEncabezado(wsH, "Contrato")

    End If


    ' ==========================================================
    ' Reemplazar solo fechas importadas. DIARIO = dia de corte.
    ' SEMANAL = semana completa para evitar duplicidad.
    ' ==========================================================
    ' QUITAR PERIODO ACTUAL PARA NO DUPLICARLO
    ' ==========================================================

    ultimaHist = _
        wsH.Cells(wsH.Rows.Count, cContrato). _
        End(xlUp).Row


    For r = ultimaHist To 2 Step -1

        If LeerFecha(wsH.Cells(r, cFecha).Value, f) Then

            If f >= fechaIni And f <= fechaFin Then
                wsH.Rows(r).Delete
            End If

        End If

    Next r


    ' ==========================================================
    ' AGREGAR INGRESOS ACTUAL
    ' ==========================================================

    cContrato = _
        ColumnaEncabezado(wsIng, "Contrato")

    ultimaFila = _
        wsIng.Cells(wsIng.Rows.Count, cContrato). _
        End(xlUp).Row


    For r = 2 To ultimaFila

        If Trim(CStr( _
            wsIng.Cells(r, cContrato).Value)) <> "" Then

            ultimaHist = _
                wsH.Cells(wsH.Rows.Count, 1). _
                End(xlUp).Row + 1

            wsH.Cells(ultimaHist, 1). _
                Resize(1, ultimaCol).Value = _
                wsIng.Cells(r, 1). _
                Resize(1, ultimaCol).Value

        End If

    Next r


    wsH.Visible = xlSheetVeryHidden

    Set ActualizarHistorialIngresos = wsH

End Function

Private Sub SumarDic( _
    ByVal dic As Object, _
    ByVal clave As String, _
    ByVal importe As Double)

    If clave = "" Then Exit Sub

    If dic.Exists(clave) Then

        dic(clave) = _
            CDbl(dic(clave)) + importe

    Else

        dic.Add clave, importe

    End If

End Sub


Private Function ValorDic( _
    ByVal dic As Object, _
    ByVal clave As String) As Double

    If dic.Exists(clave) Then
        ValorDic = CDbl(dic(clave))
    Else
        ValorDic = 0
    End If

End Function


' ==============================================================
' REGISTRAR GRUPO
'
' CAMBIO IMPORTANTE:
'
' YA NO SE USA EL CÓDIGO DEL PROMOTOR COMO LLAVE.
'
' LLAVE ÚNICA:
'   OFICINA/RUTA + NOMBRE DEL PROMOTOR
'
' Esto elimina los vendedores duplicados.
' ==============================================================

Private Function RegistrarGrupo( _
    ByVal oficina As String, _
    ByVal nombrePromotor As String, _
    ByVal dGrupos As Object, _
    ByVal dOficina As Object, _
    ByVal dPromotor As Object, _
    ByVal dNombreGrupo As Object) As String

    Dim clave As String
    Dim claveNombre As String


    If Trim(oficina) = "" Then
        oficina = "SIN OFICINA"
    End If

    If Trim(nombrePromotor) = "" Then
        nombrePromotor = "SIN PROMOTOR"
    End If


    clave = _
        Normalizar(oficina) & _
        "|NOM|" & _
        Normalizar(nombrePromotor)


    If Not dGrupos.Exists(clave) Then

        dGrupos.Add clave, True
        dOficina.Add clave, oficina
        dPromotor.Add clave, nombrePromotor

    End If


    claveNombre = _
        Normalizar(oficina) & _
        "|" & _
        Normalizar(nombrePromotor)


    If Not dNombreGrupo.Exists(claveNombre) Then

        dNombreGrupo.Add _
            claveNombre, _
            clave

    End If


    RegistrarGrupo = clave

End Function

Private Function SiguienteFechaCuota( _
    ByVal fechaActual As Date, _
    ByVal periodicidad As String) As Date

    Dim p As String
    Dim f As Date

    p = Normalizar(periodicidad)


    If InStr(p, "DIAR") > 0 Then

        f = DateAdd("d", 1, fechaActual)

        Do While Weekday(f, vbMonday) > 5
            f = DateAdd("d", 1, f)
        Loop

        SiguienteFechaCuota = f


    ElseIf InStr(p, "QUINC") > 0 _
        Or InStr(p, "CATORC") > 0 _
        Or InStr(p, "14") > 0 Then

        SiguienteFechaCuota = _
            DateAdd("d", 14, fechaActual)


    ElseIf InStr(p, "MENS") > 0 Then

        SiguienteFechaCuota = _
            DateAdd("m", 1, fechaActual)


    Else

        SiguienteFechaCuota = _
            DateAdd("d", 7, fechaActual)

    End If

End Function

Private Function GrupoTieneDatos( _
    ByVal grupo As String, _
    ByVal dSanoPC As Object, _
    ByVal dAtrasoPC As Object, _
    ByVal dVencidoPC As Object, _
    ByVal dCobSano As Object, _
    ByVal dCobAtraso As Object, _
    ByVal dCobVencido As Object) As Boolean

    Const TOL As Double = 0.01


    If Abs(ValorDic(dSanoPC, grupo)) > TOL Then
        GrupoTieneDatos = True
        Exit Function
    End If

    If Abs(ValorDic(dCobSano, grupo)) > TOL Then
        GrupoTieneDatos = True
        Exit Function
    End If

    If Abs(ValorDic(dAtrasoPC, grupo)) > TOL Then
        GrupoTieneDatos = True
        Exit Function
    End If

    If Abs(ValorDic(dCobAtraso, grupo)) > TOL Then
        GrupoTieneDatos = True
        Exit Function
    End If

    If Abs(ValorDic(dVencidoPC, grupo)) > TOL Then
        GrupoTieneDatos = True
        Exit Function
    End If

    If Abs(ValorDic(dCobVencido, grupo)) > TOL Then
        GrupoTieneDatos = True
        Exit Function
    End If


    GrupoTieneDatos = False

End Function


' ==============================================================
' ORDENAR
' ==============================================================

Private Function ObtenerGruposOrdenados( _
    ByVal dGrupos As Object, _
    ByVal dOficina As Object, _
    ByVal dPromotor As Object) As Variant

    Dim arr() As String

    Dim i As Long
    Dim j As Long

    Dim k As Variant

    Dim temp As String

    Dim a As String
    Dim b As String


    If dGrupos.Count = 0 Then

        ObtenerGruposOrdenados = Array()
        Exit Function

    End If


    ReDim arr(0 To dGrupos.Count - 1)


    i = 0

    For Each k In dGrupos.Keys

        arr(i) = CStr(k)
        i = i + 1

    Next k


    For i = LBound(arr) To UBound(arr) - 1

        For j = i + 1 To UBound(arr)

            a = _
                Normalizar(dOficina(arr(i))) & "|" & _
                Normalizar(dPromotor(arr(i)))

            b = _
                Normalizar(dOficina(arr(j))) & "|" & _
                Normalizar(dPromotor(arr(j)))


            If b < a Then

                temp = arr(i)
                arr(i) = arr(j)
                arr(j) = temp

            End If

        Next j

    Next i


    ObtenerGruposOrdenados = arr

End Function


' ==============================================================
' LIMPIAR EFECTIVIDAD
' ==============================================================

Private Sub PrepararHojaEfectividad( _
    ByVal ws As Worksheet)

    On Error Resume Next

    If ws.AutoFilterMode Then
        ws.AutoFilterMode = False
    End If

    ws.Range("A:M").UnMerge
    ws.Range("A:M").FormatConditions.Delete
    ws.Range("A:M").Clear

    On Error GoTo 0

    ws.Tab.Color = MR_Verde()

End Sub


' ==============================================================
' REPORTE
' ==============================================================

Private Sub GenerarReporte( _
    ByVal ws As Worksheet, _
    ByVal fechaIni As Date, _
    ByVal fechaFin As Date, _
    ByVal dGrupos As Object, _
    ByVal dOficina As Object, _
    ByVal dPromotor As Object, _
    ByVal dSanoPC As Object, _
    ByVal dAtrasoPC As Object, _
    ByVal dVencidoPC As Object, _
    ByVal dCobSano As Object, _
    ByVal dCobAtraso As Object, _
    ByVal dCobVencido As Object)

    Dim arr As Variant

    Dim i As Long
    Dim fila As Long
    Dim ultimaFila As Long

    Dim gruposMostrados As Long

    Dim grupo As String
    Dim oficina As String
    Dim promo As String

    Dim oficinaAnterior As String

    Dim sanoPC As Double
    Dim sanoCob As Double

    Dim atrasoPC As Double
    Dim atrasoCob As Double

    Dim vencPC As Double
    Dim vencCob As Double

    Dim totalCob As Double
    Dim mora As Double

    Dim pctSano As Double
    Dim pctAtraso As Double
    Dim pctVencido As Double
    Dim pctTotal As Double


    Dim tSanoPC As Double
    Dim tSanoCob As Double
    Dim tAtrasoPC As Double
    Dim tAtrasoCob As Double
    Dim tVencPC As Double
    Dim tVencCob As Double


    Dim gSanoPC As Double
    Dim gSanoCob As Double
    Dim gAtrasoPC As Double
    Dim gAtrasoCob As Double
    Dim gVencPC As Double
    Dim gVencCob As Double


    PrepararHojaEfectividad ws


    ' ==========================================================
    ' TITULO
    ' ==========================================================

    ws.Range("A1:M1").Merge

    ws.Range("A1").Value = _
        "MR LANA | EFECTIVIDAD DE COBRANZA"


    With ws.Range("A1:M1")

        .Interior.Color = MR_Negro()

        .Font.Name = "Montserrat"
        .Font.Size = 18
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With

    ws.Rows(1).RowHeight = 34


    ' ==========================================================
    ' PERIODO
    ' ==========================================================

    ws.Range("A2:M2").Merge

    ws.Range("A2").Value = _
        "PERIODO: " & _
        Format(fechaIni, "dd/mm/yyyy") & _
        " AL " & _
        Format(fechaFin, "dd/mm/yyyy")


    With ws.Range("A2:M2")

        .Interior.Color = MR_Verde()

        .Font.Name = "Montserrat"
        .Font.Size = 11
        .Font.Bold = True
        .Font.Color = MR_Negro()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With

    ws.Rows(2).RowHeight = 24


    ' ==========================================================
    ' NOTA
    ' ==========================================================

    ws.Range("A3:M3").Merge

    ws.Range("A3").Value = _
        "Anticipos a fecha cuota | Reno solo su semana | " & _
        "Vencido/atraso segun foto diaria | Sin moratorios"


    With ws.Range("A3:M3")

        .Interior.Color = MR_Blanco()

        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Italic = True
        .Font.Color = RGB(80, 80, 80)

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With

    ws.Rows(3).RowHeight = 22


    ' ==========================================================
    ' ENCABEZADOS FILA 4
    ' ==========================================================

    ws.Range("A4").Value = "Oficina"

    With ws.Range("A4")

        .Interior.Color = COB_AzulOscuro()
        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .HorizontalAlignment = xlLeft
        .VerticalAlignment = xlCenter

    End With


    ws.Range("B4:D4").Merge
    ws.Range("B4").Value = "SANO"

    With ws.Range("B4:D4")

        .Interior.Color = COB_Verde()
        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With


    ws.Range("E4:G4").Merge
    ws.Range("E4").Value = "VIGENTES CON ATRASO"

    With ws.Range("E4:G4")

        .Interior.Color = COB_Amarillo()
        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Negro()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With


    ws.Range("H4:J4").Merge
    ws.Range("H4").Value = "VENCIDOS CON ATRASO"

    With ws.Range("H4:J4")

        .Interior.Color = COB_Rojo()
        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With


    ws.Range("K4:L4").Merge
    ws.Range("K4").Value = "COBRANZA TOTAL"

    With ws.Range("K4:L4")

        .Interior.Color = COB_Azul()
        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With


    ws.Range("M4").Value = "MORA"

    With ws.Range("M4")

        .Interior.Color = COB_AzulOscuro()
        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .HorizontalAlignment = xlCenter
        .VerticalAlignment = xlCenter

    End With


    ' ==========================================================
    ' FILA 5
    ' ==========================================================

    ws.Range("A5").Value = "Promotor"

    ws.Range("B5").Value = "Por cobrar"
    ws.Range("C5").Value = "Cobrado"
    ws.Range("D5").Value = "% Cobrado"

    ws.Range("E5").Value = "Por cobrar"
    ws.Range("F5").Value = "Cobrado"
    ws.Range("G5").Value = "% Cobrado"

    ws.Range("H5").Value = "Por cobrar"
    ws.Range("I5").Value = "Cobrado"
    ws.Range("J5").Value = "% Cobrado"

    ws.Range("K5").Value = "Cobrado"
    ws.Range("L5").Value = "% Cobrado"

    ws.Range("M5").Value = ""


    With ws.Range("A5:M5")

        .Interior.Color = COB_AzulOscuro()

        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Blanco()

        .VerticalAlignment = xlCenter
        .WrapText = True

    End With


    ws.Range("A5").HorizontalAlignment = xlLeft
    ws.Range("B5:M5").HorizontalAlignment = xlCenter

    ws.Rows(4).RowHeight = 26
    ws.Rows(5).RowHeight = 28


    ' ==========================================================
    ' INFORMACIÓN
    ' ==========================================================

    fila = 6
    gruposMostrados = 0

    arr = _
        ObtenerGruposOrdenados( _
            dGrupos, _
            dOficina, _
            dPromotor)


    If dGrupos.Count = 0 Then

        ws.Cells(6, 1).Value = _
            "SIN MOVIMIENTOS PARA EL PERIODO"

        GoTo FormatoFinal

    End If


    oficinaAnterior = ""


    For i = LBound(arr) To UBound(arr)

        grupo = CStr(arr(i))


        ' ======================================================
        ' NO MOSTRAR TODO EN CERO
        ' ======================================================

        If Not GrupoTieneDatos( _
            grupo, _
            dSanoPC, _
            dAtrasoPC, _
            dVencidoPC, _
            dCobSano, _
            dCobAtraso, _
            dCobVencido) Then

            GoTo SiguienteGrupo

        End If


        oficina = _
            CStr(dOficina(grupo))

        promo = _
            CStr(dPromotor(grupo))


        sanoPC = _
            ValorDic(dSanoPC, grupo)

        sanoCob = _
            ValorDic(dCobSano, grupo)

        atrasoPC = _
            ValorDic(dAtrasoPC, grupo)

        atrasoCob = _
            ValorDic(dCobAtraso, grupo)

        vencPC = _
            ValorDic(dVencidoPC, grupo)

        vencCob = _
            ValorDic(dCobVencido, grupo)


        totalCob = _
            sanoCob + _
            atrasoCob + _
            vencCob


        mora = _
            sanoPC - totalCob


        If sanoPC > 0 Then

            pctSano = _
                sanoCob / sanoPC

            pctAtraso = _
                atrasoCob / sanoPC

            pctVencido = _
                vencCob / sanoPC

            pctTotal = _
                totalCob / sanoPC

        Else

            pctSano = 0
            pctAtraso = 0
            pctVencido = 0
            pctTotal = 0

        End If


        ' ======================================================
        ' CAMBIO DE OFICINA
        ' ======================================================

        If oficinaAnterior = "" _
           Or Normalizar(oficina) <> _
              Normalizar(oficinaAnterior) Then


            If oficinaAnterior <> "" Then

                EscribirSubtotal _
                    ws, _
                    fila, _
                    oficinaAnterior, _
                    tSanoPC, _
                    tSanoCob, _
                    tAtrasoPC, _
                    tAtrasoCob, _
                    tVencPC, _
                    tVencCob

                fila = fila + 1

                tSanoPC = 0
                tSanoCob = 0
                tAtrasoPC = 0
                tAtrasoCob = 0
                tVencPC = 0
                tVencCob = 0

            End If


            ws.Range( _
                "A" & fila & _
                ":M" & fila).Merge


            ws.Cells(fila, 1).Value = _
                UCase(oficina)


            With ws.Range( _
                "A" & fila & _
                ":M" & fila)

                .Interior.Color = MR_Blanco()

                .Font.Name = "Montserrat"
                .Font.Size = 9
                .Font.Bold = True
                .Font.Color = MR_Negro()

                .HorizontalAlignment = xlLeft
                .VerticalAlignment = xlCenter

            End With


            ws.Rows(fila).RowHeight = 20

            fila = fila + 1

        End If


        ' ======================================================
        ' PROMOTOR
        ' ======================================================

        ws.Cells(fila, 1).Value = promo

        ws.Cells(fila, 2).Value = sanoPC
        ws.Cells(fila, 3).Value = sanoCob
        ws.Cells(fila, 4).Value = pctSano

        ws.Cells(fila, 5).Value = atrasoPC
        ws.Cells(fila, 6).Value = atrasoCob
        ws.Cells(fila, 7).Value = pctAtraso

        ws.Cells(fila, 8).Value = vencPC
        ws.Cells(fila, 9).Value = vencCob
        ws.Cells(fila, 10).Value = pctVencido

        ws.Cells(fila, 11).Value = totalCob
        ws.Cells(fila, 12).Value = pctTotal

        ws.Cells(fila, 13).Value = mora


        With ws.Range( _
            "A" & fila & _
            ":M" & fila)

            .Interior.Color = MR_Blanco()

            .Font.Name = "Montserrat"
            .Font.Size = 9
            .Font.Bold = True
            .Font.Color = MR_Negro()

            .VerticalAlignment = xlCenter

        End With


        ws.Cells(fila, 1).HorizontalAlignment = xlLeft

        ws.Range( _
            "B" & fila & _
            ":M" & fila).HorizontalAlignment = xlRight


        ' ======================================================
        ' TOTALES DE OFICINA
        ' ======================================================

        tSanoPC = tSanoPC + sanoPC
        tSanoCob = tSanoCob + sanoCob

        tAtrasoPC = tAtrasoPC + atrasoPC
        tAtrasoCob = tAtrasoCob + atrasoCob

        tVencPC = tVencPC + vencPC
        tVencCob = tVencCob + vencCob


        ' ======================================================
        ' GRAN TOTAL
        ' ======================================================

        gSanoPC = gSanoPC + sanoPC
        gSanoCob = gSanoCob + sanoCob

        gAtrasoPC = gAtrasoPC + atrasoPC
        gAtrasoCob = gAtrasoCob + atrasoCob

        gVencPC = gVencPC + vencPC
        gVencCob = gVencCob + vencCob


        oficinaAnterior = oficina

        gruposMostrados = _
            gruposMostrados + 1

        fila = fila + 1


SiguienteGrupo:

    Next i


    If gruposMostrados = 0 Then

        ws.Cells(6, 1).Value = _
            "SIN MOVIMIENTOS PARA EL PERIODO"

        GoTo FormatoFinal

    End If


    ' ==========================================================
    ' ÚLTIMA OFICINA
    ' ==========================================================

    If oficinaAnterior <> "" Then

        EscribirSubtotal _
            ws, _
            fila, _
            oficinaAnterior, _
            tSanoPC, _
            tSanoCob, _
            tAtrasoPC, _
            tAtrasoCob, _
            tVencPC, _
            tVencCob

        fila = fila + 1

    End If


    ' ==========================================================
    ' TOTAL GENERAL
    ' ==========================================================

    EscribirGranTotal _
        ws, _
        fila, _
        gSanoPC, _
        gSanoCob, _
        gAtrasoPC, _
        gAtrasoCob, _
        gVencPC, _
        gVencCob


FormatoFinal:


    ultimaFila = _
        ws.Cells(ws.Rows.Count, "A"). _
        End(xlUp).Row


    With ws.Range( _
        "A1:M" & ultimaFila)

        .Font.Name = "Montserrat"

    End With


    If ultimaFila >= 6 Then

        ws.Range( _
            "B6:C" & ultimaFila). _
            NumberFormat = _
            "$#,##0.00;-$#,##0.00"

        ws.Range( _
            "E6:F" & ultimaFila). _
            NumberFormat = _
            "$#,##0.00;-$#,##0.00"

        ws.Range( _
            "H6:I" & ultimaFila). _
            NumberFormat = _
            "$#,##0.00;-$#,##0.00"

        ws.Range( _
            "K6:K" & ultimaFila). _
            NumberFormat = _
            "$#,##0.00;-$#,##0.00"

        ws.Range( _
            "M6:M" & ultimaFila). _
            NumberFormat = _
            "$#,##0.00;-$#,##0.00"


        ws.Range( _
            "D6:D" & ultimaFila). _
            NumberFormat = "0.00%"

        ws.Range( _
            "G6:G" & ultimaFila). _
            NumberFormat = "0.00%"

        ws.Range( _
            "J6:J" & ultimaFila). _
            NumberFormat = "0.00%"

        ws.Range( _
            "L6:L" & ultimaFila). _
            NumberFormat = "0.00%"

    End If


    AplicarSemaforoEfectividad _
        ws, _
        ultimaFila


    AplicarColorMora _
        ws, _
        ultimaFila


    If ultimaFila >= 4 Then

        With ws.Range( _
            "A4:M" & ultimaFila).Borders

            .LineStyle = xlContinuous
            .Color = RGB(210, 210, 210)
            .Weight = xlThin

        End With

    End If


    ws.Columns("A").ColumnWidth = 42

    ws.Columns("B").ColumnWidth = 14
    ws.Columns("C").ColumnWidth = 14
    ws.Columns("D").ColumnWidth = 13

    ws.Columns("E").ColumnWidth = 14
    ws.Columns("F").ColumnWidth = 14
    ws.Columns("G").ColumnWidth = 13

    ws.Columns("H").ColumnWidth = 14
    ws.Columns("I").ColumnWidth = 14
    ws.Columns("J").ColumnWidth = 13

    ws.Columns("K").ColumnWidth = 14
    ws.Columns("L").ColumnWidth = 13

    ws.Columns("M").ColumnWidth = 14


    ws.Activate

    ActiveWindow.DisplayGridlines = False

    ActiveWindow.FreezePanes = False

    ws.Range("A6").Select

    ActiveWindow.FreezePanes = True


    With ws.PageSetup

        .Orientation = xlLandscape

        .Zoom = False

        .FitToPagesWide = 1
        .FitToPagesTall = False

        .CenterHorizontally = True

        .PrintTitleRows = "$1:$5"

    End With


    ws.Range("A1").Select

End Sub


' ==============================================================
' SEMÁFORO EFECTIVIDAD - COLUMNA L
' ==============================================================

Private Sub AplicarSemaforoEfectividad( _
    ByVal ws As Worksheet, _
    ByVal ultimaFila As Long)

    Dim fila As Long

    Dim valor As Double

    Dim celda As Range


    If ultimaFila < 6 Then Exit Sub


    For fila = 6 To ultimaFila

        Set celda = _
            ws.Cells(fila, "L")


        If celda.MergeCells Then
            GoTo SiguienteSemaforo
        End If


        If IsNumeric(celda.Value) _
           And IsNumeric(ws.Cells(fila, "B").Value) _
           And Numero(ws.Cells(fila, "B").Value) > 0 Then


            valor = _
                CDbl(celda.Value)




            If valor >= 0.95 Then

                With celda

                    .Interior.Pattern = xlSolid
                    .Interior.Color = SEM_VerdeFondo()

                    .Font.Color = SEM_VerdeTexto()
                    .Font.Bold = True

                End With


            ElseIf valor >= 0.9 Then

                With celda

                    .Interior.Pattern = xlSolid
                    .Interior.Color = SEM_AmarilloFondo()

                    .Font.Color = SEM_AmarilloTexto()
                    .Font.Bold = True

                End With


            Else

                With celda

                    .Interior.Pattern = xlSolid
                    .Interior.Color = SEM_RojoFondo()

                    .Font.Color = SEM_RojoTexto()
                    .Font.Bold = True

                End With

            End If


        Else

            celda.Interior.Pattern = xlNone

        End If


SiguienteSemaforo:

    Next fila

End Sub


' ==============================================================
' MORA
' ==============================================================

Private Sub AplicarColorMora( _
    ByVal ws As Worksheet, _
    ByVal ultimaFila As Long)

    Dim fila As Long

    Dim valor As Double

    Dim celda As Range


    If ultimaFila < 6 Then Exit Sub


    For fila = 6 To ultimaFila

        Set celda = _
            ws.Cells(fila, "M")


        If celda.MergeCells Then
            GoTo SiguienteMora
        End If


        If IsNumeric(celda.Value) Then

            valor = CDbl(celda.Value)


            If valor < 0 Then

                celda.Font.Color = _
                    MORA_VerdeTexto()

                celda.Font.Bold = True


            ElseIf valor > 0 Then

                celda.Font.Color = _
                    MORA_RojoTexto()

                celda.Font.Bold = True


            Else

                celda.Font.Color = MR_Negro()
                celda.Font.Bold = True

            End If

        End If


SiguienteMora:

    Next fila

End Sub


' ==============================================================
' TOTAL OFICINA
' ==============================================================

Private Sub EscribirSubtotal( _
    ByVal ws As Worksheet, _
    ByVal fila As Long, _
    ByVal oficina As String, _
    ByVal sanoPC As Double, _
    ByVal sanoCob As Double, _
    ByVal atrasoPC As Double, _
    ByVal atrasoCob As Double, _
    ByVal vencPC As Double, _
    ByVal vencCob As Double)

    Dim totalCob As Double


    totalCob = _
        sanoCob + _
        atrasoCob + _
        vencCob


    ws.Cells(fila, 1).Value = _
        "TOTAL " & UCase(oficina)


    ws.Cells(fila, 2).Value = sanoPC
    ws.Cells(fila, 3).Value = sanoCob

    If sanoPC > 0 Then
        ws.Cells(fila, 4).Value = sanoCob / sanoPC
    Else
        ws.Cells(fila, 4).Value = 0
    End If


    ws.Cells(fila, 5).Value = atrasoPC
    ws.Cells(fila, 6).Value = atrasoCob

    If sanoPC > 0 Then
        ws.Cells(fila, 7).Value = atrasoCob / sanoPC
    Else
        ws.Cells(fila, 7).Value = 0
    End If


    ws.Cells(fila, 8).Value = vencPC
    ws.Cells(fila, 9).Value = vencCob

    If sanoPC > 0 Then
        ws.Cells(fila, 10).Value = vencCob / sanoPC
    Else
        ws.Cells(fila, 10).Value = 0
    End If


    ws.Cells(fila, 11).Value = totalCob

    If sanoPC > 0 Then
        ws.Cells(fila, 12).Value = totalCob / sanoPC
    Else
        ws.Cells(fila, 12).Value = 0
    End If


    ws.Cells(fila, 13).Value = _
        sanoPC - totalCob


    With ws.Range( _
        "A" & fila & _
        ":M" & fila)

        .Interior.Color = MR_Blanco()

        .Font.Name = "Montserrat"
        .Font.Size = 9
        .Font.Bold = True
        .Font.Color = MR_Negro()

        .VerticalAlignment = xlCenter

        .Borders(xlEdgeTop).LineStyle = xlContinuous
        .Borders(xlEdgeTop).Color = RGB(180, 180, 180)
        .Borders(xlEdgeTop).Weight = xlThin

    End With


    ws.Cells(fila, 1).HorizontalAlignment = xlLeft

    ws.Range( _
        "B" & fila & _
        ":M" & fila).HorizontalAlignment = xlRight


    ws.Rows(fila).RowHeight = 20

End Sub


' ==============================================================
' GRAN TOTAL
' ==============================================================

Private Sub EscribirGranTotal( _
    ByVal ws As Worksheet, _
    ByVal fila As Long, _
    ByVal sanoPC As Double, _
    ByVal sanoCob As Double, _
    ByVal atrasoPC As Double, _
    ByVal atrasoCob As Double, _
    ByVal vencPC As Double, _
    ByVal vencCob As Double)

    Dim totalCob As Double


    totalCob = _
        sanoCob + _
        atrasoCob + _
        vencCob


    ws.Cells(fila, 1).Value = _
        "GRAN TOTAL"


    ws.Cells(fila, 2).Value = sanoPC
    ws.Cells(fila, 3).Value = sanoCob

    If sanoPC > 0 Then
        ws.Cells(fila, 4).Value = sanoCob / sanoPC
    Else
        ws.Cells(fila, 4).Value = 0
    End If


    ws.Cells(fila, 5).Value = atrasoPC
    ws.Cells(fila, 6).Value = atrasoCob

    If sanoPC > 0 Then
        ws.Cells(fila, 7).Value = atrasoCob / sanoPC
    Else
        ws.Cells(fila, 7).Value = 0
    End If


    ws.Cells(fila, 8).Value = vencPC
    ws.Cells(fila, 9).Value = vencCob

    If sanoPC > 0 Then
        ws.Cells(fila, 10).Value = vencCob / sanoPC
    Else
        ws.Cells(fila, 10).Value = 0
    End If


    ws.Cells(fila, 11).Value = totalCob

    If sanoPC > 0 Then
        ws.Cells(fila, 12).Value = totalCob / sanoPC
    Else
        ws.Cells(fila, 12).Value = 0
    End If


    ws.Cells(fila, 13).Value = _
        sanoPC - totalCob


    With ws.Range( _
        "A" & fila & _
        ":M" & fila)

        .Interior.Color = MR_Blanco()

        .Font.Name = "Montserrat"
        .Font.Size = 11
        .Font.Bold = True
        .Font.Color = MR_Negro()

        .VerticalAlignment = xlCenter

        .Borders(xlEdgeTop).LineStyle = xlContinuous
        .Borders(xlEdgeTop).Color = COB_AzulOscuro()
        .Borders(xlEdgeTop).Weight = xlMedium

        .Borders(xlEdgeBottom).LineStyle = xlContinuous
        .Borders(xlEdgeBottom).Color = COB_AzulOscuro()
        .Borders(xlEdgeBottom).Weight = xlMedium

    End With


    With ws.Cells(fila, 1)

        .Interior.Color = COB_AzulOscuro()

        .Font.Color = MR_Blanco()
        .Font.Bold = True

        .HorizontalAlignment = xlLeft

    End With


    ws.Range( _
        "B" & fila & _
        ":M" & fila).HorizontalAlignment = xlRight


    ws.Rows(fila).RowHeight = 23

End Sub



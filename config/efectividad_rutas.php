<?php

/*
|--------------------------------------------------------------------------
| Efectividad de Cobranza — catálogo de RUTAS por SUCURSAL
|--------------------------------------------------------------------------
|
| En los Excel de Lendus la columna "Oficina" es la RUTA (ATOTONILCO, TEPEJI,
| TULA CENTRO…), no la sucursal; la columna "Zona" viene vacía. La sucursal
| se obtiene de este catálogo (proporcionado por Dirección, 14-oct-2026).
|
| Fuera del catálogo a propósito: rutas INACTIVAS, MATRIZ, REGIÓN Q1/Q2/Q3 y
| AGUASCALIENTES (inactiva). Una ruta que llegue en los archivos y no esté
| aquí se calcula igual (nunca se descarta un cobro) y se muestra en
| "SIN SUCURSAL ASIGNADA" con un aviso, para agregarla a este catálogo.
|
| La comparación es por nombre normalizado (mayúsculas, sin acentos,
| espacios simples), igual que el motor.
|
*/

return [
    'sin_sucursal' => 'SIN SUCURSAL ASIGNADA',

    'sucursales' => [
        'CUERNAVACA' => [
            'VOLANTE CUERNAVACA', 'YAUTEPEC', 'BARONA', 'JIUTEPEC', 'HUITZILAC', 'CUERNAVACA SUR',
            'CUERNAVACA CENTRO', 'CUERNAVACA GTE', 'TEMIXCO', 'ZAPATA', 'CUERNAVACA ORIENTE', 'CUERNAVACA SUBGTE',
        ],
        'SAN JUAN DEL RIO' => [
            'SJR - GTE', 'ESEQUIEL MONTES', 'SAN JUAN DEL RIO I', 'TEQUISQUIAPA', 'SJR II',
        ],
        'MIACATLAN' => [
            'PNT-IXTLA', 'EML-ZAPATA', 'VOLANTE MIACATLAN', 'MAZATEPEC', 'XOCHITEPEC', 'XOXOCOTLA - JOJUTLA',
            'TETECALA', 'MIACATLAN GERENCIA', 'MIACATLAN SUBGERENCIA',
        ],
        'ATLACOMULCO' => [
            'VOLANTE ATLACOMULCO', 'ATLACOMULCO CENTRO', 'ACAMBAY', 'SAN FELIPE', 'TECOAC', 'TEMASCALCINGO',
            'SAN LORENZO TLACOTEPEC', 'SAN BARTOLO MORELOS', 'ATLACOMULCO GERENCIA', 'ATLACOMULCO SUBGERENCIA',
        ],
        'IXTLAHUACA' => [
            'TEMOAYA', 'SAN PEDRO', 'IXTLAHUACA CENTRO', 'JIQUIPILCO', 'IXT - SANTA ANA', 'ALMOLOYA DE JUARES',
            'IXTLAHUACA GERENCIA', 'IXTLAHUACA SUBGERENCIA',
        ],
        'TENANGO' => [
            'VOLANTE TENANGO', 'CAPULHUAC', 'CHAPULTEPEC', 'TENANGO-2', 'TENANGO GERENCIA', 'TENANGO SUBGERENCIA',
            'TENANCINGO-1', 'TENANGO-1', 'CALIMAYA-2', 'SANTIAGO', 'METEPEC-2',
        ],
        'SAN LUIS POTOSI' => [
            'VOLANTE SLP2', 'SAN LUIS SUBGERENCIA', 'SOLEDAD', 'VOLANTE SLP', 'MUÑOZ', 'MAGUEYES', 'ORIENTE-SLP',
            'CENTRO-SLP', 'ZONA SUR', 'SAN LUIS GERENCIA',
        ],
        'ATLIXCO' => [
            'ATLIX-CEN', 'ATLIX-SUBGTE', 'VOLANTE ATLIXCO', 'ATLIX-SUR', 'ATLIX-NOR', 'TOCH', 'TLAX', 'SACHOL', 'HUAQ',
            'SJ', 'MAT-CEN', 'ATLIX-MATP', 'ATLIX-MATS', 'ATLIX-GTE', 'CHOL',
        ],
        'CORDOBA' => [
            'VOLANTE CORDOBA', 'CORDOBA FORTIN', 'CORDOBA PADELMA', 'VILLA JARA', 'CORDOBA CENTRO', 'CORDOBA CENTRO 2',
            '20 DE NOVIEMBRE', 'CALZADAS', 'CHOCAMAN', 'COSCOMATEPEC', 'CUITLAHUAC', 'FORTN', 'LOPEZ ARIAS',
            'PASO DEL MACHO', 'SAN ROMAN', 'TECAMA TOXPAN', 'YANGA', 'CORDOBA GERENCIA', 'CORDOBA SUBGERENCIA',
            'CORDOBA ALAMEDA',
        ],
        'HUAMANTLA' => [
            'APIZ-CEN', 'HUAMANTLA (VENCIDOS)', 'HUAMANTLA (CASTIGO)', 'APIX-NORESTE', 'APIZACO', 'LIBR', 'ACAJ',
            'APIZ-NOR', 'APIX-SUR', 'HUAM-GTE', 'HUAM-SUBGTE', 'VOLANTE HUAMANTLA',
        ],
        'ORIZABA' => [
            'CENTRO C', 'VOLANTE ORIZABA', 'GRUPALES SUR', 'GRUPALES NORTE', 'VOLANTE 2 ORIZABA', 'ATZACAN', 'CENTRO A',
            'CENTRO B', 'CENTRO D', 'CIUDAD MENDOZA', 'CUAUTLAPAN / DOS RIOS', 'IXHUATLANCILLO', 'IXTACZOQUITLAM NORTE',
            'IXTACZOQUITLAM SUR', 'JALAPILLA', 'LA PERLA', 'NOGALES', 'ORIZABA SUR', 'RIO BLANCO NORTE',
            'RIO BLANCO SUR', 'ORIZABA GERENCIA', 'ORIZABA SUBGERENCIA',
        ],
        'TLAXCALA' => [
            'VOLANTE TLAXCALA', 'VOLANTE 2 TLAXCALA', 'TLAXCALA CENTRO', 'CONTLA', 'NATIVITAS', 'ZACATELCO',
            'VILLA ALTA', 'CHIAUTEMPAN', 'TOTOLAC', 'SAN MATIAS', 'SAN PABLO', 'TETLANOHCAN', 'TLAXCALA GERENCIA',
            'TLAXCALA SUBGERENCIA',
        ],
        'TULA' => [
            'VOLANTE TULA', 'ATITALAQUIA', 'TULA GERENCIA', 'TULA SUBGERENCIA', 'TLAXCOAPAN', 'TLAHUELILPAN',
            'TEZONTEPEC', 'QUMA', 'TULA CENTRO', 'TEPEJI', 'SANTA ANA', 'CRUZ AZUL', 'ATOTONILCO',
        ],
    ],
];

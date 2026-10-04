<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Datos corporativos canónicos — PRODUCTOS Y SERVICIOS MR LANA S.A.P.I. DE C.V.
    |--------------------------------------------------------------------------
    |
    | Cierre real OKR (04-oct-2026, punto 9): "Ciudad de México" estaba
    | hardcodeado en resources/js/pages/Okr/Show.vue como lugar de emisión de
    | la Carta Compromiso — el machote original es de MR LANA Cuernavaca. Esta
    | configuración central es la ÚNICA fuente de lugar/dirección/URL
    | corporativa para documentos oficiales (Carta Compromiso, Warning Rojo,
    | cualquier futuro machote); nunca se vuelve a hardcodear en un componente
    | Vue ni en una blade individual.
    |
    */

    'name' => env('COMPANY_NAME', 'PRODUCTOS Y SERVICIOS MR LANA S.A.P.I. DE C.V.'),

    'document_place' => env('COMPANY_DOCUMENT_PLACE', 'Cuernavaca, Morelos'),

    'address' => env('COMPANY_ADDRESS', 'Subida del Club 114, zona 1, Reforma, 62260 Cuernavaca, Mor.'),

    'url' => env('COMPANY_URL', 'https://www.mr-lana.com/'),

];

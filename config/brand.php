<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad del producto
    |--------------------------------------------------------------------------
    |
    | Estos valores son el respaldo por defecto. El super admin puede
    | sobrescribir el nombre, la descripción y el logo desde
    | Configuración global (claves saas.app_name, saas.tagline, saas.logo).
    |
    */

    'name' => 'Dexi',

    'tagline' => 'Gestión para clínicas dentales',

    /*
    |--------------------------------------------------------------------------
    | Desarrollador (firma)
    |--------------------------------------------------------------------------
    |
    | El logo se sirve desde public/. Si el archivo no existe, los componentes
    | de marca hacen fallback a la firma en texto.
    |
    */

    'vendor' => 'SCZ SOFT',

    'vendor_logo' => 'img/scz-soft-logo.png',

    'vendor_url' => null,

    /*
    |--------------------------------------------------------------------------
    | Paleta
    |--------------------------------------------------------------------------
    |
    | Turquesa clínico: limpio, de salud dental, y emparentado con el verde
    | de SCZ SOFT. `rgb` es `base` en canales (para transparencias de Bootstrap).
    |
    */

    'colors' => [
        'lightest' => '#E7F6F5',
        'light' => '#7FD3CC',
        'base' => '#0E9F9A',
        'dark' => '#0B7E7A',
        'darkest' => '#075B58',
        'rgb' => '14, 159, 154',
    ],

];

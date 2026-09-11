<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing (SaaS)
    |--------------------------------------------------------------------------
    |
    | Las suscripciones de la plataforma se cobran con Paddle.
    | Las ventas de la tienda pública no usan este cobro: siguen
    | con métodos manuales (QR, transferencia, depósito).
    |
    | BILLING_OFFLINE=true solo en local/staging: el líder entra sin pagar.
    | En producción debe ser false y PADDLE_API_KEY debe estar definido.
    |
    */

    'offline' => filter_var(
        env('BILLING_OFFLINE', env('APP_ENV', 'production') !== 'production'),
        FILTER_VALIDATE_BOOL,
    ),

];

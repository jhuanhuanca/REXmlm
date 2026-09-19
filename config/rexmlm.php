<?php

return [

    'invitation_ttl_days' => (int) env('REXMLM_INVITATION_TTL_DAYS', 7),

    'max_login_attempts' => (int) env('REXMLM_MAX_LOGIN_ATTEMPTS', 5),

    'lockout_minutes' => (int) env('REXMLM_LOCKOUT_MINUTES', 15),

    'two_factor' => [
        'window' => (int) env('REXMLM_2FA_WINDOW', 1),
        'pending_minutes' => (int) env('REXMLM_2FA_PENDING_MINUTES', 15),
        'issuer' => env('REXMLM_2FA_ISSUER', env('APP_NAME', 'REXmlm')),
    ],

    'roles' => [
        'admin' => 'admin',
        'leader' => 'leader',
        'partner' => 'partner',
    ],

    'referral_subscription_commission' => (float) env('REXMLM_REFERRAL_SUBSCRIPTION_COMMISSION', 10),

    'withdrawal_minimum' => (float) env('REXMLM_WITHDRAWAL_MINIMUM', 20),

    'closing' => [
        'default_timezone' => env('REXMLM_CLOSING_TIMEZONE', 'America/La_Paz'),
        'first_organization_slug' => 'hgw',
        'country_timezones' => [
            'BO' => 'America/La_Paz',
            'VE' => 'America/Caracas',
            'CO' => 'America/Bogota',
            'MX' => 'America/Mexico_City',
            'PE' => 'America/Lima',
            'EC' => 'America/Guayaquil',
            'CL' => 'America/Santiago',
            'AR' => 'America/Argentina/Buenos_Aires',
            'UY' => 'America/Montevideo',
            'PY' => 'America/Asuncion',
            'BR' => 'America/Sao_Paulo',
            'PA' => 'America/Panama',
            'CR' => 'America/Costa_Rica',
            'GT' => 'America/Guatemala',
            'HN' => 'America/Tegucigalpa',
            'SV' => 'America/El_Salvador',
            'NI' => 'America/Managua',
            'DO' => 'America/Santo_Domingo',
            'PR' => 'America/Puerto_Rico',
            'CU' => 'America/Havana',
            'US' => 'America/New_York',
            'ES' => 'Europe/Madrid',
            'IT' => 'Europe/Rome',
            'PT' => 'Europe/Lisbon',
        ],
        'max_upload_kb' => (int) env('REXMLM_CLOSING_UPLOAD_KB', 10240),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'admin_url' => env('ADMIN_URL', 'http://localhost:5174'),

    'secondary_company' => [
        'price' => (float) env('REXMLM_SECONDARY_COMPANY_PRICE', 15),
        'currency' => env('REXMLM_SECONDARY_COMPANY_CURRENCY', 'USD'),
        'paddle_price_id' => env('REXMLM_SECONDARY_COMPANY_PADDLE_PRICE_ID'),
    ],

    'landing_block_types' => ['text', 'image', 'store_cta'],

    'countries' => [
        ['code' => 'VE', 'name' => 'Venezuela'],
        ['code' => 'CO', 'name' => 'Colombia'],
        ['code' => 'MX', 'name' => 'México'],
        ['code' => 'PE', 'name' => 'Perú'],
        ['code' => 'EC', 'name' => 'Ecuador'],
        ['code' => 'BO', 'name' => 'Bolivia'],
        ['code' => 'CL', 'name' => 'Chile'],
        ['code' => 'AR', 'name' => 'Argentina'],
        ['code' => 'UY', 'name' => 'Uruguay'],
        ['code' => 'PY', 'name' => 'Paraguay'],
        ['code' => 'BR', 'name' => 'Brasil'],
        ['code' => 'PA', 'name' => 'Panamá'],
        ['code' => 'CR', 'name' => 'Costa Rica'],
        ['code' => 'GT', 'name' => 'Guatemala'],
        ['code' => 'HN', 'name' => 'Honduras'],
        ['code' => 'SV', 'name' => 'El Salvador'],
        ['code' => 'NI', 'name' => 'Nicaragua'],
        ['code' => 'DO', 'name' => 'República Dominicana'],
        ['code' => 'PR', 'name' => 'Puerto Rico'],
        ['code' => 'CU', 'name' => 'Cuba'],
        ['code' => 'US', 'name' => 'Estados Unidos'],
        ['code' => 'ES', 'name' => 'España'],
        ['code' => 'IT', 'name' => 'Italia'],
        ['code' => 'PT', 'name' => 'Portugal'],
        ['code' => 'XX', 'name' => 'Otro'],
    ],

    'inventory' => [
        'low_stock_below' => 3,
        'expiry_warning_days' => 15,
    ],
];

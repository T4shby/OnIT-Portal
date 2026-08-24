<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client-facing Contact Support (Service Desk)
    |--------------------------------------------------------------------------
    |
    | Shown on the Contact Support hub for customer users (not On IT technicians).
    | Override via .env without a code change.
    |
    */

    'phone' => env('ONIT_SUPPORT_PHONE', '03300 945 946'),
    'email' => env('ONIT_SUPPORT_EMAIL', 'service.desk@onit.ltd'),
    'hours' => env('ONIT_SUPPORT_HOURS', 'Monday-Friday, 09:00-17:00'),
    'timezone_label' => env('ONIT_SUPPORT_HOURS_TZ', 'UK'),

    'address_lines' => array_values(array_filter(array_map(
        'trim',
        explode('|', (string) env(
            'ONIT_SUPPORT_ADDRESS',
            'Unit G, Wheatley Park|Mirfield|WF14 8HE',
        )),
    ))),

];

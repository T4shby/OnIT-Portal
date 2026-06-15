<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'organizations'),
    ],

    'azure' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'organizations'),
    ],

    'portal' => [
        'superops_url' => env('SUPEROPS_PORTAL_URL'),
        'pax8_url' => env('PAX8_PORTAL_URL'),
        'microsoft_365_url' => env('MICROSOFT_365_PORTAL_URL'),
        'knowledge_base_url' => env('KNOWLEDGE_BASE_URL'),
        'billing_portal_url' => env('BILLING_PORTAL_URL'),
        'super_admin_email' => env('SUPER_ADMIN_EMAIL', 'admin@onit.example'),
    ],

    'superops' => [
        'api_token' => env('SUPEROPS_API_TOKEN'),
        'subdomain' => env('SUPEROPS_SUBDOMAIN'),
        'region' => env('SUPEROPS_REGION', 'us'),
        'portal_url' => env('SUPEROPS_PORTAL_URL', 'https://app.superops.ai'),
        'requester_portal_url' => env('SUPEROPS_REQUESTER_PORTAL_URL')
            ?: (filled(env('SUPEROPS_SUBDOMAIN'))
                ? 'https://'.env('SUPEROPS_SUBDOMAIN').'.superops.ai'
                : null),
        'sso_url' => env('SUPEROPS_SSO_URL'),
        'requester_login_path' => env('SUPEROPS_REQUESTER_LOGIN_PATH', '/#/requester/login'),
        'login_hint_enabled' => env('SUPEROPS_LOGIN_HINT_ENABLED', true),
        'sso_enabled' => env('SUPEROPS_SSO_ENABLED', true),
        'auto_open_after_login' => env('SUPEROPS_AUTO_OPEN_AFTER_LOGIN', false),
    ],

];

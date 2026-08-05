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
        'oauth_stateless' => match (true) {
            in_array(env('MICROSOFT_OAUTH_STATELESS'), ['false', '0'], true) => false,
            in_array(env('MICROSOFT_OAUTH_STATELESS'), ['true', '1'], true) => true,
            default => env('APP_ENV') === 'production',
        },
    ],

    'portal' => [
        'organisation_name' => env('PORTAL_ORGANISATION_NAME', 'On IT Technology Partners'),
        'superops_url' => env('SUPEROPS_PORTAL_URL'),
        'pax8_url' => env('PAX8_PORTAL_URL'),
        'microsoft_365_url' => env('MICROSOFT_365_PORTAL_URL'),
        'knowledge_base_url' => env('KNOWLEDGE_BASE_URL'),
        'billing_portal_url' => env('BILLING_PORTAL_URL'),
        'super_admin_email' => env('SUPER_ADMIN_EMAIL', 'admin@onit.example'),
    ],

    'pax8' => [
        'partner_url' => env('PAX8_PARTNER_PORTAL_URL', env('PAX8_PORTAL_URL', 'https://app.pax8.com')),
        'partner_login_path' => env('PAX8_PARTNER_LOGIN_PATH', '/login'),
        'company_url_template' => env('PAX8_COMPANY_URL_TEMPLATE', 'https://app.pax8.com/companies/{companyId}'),
        'login_hint_enabled' => env('PAX8_LOGIN_HINT_ENABLED', true),
        'enabled' => env('PAX8_SSO_ENABLED', true),
    ],

    'dropsuite' => [
        'api_url' => env('DROPSUITE_API_URL', 'https://dropsuite.us/api'),
        'reseller_token' => env('DROPSUITE_RESELLER_TOKEN'),
        'auth_token' => env('DROPSUITE_AUTH_TOKEN'),
        'enabled' => env('DROPSUITE_ENABLED', false),
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
        'technician_portal_url' => env('SUPEROPS_TECHNICIAN_PORTAL_URL')
            ?: env('SUPEROPS_REQUESTER_PORTAL_URL')
            ?: (filled(env('SUPEROPS_SUBDOMAIN'))
                ? 'https://'.env('SUPEROPS_SUBDOMAIN').'.superops.ai'
                : null),
        'sso_url' => env('SUPEROPS_SSO_URL'),
        'requester_login_path' => env('SUPEROPS_REQUESTER_LOGIN_PATH', '/#/requester/login'),
        'technician_login_path' => env('SUPEROPS_TECHNICIAN_LOGIN_PATH', '/#/technician/login'),
        'login_hint_enabled' => env('SUPEROPS_LOGIN_HINT_ENABLED', true),
        'sso_enabled' => env('SUPEROPS_SSO_ENABLED', true),
        'auto_open_after_login' => env('SUPEROPS_AUTO_OPEN_AFTER_LOGIN', false),
        // When SuperOps last success is older than this, prewarm queues a new job (~2.5m cadence).
        'dashboard_refresh_after_minutes' => (float) env('SUPEROPS_DASHBOARD_REFRESH_AFTER_MINUTES', 2.5),
        // Client soft note window (slightly above requeue so one slow job doesn't flash "old").
        'dashboard_cache_minutes' => (float) env('SUPEROPS_DASHBOARD_CACHE_MINUTES', 5),
        // Keep last successful snapshot long enough that deploys/backlogs do not empty demos.
        'dashboard_stale_minutes' => env('SUPEROPS_DASHBOARD_STALE_MINUTES', 10080),
        'dashboard_refresh_cooldown_seconds' => env('SUPEROPS_DASHBOARD_REFRESH_COOLDOWN_SECONDS', 60),
        // Cap GraphQL pagination so one refresh cannot exceed the queue worker window.
        'dashboard_max_pages' => max(1, (int) env('SUPEROPS_DASHBOARD_MAX_PAGES', 10)),
    ],

    'huntress' => [
        'api_key' => env('HUNTRESS_API_KEY'),
        'api_secret' => env('HUNTRESS_API_SECRET'),
        'enabled' => env('HUNTRESS_ENABLED', false),
    ],

    'entra_sync' => [
        'enabled' => env('ENTRA_SYNC_ENABLED', false),
        'client_id' => env('ENTRA_SYNC_CLIENT_ID', env('MICROSOFT_CLIENT_ID')),
        'client_secret' => env('ENTRA_SYNC_CLIENT_SECRET', env('MICROSOFT_CLIENT_SECRET')),
        'directory_cache_minutes' => (float) env('ENTRA_DIRECTORY_CACHE_MINUTES', 5),
        // Prewarm requeue (default 2.5m with PORTAL_PREWARM_INTERVAL_MINUTES).
        'directory_refresh_after_minutes' => (float) env('ENTRA_DIRECTORY_REFRESH_AFTER_MINUTES', 2.5),
        'directory_stale_minutes' => env('ENTRA_DIRECTORY_STALE_MINUTES', 1440),
        'directory_refresh_lock_seconds' => env('ENTRA_DIRECTORY_REFRESH_LOCK_SECONDS', 600),
        'directory_refresh_cooldown_seconds' => env('ENTRA_DIRECTORY_REFRESH_COOLDOWN_SECONDS', 60),
        'maintain_superops_group' => env('ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP', true),
        'superops_name_extension_attribute' => (int) env('ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE', 1),
        'superops_provision_on_demand' => env('ENTRA_SYNC_SUPEROPS_PROVISION_ON_DEMAND', true),
        // Seconds to wait after writing extensionAttribute1 before SCIM provision-on-demand (Entra replication).
        'superops_provision_delay_after_names_seconds' => (int) env('ENTRA_SYNC_SUPEROPS_PROVISION_DELAY_SECONDS', 3),
        // Entra UI provisions one user at a time; batching often skips extensionAttribute1 updates.
        'superops_provision_batch_size' => max(1, (int) env('ENTRA_SYNC_SUPEROPS_PROVISION_BATCH_SIZE', 1)),
        // Microseconds between provision-on-demand API calls (default 2.1s — Graph limit ~5 per 10s).
        'superops_provision_interval_us' => (int) env('ENTRA_SYNC_SUPEROPS_PROVISION_INTERVAL_US', 2_100_000),
        'superops_provision_max_attempts' => max(1, (int) env('ENTRA_SYNC_SUPEROPS_PROVISION_MAX_ATTEMPTS', 3)),
        'lock_seconds' => max(60, (int) env('ENTRA_SYNC_LOCK_SECONDS', 600)),
        'web_max_execution_seconds' => max(60, (int) env('ENTRA_SYNC_WEB_MAX_EXECUTION_SECONDS', 300)),
    ],

    'm365_insights' => [
        'insights_cache_minutes' => (float) env('M365_INSIGHTS_CACHE_MINUTES', 5),
        // Prewarm requeue (default 2.5m cadence).
        'insights_refresh_after_minutes' => (float) env('M365_INSIGHTS_REFRESH_AFTER_MINUTES', 2.5),
        'insights_stale_minutes' => env('M365_INSIGHTS_STALE_MINUTES', 1440),
        'insights_refresh_cooldown_seconds' => env('M365_INSIGHTS_REFRESH_COOLDOWN_SECONDS', 60),
    ],

];

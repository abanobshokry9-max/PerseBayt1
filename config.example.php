<?php
return [
    'app' => [
        'name' => 'Elmetr AI Agency',
        'env' => getenv('PERSEBAYT_APP_ENV') ?: 'production',
        'timezone' => getenv('PERSEBAYT_TIMEZONE') ?: 'Africa/Cairo',
        'base_url' => getenv('PERSEBAYT_BASE_URL') ?: 'https://persebayt.com/api',
        'trust_proxy' => true,
        'session_name' => 'ELMETRADMIN',
    ],
    'db' => [
        'host' => getenv('PERSEBAYT_DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('PERSEBAYT_DB_PORT') ?: 3306),
        'name' => getenv('PERSEBAYT_DB_NAME') ?: '',
        'user' => getenv('PERSEBAYT_DB_USER') ?: '',
        'pass' => getenv('PERSEBAYT_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'ai' => [
        'api_key' => getenv('PERSEBAYT_AI_API_KEY') ?: '',
        'model' => getenv('PERSEBAYT_AI_MODEL') ?: '',
        'enabled' => true,
        'max_daily_calls' => 500,
    ],
    'meta' => [
        'verify_token' => getenv('PERSEBAYT_META_VERIFY_TOKEN') ?: '',
        'app_secret' => getenv('PERSEBAYT_META_APP_SECRET') ?: '',
        'access_token' => getenv('PERSEBAYT_META_ACCESS_TOKEN') ?: '',
        'phone_number_id' => getenv('PERSEBAYT_META_PHONE_NUMBER_ID') ?: '',
        'graph_version' => getenv('PERSEBAYT_META_GRAPH_VERSION') ?: 'v23.0',
    ],
    'hostinger' => [
        'api_token' => getenv('PERSEBAYT_HOSTINGER_API_TOKEN') ?: '',
    ],
    'twilio' => [
        'account_sid' => getenv('PERSEBAYT_TWILIO_ACCOUNT_SID') ?: '',
        'api_key' => getenv('PERSEBAYT_TWILIO_API_KEY') ?: '',
        'api_secret' => getenv('PERSEBAYT_TWILIO_API_SECRET') ?: '',
        'auth_token' => getenv('PERSEBAYT_TWILIO_AUTH_TOKEN') ?: '',
        'messaging_service_sid' => getenv('PERSEBAYT_TWILIO_MESSAGING_SERVICE_SID') ?: '',
        'sms_from' => getenv('PERSEBAYT_TWILIO_SMS_FROM') ?: '',
        'voice_from' => getenv('PERSEBAYT_TWILIO_VOICE_FROM') ?: '',
        'whatsapp_from' => getenv('PERSEBAYT_TWILIO_WHATSAPP_FROM') ?: '',
    ],
    'calls' => [
        'bridge_url' => getenv('PERSEBAYT_CALLS_BRIDGE_URL') ?: '',
        'bridge_secret' => getenv('PERSEBAYT_CALLS_BRIDGE_SECRET') ?: '',
    ],
];

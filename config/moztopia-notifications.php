<?php

declare(strict_types=1);

return [
    'application' => env('NOTIFICATIONS_APPLICATION'),
    'environment' => env('NOTIFICATIONS_ENVIRONMENT', 'production'),
    'fingerprint_key' => env('NOTIFICATIONS_FINGERPRINT_KEY'),
    'fcm_project' => env('NOTIFICATIONS_FCM_PROJECT', 'app'),
    'fcm_project_id' => env('FIREBASE_PROJECT_ID'),
    'fcm_credentials' => env('FIREBASE_CREDENTIALS'),
    'fcm_timeout' => 20,
    'apns' => [
        'key_id' => env('APN_KEY_ID'),
        'team_id' => env('APN_TEAM_ID'),
        'app_bundle_id' => env('APN_BUNDLE_ID'),
        'private_key_path' => env('APN_PRIVATE_KEY_PATH'),
        'private_key_content' => env('APN_PRIVATE_KEY_CONTENT'),
        'production' => env('APN_PRODUCTION', true),
    ],
    'queue' => [
        'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION'),
        'name' => env('NOTIFICATIONS_QUEUE', 'notifications'),
        'tries' => 5,
        'backoff' => [30, 120, 600, 1800],
        'timeout' => 60,
    ],
    'history' => true,
    'push_categories' => [],
    'android_channels' => [],
    'defaults' => [
        'category' => 'general',
        'priority' => 'normal',
    ],
    'ttl' => 86400,
    'deduplication_retention_days' => 30,
    'delivery_retention_days' => 7,
    'invalidate_tokens' => true,
    'recovery_batch' => 100,
];

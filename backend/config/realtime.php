<?php

return [
    'enabled' => env('REALTIME_ENABLED', true),
    'secret' => env('REALTIME_SECRET', hash('sha256', 'realtime:'.env('APP_KEY', ''))),
    'publish_url' => env('REALTIME_PUBLISH_URL', 'http://127.0.0.1:8081/publish'),
    'public_url' => env('REALTIME_PUBLIC_URL'),
    'port' => (int) env('REALTIME_PORT', 8081),
];

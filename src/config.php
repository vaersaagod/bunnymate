<?php

return [
    'pullingEnabled' => true,
    'pullZones' => [
        'default' => [
            'hostname' => '',//https://yourpullzone.b-cdn.net',
            'enabled' => true,
        ],
    ],
    'defaultPullZone' => 'default',

    // Account API key from the Bunny dashboard, used to purge the CDN cache.
    // This is not a storage zone password.
    'apiKey' => '$BUNNY_API_KEY',

    // Whether to purge changed files from the CDN cache on a Bunny Storage filesystem.
    // Individual URLs are purged, never the whole pull zone.
    'purgeEnabled' => true,
];

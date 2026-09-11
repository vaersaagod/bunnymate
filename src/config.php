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

    // Bunny Stream video libraries, indexed by handle
    'videoLibraries' => [
        //'default' => [
        //    'id' => '$BUNNY_STREAM_LIBRARY_ID',
        //    'apiKey' => '$BUNNY_STREAM_API_KEY',
        //    'readOnlyApiKey' => '$BUNNY_STREAM_READONLY_KEY',
        //    'hostname' => 'vz-xxxxxxxx-xxx.b-cdn.net',
        //    // Only needed if the library's pull zone has token authentication enabled
        //    'tokenAuthKey' => '$BUNNY_STREAM_TOKEN_KEY',
        //],
    ],
    'defaultVideoLibrary' => null,

    // Maps volume handles to video library handles. Volumes that aren't listed are left alone.
    'volumeVideoLibraries' => [
        //'videos' => 'default',
    ],

    // Whether asset.url should return the Bunny playback URL for Bunny Stream videos
    'overrideAssetUrls' => true,

    // Whether to purge changed files from the CDN cache on a Bunny Storage filesystem.
    // Individual URLs are purged, never the whole pull zone.
    'purgeEnabled' => true,
];

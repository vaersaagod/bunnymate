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
        //    // Set to true if Bunny Optimizer is enabled on the library's pull zone, so
        //    // control panel thumbnails can be resized rather than served full size
        //    'optimizerEnabled' => false,
        //],
    ],
    // Maps volume handles to video library handles. Volumes that aren't listed are left alone.
    'volumeVideoLibraries' => [
        //'videos' => 'default',
    ],

    // Whether videos uploaded to a mapped volume any way other than the Bunny Video Upload
    // utility should be sent to Bunny Stream automatically. Bunny pulls the file from the
    // asset's URL, so the volume must be reachable from the public internet.
    'autoUploadVideos' => true,

    // Whether asset.url should return the Bunny playback URL for Bunny Stream videos
    'overrideAssetUrls' => true,

    // Whether to purge changed files from the CDN cache on a Bunny Storage filesystem.
    // Individual URLs are purged, never the whole pull zone.
    'purgeEnabled' => true,
];

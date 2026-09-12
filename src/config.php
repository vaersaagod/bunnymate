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
    // Maps volume handles to video library handles, and is what turns Bunny Stream on: a
    // volume that isn't listed is left alone entirely. Videos uploaded to a mapped volume go
    // straight from the browser to Bunny, whatever filesystem the volume uses.
    'volumeVideoLibraries' => [
        //'videos' => 'default',
    ],

    // Whether videos that reach a mapped volume without going through the uploader (an import,
    // a programmatic save, or the uploader falling back) should be sent to Bunny Stream
    // automatically. Bunny pulls the file from the asset's URL, so this path only works for
    // volumes reachable from the public internet.
    'autoUploadVideos' => true,

    // Whether to write an empty placeholder file for videos uploaded straight to Bunny, so
    // Craft's asset indexer doesn't report them as missing
    'writePlaceholderFiles' => true,

    // Whether to resize control panel thumbnails with Imager X, when it's installed
    'transformThumbnails' => true,

    // Whether asset.url should return the Bunny playback URL for Bunny Stream videos
    'overrideAssetUrls' => true,

    // Whether to purge changed files from the CDN cache on a Bunny Storage filesystem.
    // Individual URLs are purged, never the whole pull zone.
    'purgeEnabled' => true,
];

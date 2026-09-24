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
        //    // The pull zone's security key, needed when "CDN token authentication" is
        //    // enabled on the library. Every playback URL is then signed.
        //    'tokenAuthKey' => '$BUNNY_STREAM_TOKEN_KEY',
        //    // Set to true when "Embed view token authentication" is enabled on the library.
        //    // That's a separate switch, guarding the iframe player rather than the playback
        //    // files. Signed differently, but with the same key, so tokenAuthKey is required.
        //    'playerTokenAuthEnabled' => false,
        //    // How long signed URLs stay valid: a number of seconds, or a date interval
        //    // string. Any cache holding a page with signed URLs in it that Craft doesn't
        //    // render itself must expire well inside this.
        //    'signedUrlDuration' => 'PT1H',
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

    // How many videos the control panel uploader sends to Bunny Stream at once. The rest of a
    // batch is queued. One at a time gets each video to Bunny, and encoding, soonest.
    'maxConcurrentUploads' => 1,

    // Where to load hls.js from, for adaptive playback outside Safari. Null never loads it,
    // and the player falls back to an MP4 rendition instead.
    'hlsJsUrl' => 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js',

    // Bounds for adaptive playback, e.g. '720p'. HLS only: with MP4 the rendition is chosen
    // outright, so there's nothing to bound.
    'defaultMinResolution' => null,
    'defaultMaxResolution' => null,

    // Whether rendered video tags hold their sources in `data-src` until scrolled into view.
    // On by default: getBunnyVideoTag() renders a self-contained player, so nothing outside it
    // is waiting to call play(). Turn it off where something else drives playback — though
    // that case is better served by building the element from mp4Sources().
    // Unrelated to hls.js, which is always attached on approach.
    'lazyloadBunnyVideo' => true,

    // Whether to defer signing playback URLs until the response is prepared, so an expiring
    // token is never written into a {% cache %} block. Only affects libraries with token
    // authentication enabled. It substitutes the response body only, so a URL rendered into
    // something that doesn't travel in the response -- an email, say -- keeps its placeholder.
    'deferSignedUrls' => false,

    // Whether original files can be downloaded from the front end. Originals aren't capped the
    // way the MP4 renditions are, so this serves the full-resolution master to anyone who asks.
    'allowOriginalDownloads' => true,

    // Whether to resize control panel thumbnails with Imager X, when it's installed
    'useImagerForThumbnailTransforms' => true,

    // Passed straight to Imager X's transformImage() when transforming a thumbnail:
    // imagerTransformDefaults as its third argument, imagerTransformConfigOverrides as its
    // fourth. Defaults sit under the transform BunnyMate builds, so the width and height Craft asked
    // for always win, while `mode` (which defaults to 'crop'), `position`, `format`, `quality`
    // and the rest are yours. Config overrides take precedence over BunnyMate's own, except
    // that `curlOptions` merges key by key so the referrer Bunny requires isn't lost.
    'imagerTransformDefaults' => null,
    'imagerTransformConfigOverrides' => null,

    // Which MP4 rendition asset.url should point at, e.g. '1080p'. Null uses the highest one
    // Bunny produced. A video without that exact rendition falls back to the closest one below.
    'videoUrlRendition' => null,

    // Whether asset.url should return the Bunny playback URL for Bunny Stream videos
    'overrideAssetUrls' => true,

    // Whether to purge changed files from the CDN cache on a Bunny Storage filesystem.
    // Individual URLs are purged, never the whole pull zone.
    'purgeEnabled' => true,
];

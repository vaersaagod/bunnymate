# BunnyMate

Keeping your bunny finely-tuned for a hoppy life, mate!

<img src="https://github.com/vaersaagod/bunnymate/blob/main/src/icon.svg" width="200" height="200" alt="Logo">

## Description

BunnyMate integrates [BunnyCDN](https://bunny.net) with Craft CMS.

## Requirements

This plugin requires Craft CMS 5.1.0 or later, and PHP 8.3 or later.

## Disclaimer

This is a [private plugin](https://craftcms.com/docs/5.x/extend/plugin-guide.html#private-plugins), made for Værsågod and friends.

## Migrating from `vaersaagod/bunny`  

1. `ddev craft plugin/uninstall bunny && ddev composer remove vaersaagod/bunny`
2. `ddev composer require vaersaagod/bunnymate && ddev craft plugin/install _bunnymate`
3. Rename `config/bunny.php` to `config/_bunnymate.php`

## Configuration

```
<?php

return [
    'pullingEnabled' => true,
    'pullZones' => [
        'default' => [
            'hostname' => 'https://awesome-project.b-cdn.net',
            'enabled' => true,
        ],
    ],
    'defaultPullZone' => 'default',

    // Account API key from the Bunny dashboard, used to purge the CDN cache.
    // This is not a storage zone password.
    'apiKey' => '$BUNNY_API_KEY',

    // Whether to purge the CDN cache when assets change on a Bunny Storage filesystem
    'purgeEnabled' => true,
];
```

## Usage

BunnyMate provides a global Twig function `bunnyPullUrl()`, which can be used to generate a Bunny CDN pullzone URL:  

```
{% set bunnyUrl = bunnyPullUrl(asset) %}
{% set bunnyUrl = bunnyPullUrl(siteUrl('lorem/ipsim') %}
{% set bunnyUrl = bunnyPullUrl('lorem/ipsum') %}
{% set bunnyUrl = bunnyPullUrl('/lorem/ipsum', 'anotherZone') %}
```

## Bunny Storage filesystem

BunnyMate provides a `Bunny Storage` filesystem type, which stores assets in a [Bunny Edge Storage](https://bunny.net/storage/) zone and serves them over a pull zone.

Create it under **Settings &rarr; Filesystems**, and configure:

| Setting | Notes |
| --- | --- |
| Storage Zone | The Edge Storage zone name. |
| Access Key | The storage zone's password, found under **FTP & API Access** in the Bunny dashboard. Not the account API key. |
| Region | The region the storage zone was created in. Getting this wrong causes every request to fail. |
| Subfolder | Optional path within the storage zone to use as the filesystem root. |
| Pull Zone | Which of the pull zones from `config/_bunnymate.php` serves this storage zone. |

Note that the filesystem has no **Base URL** field. Asset URLs are built from the selected pull zone's `hostname`, plus the subfolder, so the hostname is configured in one place and shared with `bunnyPullUrl()`.

The `pullingEnabled` and per-zone `enabled` settings do not apply to the filesystem. Those toggles let `bunnyPullUrl()` fall back to the site's own origin, but files in a storage zone have no origin to fall back on, so stripping their URLs would break them.

### Cache purging

When `apiKey` is set and `purgeEnabled` is `true`, BunnyMate purges files from the CDN cache whenever assets on a Bunny Storage filesystem are added, replaced, copied, moved or deleted.

Only the affected URLs are purged, via Bunny's [single URL purge endpoint](https://bunny.net/docs/reference/purgepublic_indexpost). The pull zone is never purged as a whole. Deleting a folder purges it with a trailing slash, which Bunny treats as a wildcard, so everything beneath it is cleared too.

Paths are collected over the course of a request and handed to a single queue job afterwards, so bulk operations such as renaming a folder don't fire one blocking HTTP request per file.

`apiKey` is the **account** API key from the Bunny dashboard, not a storage zone password. Set `purgeEnabled` to `false` to leave the edge cache to expire on the pull zone's own TTL.

## Bunny Stream

BunnyMate can back Craft video assets with [Bunny Stream](https://bunny.net/stream/), so videos are transcoded and delivered by Bunny while remaining ordinary Craft assets.

Unlike a custom field type, nothing is added to an asset field layout and no video metadata is stored in the content table. The mapping between assets and Bunny videos lives in the `bunnymate_videos` table, and is exposed through a behavior.

### Configuration

```php
'videoLibraries' => [
    'default' => [
        'id' => '$BUNNY_STREAM_LIBRARY_ID',
        'apiKey' => '$BUNNY_STREAM_API_KEY',
        'readOnlyApiKey' => '$BUNNY_STREAM_READONLY_KEY',
        'hostname' => 'vz-xxxxxxxx-xxx.b-cdn.net',
        // Only if token authentication is enabled on the library's pull zone
        'tokenAuthKey' => '$BUNNY_STREAM_TOKEN_KEY',
    ],
],
'defaultVideoLibrary' => 'default',

// Volumes not listed here are left alone
'volumeVideoLibraries' => [
    'videos' => 'default',
],
```

The `apiKey` grants write access to the library, so it must never reach the browser. The `readOnlyApiKey` doubles as the webhook signing secret.

### Webhook

Add this URL under **Webhook URL** in the video library's settings in the Bunny dashboard:

`https://your-site.com/bunnymate/webhook`

Every payload is verified as an HMAC-SHA256 of the raw request body, keyed on the library's read-only API key, so an unsigned or incorrectly signed request is rejected with a 403. Local environments need a publicly reachable URL, via `ddev share`, ngrok or similar.

### Twig

```twig
{% set video = asset.bunnyVideo %}

{% if video and video.isReady %}
    <video poster="{{ video.thumbnailUrl }}" controls>
        <source src="{{ video.hlsUrl }}" type="application/x-mpegURL">
        <source src="{{ video.mp4Url('720p') }}" type="video/mp4">
    </video>
{% elseif video and video.isFailed %}
    <p>{{ "This video could not be processed."|t }}</p>
{% elseif video %}
    <p>{{ "Processing: {progress}%"|t({ progress: video.encodeProgress }) }}</p>
{% endif %}
```

| Property | Notes |
| --- | --- |
| `isReady` / `isFailed` | Whether the video is playable, or failed to encode |
| `status` | A `VideoStatus` enum case; `status.label()` for a readable name |
| `hlsUrl` | HLS playlist. Null until playable. |
| `mp4Url(resolution)` | MP4 rendition. Needs MP4 fallback enabled; highest available if no resolution given. |
| `thumbnailUrl` | Poster frame. Available before encoding finishes. |
| `previewUrl` | Animated WebP preview |
| `embedUrl(params)` | Bunny's iframe player URL |
| `width`, `height`, `length`, `encodeProgress`, `availableResolutions` | Metadata from Bunny |

### Asset URLs

Assets backed by Bunny Stream hold no file of their own, so `asset.url` would otherwise resolve to a path that 404s. BunnyMate overrides it via `Asset::EVENT_BEFORE_DEFINE_URL`: a video asset returns its HLS URL, and a transform request returns the poster frame instead. Set `overrideAssetUrls` to `false` to opt out.

### Known limitations

Because these assets have no file on the filesystem, running **Update Asset Indexes** on a Bunny Stream volume reports them as missing.

An asset that is moved to the trash and later purged by garbage collection leaves its Bunny video behind, since Craft's GC deletes elements with raw SQL and fires no element events. Deleting an asset outright removes the Bunny video correctly.

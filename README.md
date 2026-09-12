# BunnyMate

Keeping your bunny finely-tuned for a hoppy life, mate!

<img src="https://github.com/vaersaagod/bunnymate/blob/main/src/icon.svg" width="200" height="200" alt="Logo">

## Description

BunnyMate integrates [Bunny](https://bunny.net) with Craft CMS. It does four things, and they can be used together or on their own:

**Pull zone URLs.** A `bunnyPullUrl()` Twig function that rewrites any path or asset URL to a Bunny pull zone, so existing files are served from the CDN without moving them. Several pull zones can be configured and picked between per call. → [Usage](#usage)

**A Bunny Storage filesystem.** Assets stored in a [Bunny Edge Storage](https://bunny.net/storage/) zone and served over the pull zone that fronts it. Asset URLs come from the pull zone config rather than a per-filesystem base URL, and changed files are purged from the CDN automatically. → [Bunny Storage filesystem](#bunny-storage-filesystem)

**Video streaming.** Video assets backed by [Bunny Stream](https://bunny.net/stream/), transcoded and delivered by Bunny while staying ordinary Craft assets. Uploads go straight from the browser to Bunny over a resumable protocol, so PHP's upload limits don't apply, and a signed webhook keeps encoding status in sync. No custom field type: playback URLs, poster frames and metadata hang off `asset.bunnyVideo`. → [Bunny Stream](#bunny-stream)

**CDN cache purging.** Changed files are purged from the edge when assets are added, replaced, moved or deleted, so a replaced file doesn't keep serving its old copy until the TTL expires. → [Cache purging](#cache-purging)

Everything is configured in `config/_bunnymate.php`, with every credential resolvable from an environment variable.

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

// Volumes not listed here are left alone
'volumeVideoLibraries' => [
    'videos' => 'default',
],
```

The key each library is listed under (`default` above) is a local name, referred to by `volumeVideoLibraries`. The library itself is identified by its `id`.

`volumeVideoLibraries` maps Craft volume handles to those names, and is what turns Bunny Stream on: a volume that isn't listed is left alone entirely.

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
| `isMissing` | Whether Bunny no longer has the video. See [Videos deleted on Bunny](#videos-deleted-on-bunny). |
| `status` | The raw `VideoStatus` enum case from Bunny |
| `statusLabel` | A readable state, derived from encoding progress. See [Video status](#video-status). |
| `hlsUrl` | HLS playlist. Null until playable. |
| `mp4Url(resolution)` | MP4 rendition. Needs MP4 fallback enabled; highest available if no resolution given, and an unavailable one falls back to the closest below it. |
| `thumbnailUrl(width, height)` | Poster frame. Available before encoding finishes. Dimensions only apply when `optimizerEnabled` is set. |
| `previewUrl` | Animated WebP preview |
| `embedUrl(params)` | Bunny's iframe player URL |
| `width`, `height`, `length`, `encodeProgress` | Metadata from Bunny |
| `availableResolutions` | Every rendition Bunny encoded for HLS, ascending, e.g. `['240p', '360p', …]`. The sidebar panel only shows the highest. |
| `availableMp4Resolutions` | Those an MP4 exists for, which is a subset. See [Renditions](#renditions). |

### Video status

Bunny reports a video's state as a numeric status, sent on the webhook and returned by the API. BunnyMate maps these onto the `VideoStatus` enum, available as `asset.bunnyVideo.status`:

| Code | Case | Meaning |
| --- | --- | --- |
| -1 | `Missing` | Not one of Bunny's codes. Set when Bunny turns out to no longer have the video. |
| 0 | `Queued` | Waiting to be encoded |
| 1 | `Processing` | Working out preview and format details |
| 2 | `Encoding` | Encoding |
| 3 | `Finished` | Encoding complete, every rendition available |
| 4 | `ResolutionFinished` | One rendition available, so the video plays while encoding continues |
| 5 | `Failed` | Encoding failed |
| 6 | `PresignedUploadStarted` | A TUS upload began |
| 7 | `PresignedUploadFinished` | A TUS upload completed |
| 8 | `PresignedUploadFailed` | A TUS upload failed |
| 9 | `CaptionsGenerated` | Automatic captions finished |
| 10 | `TitleOrDescriptionGenerated` | Automatic title or description finished |

Three things about these are worth knowing, because none of them are obvious from the numbers.

**9 and 10 arrive after a video is finished.** They report generated metadata, not encoding progress, so treating them as lifecycle states would move a finished video backwards. `VideoStatus::isLifecycle()` marks them as not-lifecycle, and BunnyMate refreshes a video's metadata on those webhooks without touching its status.

**Bunny's status settles on 4, not 3.** A fully encoded video with every rendition still reports `ResolutionFinished` from the API. `Finished` only ever arrives as a passing webhook, so whether a video ends up stored as 3 or 4 is a matter of which webhook landed last. Both mean the video plays.

**So use `statusLabel`, not `status`, for display.** It reports encoding progress once a video is playable, which is stable where the status code isn't:

| Status | `encodeProgress` | `statusLabel` | `isReady` |
| --- | --- | --- | --- |
| `Queued` | 0% | Queued | `false` |
| `Encoding` | 45% | Encoding | `false` |
| `ResolutionFinished` | 60% | Playable | `true` |
| `ResolutionFinished` | 100% | Ready | `true` |
| `Finished` | 100% | Ready | `true` |
| `Failed` | — | Failed | `false` |

`isReady` is true for 3 and 4, since either means the video plays, and also for 9 and 10, which can only arrive once encoding has finished. `isFailed` covers 5 and 8. In templates:

```twig
{% if video.isReady %}
    {# plays, though it may still be encoding higher renditions #}
{% elseif video.isFailed %}
    {# 5 or 8 #}
{% else %}
    {{ video.statusLabel }} ({{ video.encodeProgress }}%)
{% endif %}
```

### Renditions

Bunny only encodes up to the source resolution, so renditions are per video: a 720p upload never has a 1080p rendition, and two videos in the same library can offer different sets. `availableResolutions` is what Bunny actually produced, ascending.

**`availableResolutions` describes the HLS renditions, not the MP4 ones.** Bunny's MP4 fallback stops short of what it encodes for HLS: a video listing renditions up to 2160p may only have MP4s up to 1080p, and `play_2160p.mp4` then returns a 404. Nothing in the API says where the cut is, so the library's `mp4MaxRendition` setting decides it, defaulting to `1080p`:

```php
'mp4MaxRendition' => '1080p',
```

`mp4Url()` and `videoUrlRendition` resolve against that capped list. A rendition that isn't in it falls back to the closest one below, or to the lowest available if the request was below everything on offer:

| Available | Asked for | Returns |
| --- | --- | --- |
| `240p,360p,480p,720p,1080p` | — | `1080p` |
| `240p,360p,480p,720p,1080p` | `720p` | `720p` |
| `240p,360p,720p` | `1080p` | `720p` |
| `720p,1080p` | `240p` | `720p` |

`mp4Url()` returns null only when a video has no MP4 renditions at all, which means MP4 fallback is off for the library, or every rendition it encoded is above `mp4MaxRendition`.

Use `availableMp4Resolutions` when you need the renditions an MP4 actually exists for, and `availableResolutions` for what Bunny encoded.

### Asset URLs

Assets backed by Bunny Stream hold no file of their own, so `asset.url` would otherwise resolve to a path that 404s. BunnyMate overrides it via `Asset::EVENT_BEFORE_DEFINE_URL`, and a transform request returns the poster frame instead. Set `overrideAssetUrls` to `false` to opt out.

`asset.url` returns an **MP4** rendition rather than the HLS playlist, because this URL is what ends up in plain `<video>` elements, including Craft's own on the asset edit screen, and only Safari plays HLS natively. Templates that want adaptive streaming should ask for `asset.bunnyVideo.hlsUrl`, and that needs a player like hls.js outside Safari.

Which rendition is set by `videoUrlRendition`, which defaults to the highest one Bunny produced:

```php
'videoUrlRendition' => '1080p',
```

Renditions are per video: Bunny only encodes up to the source resolution, so a 720p upload never has a 1080p rendition. Rather than break, a rendition that wasn't produced falls back to the closest one below it, or to the lowest available if the request was below everything on offer. Bunny's own list of available resolutions is what's consulted, so neither `asset.url` nor `mp4Url()` ever returns a URL that 404s.

### Uploading

Videos uploaded to a mapped volume through the regular **Assets** screen go straight from the browser to Bunny over the [TUS](https://tus.io) resumable protocol. The file never passes through PHP, so `upload_max_filesize`, `post_max_size` and request timeouts don't apply, and an interrupted upload resumes rather than restarting.

This works by registering a custom uploader with Craft, via `Craft.registerUploaderClass()`. Craft dispatches uploaders by filesystem class, so BunnyMate registers for every filesystem used by a mapped volume. Volumes that share that filesystem but aren't mapped to a library are unaffected, and non-video files are always handled by Craft's own uploader.

Craft handles two small requests per file: one to create the video and its asset and hand back a signed upload credential, and one to refresh metadata when the upload finishes. The library's API key never reaches the browser. The signature is `SHA256(libraryId + apiKey + expires + videoGuid)`, scoped to a single video and expiring on its own.

Only volumes listed in `volumeVideoLibraries` upload this way, and only for users with `saveAssets` permission on them.

Uploaded videos are named `.mp4` whatever the source file was. The source is never stored, since Bunny keeps it and serves MP4 and HLS, and Craft derives an asset's MIME type from its extension, so a `.mov` asset would advertise `video/quicktime` for an MP4 URL and browsers would refuse to play it.

#### Videos that arrive some other way

A video that reaches a mapped volume by any other route, a normal CP upload, a feed import, or a programmatic save, is also sent to Bunny, by asking Bunny to fetch it from the asset's URL. That means the volume has to be reachable from the public internet: a local filesystem on a dev machine can't be pulled from, and the job will log an error. A volume on the Bunny Storage filesystem works well here, since the file is already on Bunny's network.

Replacing a video's file deletes the old Bunny video and sends the new file up in its place.

Set `autoUploadVideos` to `false` to turn this off and rely on the uploader alone.

### The Bunny Stream panel

Video assets backed by Bunny Stream get a **Bunny Stream** panel in their edit screen sidebar, showing encoding status, available resolutions, dimensions, duration and the video's GUID.

It also carries a **Refresh from Bunny** button, which pulls the video's current state down from Bunny on demand. The webhook normally keeps this in step, so the button is for when it doesn't: no webhook URL configured, an environment Bunny can't reach, or a delivery that was missed.

### Control panel thumbnails

Video assets show Bunny's poster frame as their control panel thumbnail, instead of the generic file-type icon Craft would otherwise use. Craft can't generate one itself: the assets uploaded over TUS have no file at all, and the rest are videos, which its image drivers can't open.

Bunny serves the poster frame at full resolution, and only resizes from the URL when **Bunny Optimizer** is enabled on the pull zone. Left alone, a 4K video would mean a 4K JPEG for every thumbnail.

So where [Imager X](https://imager-x.spacecat.ninja) is installed, BunnyMate resizes with that instead. The frame is fetched once, transformed to whatever size Craft asked for and cached locally: a 120px thumbnail from a 4K poster comes out around 3KB rather than 150KB. Set `transformThumbnails` to `false` to turn this off.

Imager downloads over curl, which sends no referrer, and Bunny libraries block referrer-less requests by default. BunnyMate passes the site's own URL as the referrer so this works either way.

Without Imager, the poster frame is used as Bunny serves it. If you have Bunny Optimizer enabled on the pull zone, set `optimizerEnabled` to `true` on the library config and BunnyMate will ask Bunny for the size Craft wanted.

Video thumbnails are also marked with a play icon, since a poster frame is a still image and would otherwise be indistinguishable from a photo in an asset index. A video that's still encoding has no usable poster yet, so it gets a plain placeholder with a spinner over it rather than a file-type icon that gives no sign anything is happening. Videos with no Bunny video are left alone.

Previewing a video asset in the control panel opens Bunny's player, rather than Craft's default `<video>` element, which can't play an HLS playlist in most browsers.

If MuxMate is also installed, it claims the preview for every video asset whether or not it has a Mux video, and Craft takes the last handler registered. Uninstall MuxMate, or guard its preview handler, or Bunny videos will preview as "No Mux playback ID".

### Non-video files

Only video files are sent to Bunny Stream. An image, PDF or anything else uploaded to a mapped volume is stored in that volume exactly as it would be otherwise, with transforms and asset URLs untouched. On a volume using the Bunny Storage filesystem, that means it's served over the pull zone like any other file.

### Videos deleted on Bunny

Deleting a video in Bunny's dashboard fires no webhook, so Craft has no way to hear about it. Worse, the asset carries on looking fine for a while, because the CDN edge and any cached thumbnails still hold copies. Once those expire, every URL 404s.

Pressing **Refresh from Bunny** on the asset finds out. When Bunny no longer has the video, the panel says **Missing from Bunny** and the asset stops pretending: `asset.bunnyVideo.isMissing` is true, `isReady` is false, the control panel thumbnail reverts to a file-type icon and `asset.url` stops returning a Bunny URL.

The record is kept rather than dropped, so the asset says what happened instead of quietly looking like an ordinary video with no file. Re-uploading is the fix; there's nothing to recover, since Bunny held the only copy.

### Access control

New Bunny video libraries ship with **Block direct URL file access** enabled, which rejects any request that arrives without a `Referer` header.

This is hotlink protection, not access control. A `Referer` header is set by whoever makes the request, so anyone who wants the file can send one and get it. What the setting reliably does is break legitimate consumers that don't send a referrer: sites using `Referrer-Policy: no-referrer`, native apps, and any server-side fetch.

If videos need to be genuinely protected, enable **token authentication** on the library's pull zone and set `tokenAuthKey` in the library's config. BunnyMate then signs every playback and embed URL with an expiring token, and `signedUrlDuration` controls how long each one is valid. That is a real access control; the referrer check is not.

With both off, playback URLs are public to anyone holding them. Video GUIDs are random UUIDs, so they aren't guessable, which is usually fine for non-sensitive content.

### Placeholder files

Videos uploaded straight to Bunny have no bytes in the volume, which Craft's asset indexer treats as a missing file. That matters more than it sounds: the indexer's review dialog pre-selects every missing asset and its primary button deletes them, which would take the Bunny videos with them.

So an empty file is written at the asset's path when the upload starts, purely to give the indexer something to match. It has to be the asset's own filename, since a placeholder under any other name would itself be indexed as a new file.

The cost is that anything reading the asset's own file gets an empty one. Playback, thumbnails and metadata all come from Bunny, so they're unaffected. Set `writePlaceholderFiles` to `false` to turn this off.

### Known limitations

Deleting an asset outright removes its Bunny video. Moving one to the trash deliberately doesn't, since a trashed asset can be restored and a deleted video couldn't be: the video stays until the asset is either restored or purged for good.

When Craft's garbage collection eventually purges it, the video is deleted too. That needs a little care, because GC deletes elements with raw SQL and fires no element events, and `Gc::run()` hard-deletes before it fires `EVENT_RUN`. So the videos table's foreign key is `ON DELETE SET NULL` rather than a cascade: purging an asset leaves the row behind with its video ID intact and a null `assetId`, which is an unambiguous marker that the video outlived its asset. BunnyMate clears those on `Gc::EVENT_RUN`, which runs immediately afterwards.

That distinction matters. A null row is definitely a purged asset's video, never a video someone uploaded through Bunny's dashboard, so nothing has to guess and nothing needs scheduling. A video that fails to delete keeps its row and is retried on the next run.

If the uploader can't confirm in time that a folder is set up for Bunny Stream, it lets Craft handle the upload normally. The video still reaches Bunny via the fetch fallback, just without bypassing PHP's upload limits.

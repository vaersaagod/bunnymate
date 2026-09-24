# BunnyMate

Keeping your bunny finely-tuned for a hoppy life, mate!

<img src="https://github.com/vaersaagod/bunnymate/blob/main/src/icon.svg" width="200" height="200" alt="Logo">

## Description

BunnyMate integrates [Bunny](https://bunny.net) with Craft CMS. It does four things, and they can be used together or on their own:

**Pull zone URLs.** A `bunnyPullUrl()` Twig function that rewrites any path or asset URL to a Bunny pull zone, so existing files are served from the CDN without moving them. Several pull zones can be configured and picked between per call. → [Usage](#usage)

**A Bunny Storage filesystem.** Assets stored in a [Bunny Edge Storage](https://bunny.net/storage/) zone and served over the pull zone that fronts it. Asset URLs come from the pull zone config rather than a per-filesystem base URL, and changed files are purged from the CDN automatically. → [Bunny Storage filesystem](#bunny-storage-filesystem)

**Video streaming.** Video assets backed by [Bunny Stream](https://bunny.net/stream/), transcoded and delivered by Bunny while staying ordinary Craft assets. Uploads go straight from the browser to Bunny over a resumable protocol, so PHP's upload limits don't apply, and a signed webhook keeps encoding status in sync. No custom field type: playback URLs, poster frames and metadata hang off `asset.bunnyVideo`, asset queries take a `bunnyVideo()` parameter, and `asset.getBunnyVideoTag()` renders a player. → [Bunny Stream](#bunny-stream)

**CDN cache purging.** Changed files are purged from the edge when assets are added, replaced, moved or deleted, so a replaced file doesn't keep serving its old copy until the TTL expires. → [Cache purging](#cache-purging)

Everything is configured in `config/bunnymate.php`, with every credential resolvable from an environment variable.

## Requirements

This plugin requires Craft CMS 5.1.0 or later, and PHP 8.3 or later.

## Migrating from BunnyMate 2.x

BunnyMate 3.0 is no longer a private plugin: the handle changed from `_bunnymate` to `bunnymate`.

It's the same Composer package, so Composer simply upgrades it — nothing to remove or re-require there. Craft is the part that cares: it keys installed plugins by handle, in the `plugins` table and in project config, and a changed handle reads to Craft as one plugin disappearing and a different one showing up. So the old handle needs uninstalling and the new one installing, at Craft's level only.

Nothing is at risk in the process. BunnyMate 2.x shipped no migrations and owned no database tables, so uninstalling it loses nothing beyond its row in the `plugins` table.

1. `ddev craft plugin/uninstall _bunnymate` — Craft only; the package stays where it is
2. `ddev composer require vaersaagod/bunnymate:^3.0` — upgrades in place
3. Rename `config/_bunnymate.php` to `config/bunnymate.php`
4. `ddev craft plugin/install bunnymate`

**Do step 1 before step 2**, so the uninstall runs against a plugin Craft can still load. If you've already upgraded, it's recoverable — see below.

Settings carry over untouched: `pullingEnabled`, `pullZones` and `defaultPullZone` mean exactly what they meant in 2.x, and `bunnyPullUrl()` is unchanged. Templates need no edits. Everything else in 3.0 — the Bunny Storage filesystem, Bunny Stream — is additive and off until configured.

Steps 1 and 4 both write to project config, so committing `project.yaml` carries the change to your other environments: applying it there removes `_bunnymate` and installs `bunnymate` without further intervention.

### If you've already upgraded

If Composer upgraded before you ran step 1, `_bunnymate` is left behind in the `plugins` table and in project config, and Craft can no longer load it. Pass `--force`, which lets Craft uninstall a plugin it can't load:

```
ddev craft plugin/uninstall _bunnymate --force
ddev craft plugin/install bunnymate
```

That clears the `plugins` row, the plugin's migration track and its project config entry. The only thing it skips is the plugin's own uninstall migration, since there's no class left to run it — which costs nothing here, because 2.x had no migrations and created no tables.

## Migrating from `vaersaagod/bunny`  

1. `ddev craft plugin/uninstall bunny && ddev composer remove vaersaagod/bunny`
2. `ddev composer require vaersaagod/bunnymate && ddev craft plugin/install bunnymate`
3. Rename `config/bunny.php` to `config/bunnymate.php`

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
| Pull Zone | Which of the pull zones from `config/bunnymate.php` serves this storage zone. |

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
| `mp4Sources(map)` | MP4 sources for a player that manages `src` itself. See [Driving playback yourself](#driving-playback-yourself). |
| `thumbnailUrl(width, height)` | Poster frame. Available before encoding finishes. Dimensions only apply when `optimizerEnabled` is set. |
| `previewUrl` | Animated WebP preview |
| `embedUrl(params)` | Bunny's iframe player URL |
| `hasOriginal` / `originalUrl` | The uploaded file itself, played in the browser. See [The original file](#the-original-file). |
| `downloadUrl` | The same file, served as a download with its original filename |
| `originalFilename` | What the file was uploaded as, before being renamed to `.mp4` |
| `width`, `height`, `length`, `encodeProgress` | Metadata from Bunny |
| `library` / `libraryHandle` | The library the video lives in. Recorded by Bunny ID, so renaming a handle in `videoLibraries` doesn't strand existing videos. |
| `availableResolutions` | Every rendition Bunny encoded for HLS, ascending, e.g. `['240p', '360p', …]`. The sidebar panel only shows the highest. |
| `availableMp4Resolutions` | Those an MP4 actually exists for, measured rather than assumed. See [Renditions](#renditions). |

### The player

`asset.getBunnyVideoTag()` renders a `<video>` element for a ready video, and returns null for anything else, so it doubles as the "is this playable" check:

```twig
{{ asset.getBunnyVideoTag() }}
```

What comes out is a plain `<video>` with two sources — the HLS playlist and an MP4 rendition — and an `aspect-ratio` style so the page doesn't jump once metadata arrives. There's no poster unless you ask for one with `poster: true`, since a poster is a whole extra image request and templates that want one usually want it transformed. With JavaScript off, or blocked, iOS plays the HLS source natively and everything else falls back to the MP4 — the tag degrades on its own. Where `hlsJsUrl` is set, a small script attaches hls.js as the video approaches the viewport, giving adaptive playback everywhere hls.js can run. It's only registered when the tag actually needs it.

```twig
{# A muted background loop: MP4 only, because for a silent loop a fixed rendition
   starts sooner and needs no player code #}
{{ asset.getBunnyVideoTag({ inline: true, hls: false, resolution: '360p' }) }}
```

| Option | Default | Notes |
| --- | --- | --- |
| `inline` | `false` | Sets `autoplay`, `muted`, `loop` and drops `controls`, for a background loop |
| `hls` | `true` | Set to false to emit only the MP4 source, and skip the player script |
| `lazyload` | `lazyloadBunnyVideo` (`true`) | Holds the sources in `data-src` until the element scrolls into view. See [Lazyloading](#lazyloading). |
| `resolution` | `videoUrlRendition` | Which MP4 rendition to use as the fallback source |
| `minResolution` / `maxResolution` | `defaultMinResolution` / `defaultMaxResolution` | Bounds on the levels hls.js may pick, measured on the short side, so `'720p'` means 1280&times;720 or 720&times;1280 as the video requires. Adaptive playback only: they do nothing when `hls` is false, or on a browser without Media Source Extensions, where HLS plays natively and there's no hls.js to cap. |
| `poster` | `false` | `true` for Bunny's poster frame, or a URL to use instead |
| `controls`, `playsinline`, `preload`, `autoplay`, `muted`, `loop` | — | Passed through to the element |
| `attributes` | — | Merged over everything above |
| `nonce` | — | Applied to the player script tag, for a strict CSP |

The return value is `Markup`, so Craft's `|attr` filter can add to it after the fact:

```twig
{{ asset.getBunnyVideoTag({ inline: true })|attr({ class: 'teaser__video' }) }}
```

#### Lazyloading

`lazyload` governs one thing: whether the `<source>` elements render with `src` or `data-src`. It's **on** by default, because this tag renders a self-contained player — nothing outside it is waiting to play the video, so deferring the media until it's needed is a straight win.

hls.js is a separate matter, and is attached as the video approaches the viewport whether or not the tag is lazyloaded. It starts buffering the moment it attaches, so attaching it to an off-screen video would only move the download earlier. Nothing above the fold waits for this: an element already in view intersects on the observer's first check.

Turn `lazyload` off only if you need the element to work before any JavaScript has run. If you're turning it off because your own code calls `play()`, that's the wrong tool — see [Driving playback yourself](#driving-playback-yourself).

### Driving playback yourself

`getBunnyVideoTag()` is a standalone player: it owns its sources, its loading and its hls.js. That makes it the wrong shape for a video-loop component — the kind that plays on intersection, pauses off-screen, swaps rendition on a media query and falls back to a still image when playback doesn't start. Such a component needs to own the `src` itself.

For that, skip the tag and take the URLs:

```twig
{% set sources = asset.bunnyVideo.mp4Sources({
    '(max-width: 767px)': '480p',
    '(min-width: 768px)': '1080p',
}) %}

{{ tag('video', {
    class: 'videoloop',
    loop: true,
    muted: true,
    playsinline: true,
    'data-sources': sources|json_encode,
}) }}
```

`mp4Sources()` returns `[{ src, media }]` in the order given, ready to `json_encode`. With no arguments it returns a single source at the highest available rendition. A rendition the video doesn't have falls back to the closest below it, exactly as `mp4Url()` does, so one map works across videos encoded differently. Passing a plain list throws rather than silently producing numeric media queries nothing will match.

Preconnecting to the library's hostname is worth it, since the first request is deferred:

```twig
{% html at head %}
    <link rel="preconnect" href="https://{{ asset.bunnyVideo.library.hostname }}">
{% endhtml %}
```

MP4 renditions stop at 1080p — that's Bunny's MP4 fallback, not a BunnyMate limit. Where a loop has to fill a large viewport, `hlsUrl` carries the full ladder up to the source resolution, at the cost of needing a player.

### Querying

Asset queries take a `bunnyVideo()` parameter, so finding video assets doesn't mean fetching everything and filtering in Twig:

```twig
{% set videos = craft.assets.bunnyVideo('ready').limit(6).all() %}
```

It's an ordinary asset query parameter and chains with the rest:

```twig
{% set videos = craft.assets
    .volume('media')
    .bunnyVideo('ready')
    .orderBy('dateCreated DESC')
    .all() %}
```

| Value | Matches |
| --- | --- |
| `true` (the default) | Assets with a Bunny Stream video, whatever state it's in |
| `false` | Assets without one. Combine with `.kind('video')` for videos that never made it to Bunny. |
| `'ready'` | Playable videos |
| `'encoding'` | Still uploading or encoding |
| `'failed'` | Failed to encode or upload, or gone from Bunny |
| `'missing'` | Bunny no longer has the video. See [Videos deleted on Bunny](#videos-deleted-on-bunny). |
| A `VideoStatus` case, a raw code, or an array of any of the above | Exactly those statuses |

The parameter filters on BunnyMate's own table with a subquery, so it costs one `IN (SELECT …)` and works with `count()`, `exists()`, pagination and eager loading like any other parameter. Anything else throws, rather than quietly matching everything.

This works because BunnyMate attaches a behavior to every asset query through `craft\db\Query::EVENT_DEFINE_BEHAVIORS` — the same mechanism custom fields use, without a field existing.

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

**`availableResolutions` describes the HLS renditions, not the MP4 ones.** Bunny's MP4 fallback stops short of what it encodes for HLS: a video listing renditions up to 2160p may only have MP4s up to 1080p, and `play_2160p.mp4` then returns a 404.

Nothing in the API reports where the cut is. The video payload carries `availableResolutions`, which is the HLS set, and `hasMP4Fallback`, which is a plain boolean. So BunnyMate measures it instead: once a video finishes encoding, it asks Bunny for the renditions from the top down until one answers, and stores the result with the video's metadata. MP4s are produced from the bottom up, so the highest one that exists settles the rest, which makes this a few requests rather than one per rendition. It runs when metadata is refreshed, never per page view.

`availableMp4Resolutions` is therefore measured rather than assumed. Until a video has been measured, renditions above 1080p are assumed absent, which is what Bunny does today.

`mp4Url()` and `videoUrlRendition` resolve against that list. A rendition that isn't in it falls back to the closest one below, or to the lowest available if the request was below everything on offer:

| Available | Asked for | Returns |
| --- | --- | --- |
| `240p,360p,480p,720p,1080p` | — | `1080p` |
| `240p,360p,480p,720p,1080p` | `720p` | `720p` |
| `240p,360p,720p` | `1080p` | `720p` |
| `720p,1080p` | `240p` | `720p` |

`mp4Url()` returns null only when a video has no MP4 renditions at all, which means MP4 fallback is off for the library.

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

Set `tusUploadsEnabled` to `false` on a library to leave its volumes to Craft's own uploader instead. Videos are then stored in the volume like any other file and sent to Bunny as described under [Videos that arrive some other way](#videos-that-arrive-some-other-way), so no [placeholder file](#placeholder-files) is written. That suits a library of smaller clips that fit through PHP's upload limits anyway. It needs `autoUploadVideos` on and the volume reachable from the public internet, or the videos never reach Bunny. They also keep their own extension rather than being renamed to `.mp4`.

```php
'videoLibraries' => [
    'clips' => [
        // ...
        'tusUploadsEnabled' => false,
    ],
],
```

Several videos dropped at once are queued and uploaded one at a time, the way Craft's own uploader handles files. Side by side wouldn't finish the batch any sooner, since every upload shares the same bandwidth, and one at a time gets each video to Bunny, and encoding, as early as possible. Set `maxConcurrentUploads` to allow more at once. Each video's upload credential is only issued when its turn comes, so a long queue can't outlive it.

Uploaded videos are named `.mp4` whatever the source file was. The source is never stored, since Bunny keeps it and serves MP4 and HLS, and Craft derives an asset's MIME type from its extension, so a `.mov` asset would advertise `video/quicktime` for an MP4 URL and browsers would refuse to play it.

#### Videos that arrive some other way

A video that reaches a mapped volume by any other route, a normal CP upload, a feed import, or a programmatic save, is also sent to Bunny, by asking Bunny to fetch it from the asset's URL. That means the volume has to be reachable from the public internet: a local filesystem on a dev machine can't be pulled from, and the job will log an error. A volume on the Bunny Storage filesystem works well here, since the file is already on Bunny's network.

Replacing a video's file deletes the old Bunny video and sends the new file up in its place.

Set `autoUploadVideos` to `false` to turn this off and rely on the uploader alone.

#### Videos that were already there

Auto-upload only catches videos as they arrive. Mapping a volume that already holds videos sends none of them anywhere, and a resave won't either — the handler skips resaves on purpose, so an unrelated `resave/assets` can't push a whole volume to Bunny by accident.

For the back catalogue, there's a command:

```
php craft bunnymate/videos/create-missing
```

It finds video assets in mapped volumes with no Bunny video, reports what it found per volume, and asks before queuing anything. Assets that already have a video are skipped, so it's safe to re-run and safe to interrupt.

| Option | |
| --- | --- |
| `--volume` | Only this volume. Must be one that's mapped to a library. |
| `--limit` | Stop after this many assets, for working through a large volume in batches |
| `--dry-run` | Report what would be queued, and queue nothing |

Bunny fetches each file over HTTP from the asset's own URL, so **this has to run where those URLs are publicly reachable**. A local environment won't do, however correctly it's configured. The command checks a sample URL per volume up front and warns if it looks local, rather than letting a few hundred jobs fail one at a time.

Encoding progress arrives on the webhook as usual, so that needs to be reachable too, or the videos will sit at *Queued* until something refreshes them.

### The Bunny Stream panel

Video assets backed by Bunny Stream get a **Bunny Stream** panel in their edit screen sidebar, showing encoding status, available resolutions, dimensions, duration and the video's GUID.

It also carries a **Refresh from Bunny** button, which pulls the video's current state down from Bunny on demand. The webhook normally keeps this in step, so the button is for when it doesn't: no webhook URL configured, an environment Bunny can't reach, or a delivery that was missed.

### Control panel thumbnails

Video assets show Bunny's poster frame as their control panel thumbnail, instead of the generic file-type icon Craft would otherwise use. Craft can't generate one itself: the assets uploaded over TUS have no file at all, and the rest are videos, which its image drivers can't open.

Bunny serves the poster frame at full resolution, and only resizes from the URL when **Bunny Optimizer** is enabled on the pull zone. Left alone, a 4K video would mean a 4K JPEG for every thumbnail.

So where [Imager X](https://imager-x.spacecat.ninja) is installed, BunnyMate resizes with that instead. The frame is fetched once, transformed to whatever size Craft asked for and cached locally: a 120px thumbnail from a 4K poster comes out around 3KB rather than 150KB. Set `useImagerForThumbnailTransforms` to `false` to turn this off.

Imager downloads over curl, which sends no referrer, and Bunny libraries block referrer-less requests by default. BunnyMate passes the site's own URL as the referrer so this works either way.

`imagerTransformDefaults` and `imagerTransformConfigOverrides` are handed to Imager's `transformImage()` as its third and fourth arguments:

```php
'imagerTransformDefaults' => ['format' => 'webp', 'quality' => 60],
'imagerTransformConfigOverrides' => ['useRemoteUrlQueryString' => true],
```

Defaults sit *under* the transform BunnyMate builds, so the width and height Craft asked for always win — `mode`, which defaults to `crop`, along with `position`, `format`, `quality` and the rest are yours to set. Config overrides take precedence over BunnyMate's own, with one exception: `curlOptions` is merged key by key rather than replaced, so setting an unrelated curl option can't quietly drop the referrer and turn every thumbnail into a 403. Setting `CURLOPT_REFERER` yourself does replace it.

Without Imager, the poster frame is used as Bunny serves it. If you have Bunny Optimizer enabled on the pull zone, set `optimizerEnabled` to `true` on the library config and BunnyMate will ask Bunny for the size Craft wanted.

Video thumbnails are also marked with a play icon, since a poster frame is a still image and would otherwise be indistinguishable from a photo in an asset index. A video that's still encoding has no usable poster yet, so it gets a plain placeholder with a spinner over it rather than a file-type icon that gives no sign anything is happening. Videos with no Bunny video are left alone.

Previewing a video asset in the control panel opens Bunny's player, rather than Craft's default `<video>` element, which can't play an HLS playlist in most browsers.

If MuxMate is also installed, it claims the preview for every video asset whether or not it has a Mux video, and Craft takes the last handler registered. Uninstall MuxMate, or guard its preview handler, or Bunny videos will preview as "No Mux playback ID".

### Non-video files

Only video files are sent to Bunny Stream. An image, PDF or anything else uploaded to a mapped volume is stored in that volume exactly as it would be otherwise, with transforms and asset URLs untouched. On a volume using the Bunny Storage filesystem, that means it's served over the pull zone like any other file.

### The original file

Where a library keeps original files, Bunny serves the upload back untouched, and
`asset.bunnyVideo.originalUrl` points at it. The asset edit screen offers it as a download.

```twig
{% if video.hasOriginal %}
    <a href="{{ video.downloadUrl }}">{{ "Download original"|t }}</a>
{% endif %}
```

Use `downloadUrl` rather than `originalUrl` for anything meant to save the file. Bunny serves originals as `video/mp4` with no `Content-Disposition`, and offers no way to change that: no query parameter sets it and the pull zone has no setting for it. A `download` attribute doesn't help either, since browsers ignore it cross-origin, so `originalUrl` opens the video in the browser rather than saving it.

`downloadUrl` goes through Craft, which sets the header and the filename and streams the file on from Bunny. Because uploads are renamed to `.mp4`, the filename it uses is the one the file was uploaded as, kept alongside the video's metadata; videos uploaded before that was recorded fall back to the asset's filename.

Front-end downloads are governed by `allowOriginalDownloads`. Control panel downloads aren't, and are gated on the asset's own view permission instead.

This matters more than it might look, because assets uploaded straight to Bunny hold no file of their own: the original on Bunny is the only copy of what was uploaded, and this is how to get it back.

Four things to weigh:

Unlike the MP4 renditions, this isn't capped. A 4K upload is served back at 4K, at its full original size, which may be hundreds of megabytes.

Keeping originals roughly doubles what a video costs to store, since the upload sits alongside every rendition.

The URL is guessable from the video's ID, so anyone holding it can download the full-resolution master. Enable token authentication on the library if that matters, and BunnyMate will sign this URL along with the others.

And `downloadUrl` streams through your server rather than straight from the CDN, since that's the only way to set the download header. That's fine for the occasional download, but it means your own bandwidth carries it. Set `allowOriginalDownloads` to `false` to keep that off the front end.

`hasOriginal` is false when the library discards originals after encoding, in which case there's nothing to recover.

### Asset metadata

Assets uploaded straight to Bunny hold no file for Craft to read, so their dimensions, size and modified date would otherwise stay empty and an asset index would look half broken.

Bunny knows all of it, so it's written onto the asset whenever a video's metadata is refreshed: `width` and `height` as soon as Bunny reports them, and `size` and `dateModified` once encoding finishes.

The size reported is the **uploaded file's**, not Bunny's `storageSize`, which counts every rendition alongside the original and is several times larger than anything anyone uploaded. For one 4K video here that's 296 MB against a `storageSize` of 718 MB.

Videos uploaded before this existed fill in on their next refresh, whether that's a webhook or the panel's **Refresh from Bunny** button.

### Videos deleted on Bunny

Deleting a video in Bunny's dashboard fires no webhook, so Craft has no way to hear about it. Worse, the asset carries on looking fine for a while, because the CDN edge and any cached thumbnails still hold copies. Once those expire, every URL 404s.

Pressing **Refresh from Bunny** on the asset finds out. When Bunny no longer has the video, the panel says **Missing from Bunny** and the asset stops pretending: `asset.bunnyVideo.isMissing` is true, `isReady` is false, the control panel thumbnail reverts to a file-type icon and `asset.url` stops returning a Bunny URL.

The record is kept rather than dropped, so the asset says what happened instead of quietly looking like an ordinary video with no file. Re-uploading is the fix; there's nothing to recover, since Bunny held the only copy.

### Uninstalling

Uninstalling BunnyMate drops the `bunnymate_videos` table and nothing else. **The videos stay on Bunny**, encoded and billed as before.

That's deliberate — a local uninstall shouldn't destroy remote content you're paying for, and there'd be no getting it back. BunnyMate says so before it happens, logging a warning that counts the videos it's about to leave behind and in which libraries, and printing it too when the uninstall runs from the console. Three things follow.

The mapping is gone. Every video GUID lived only in that table. On Bunny the videos are identifiable by title alone, which is the asset's filename as it stood when the video was created, so anything renamed since carries its old name. Nothing on Bunny records which asset a video belonged to.

Reinstalling doesn't reconnect them. You get an empty table and assets that look like they've never been to Bunny, so [`create-missing`](#videos-that-were-already-there) would upload every one of them a second time — double the storage, with the originals orphaned and hard to tell from the new copies. If you expect to reinstall, keep a dump of the table.

Nothing prunes them afterwards. Orphan collection works from rows in that table, so once it's gone the videos can only be cleared out from Bunny's dashboard.

### Access control

New Bunny video libraries ship with **Block direct URL file access** enabled, which rejects any request that arrives without a `Referer` header.

This is hotlink protection, not access control. A `Referer` header is set by whoever makes the request, so anyone who wants the file can send one and get it. What the setting reliably does is break legitimate consumers that don't send a referrer: sites using `Referrer-Policy: no-referrer`, native apps, and any server-side fetch.

With it off and nothing else configured, playback URLs are public to anyone holding them. Video GUIDs are random UUIDs, so they aren't guessable, which is usually fine for non-sensitive content.

#### Token authentication

For real access control, enable **CDN token authentication** on the library and set `tokenAuthKey` to the pull zone's security key. BunnyMate then signs every playback URL with an expiring token, and `signedUrlDuration` controls how long each stays valid — a number of seconds, or a date interval string like `'PT1H'`, whichever reads better.

A token is the URL-safe base64 of `SHA256(securityKey + path + expires)`, and it covers exactly the path it was signed over. BunnyMate signs each URL as narrowly as it can: an MP4 rendition's token opens that rendition and nothing else.

HLS is the exception. A master playlist only names per-rendition sub-playlists, and those name segments, each fetched as its own request with no query string inherited from the manifest. So an HLS URL is signed over the video's directory instead, which Bunny extends to everything beneath it, nested paths included. BunnyMate's player then puts the same token back onto every request hls.js makes.

**That makes an HLS token broader than it looks.** It opens every file for that video, the unencoded original among them. Where that matters, serve MP4 renditions rather than HLS, since their tokens are per-file.

There's no equivalent of a signed claim either: a resolution cap is something the markup asks for, never something the CDN enforces.

The library's **Embed view token authentication** is a separate switch, guarding the iframe player at `iframe.mediadelivery.net` rather than the playback files. Set `playerTokenAuthEnabled` to match it; the two are enabled independently, and turning on one doesn't turn on the other.

It's signed differently — a hex digest over the video GUID, where a CDN token is base64 over a path — but keyed on the same pull zone security key, so `tokenAuthKey` is needed for either. A library configured with `playerTokenAuthEnabled` and no `tokenAuthKey` is rejected rather than silently emitting URLs the player won't accept.

#### Signed URLs and caching

A token carries an expiry, which puts signed URLs at odds with cached HTML: a token written into a `{% cache %}` block outlives itself, and the page then serves 403s for the rest of that cache's life.

Set `deferSignedUrls` to `true` and BunnyMate stops signing at render time. Site requests render a placeholder instead, and the real token is minted as the response is prepared — after any template cache has been read from or written to. Caches therefore store the placeholder, and each visitor gets a token minted for them. This holds through `json_encode` and HTML escaping, so URLs carried inside a `data-sources` attribute are substituted like any other, and it covers JSON responses as well as HTML.

**It's off by default, because it only rewrites the response body.** A URL that is rendered but doesn't travel in the response keeps its placeholder and reaches its reader unusable — an email built from a template during a site request is the obvious case. Before turning it on, check where else signed URLs are rendered.

Two things are unaffected either way. Anything BunnyMate fetches itself — the MP4 rendition probe, the original-size check, the download controller — signs immediately, as do control panel requests, which are never template-cached. `thumbnailUrl` also always signs immediately, because it's routinely handed to a server-side transformer like Imager, which would otherwise fetch an unsigned placeholder.

And neither setting can help a page served without booting Craft at all: full static caching at the web server or a CDN. There's no response to rewrite, so such a cache has to expire well inside `signedUrlDuration`. Where a plugin caches the response itself, which of it and BunnyMate runs first decides whether the placeholder or the token gets stored — worth checking before relying on it.

### Placeholder files

Videos uploaded straight to Bunny have no bytes in the volume, which Craft's asset indexer treats as a missing file. That matters more than it sounds: the indexer's review dialog pre-selects every missing asset and its primary button deletes them, which would take the Bunny videos with them.

So an empty file is written at the asset's path when the upload starts, purely to give the indexer something to match. It has to be the asset's own filename, since a placeholder under any other name would itself be indexed as a new file.

The cost is that anything reading the asset's own file gets an empty one. Playback, thumbnails and metadata all come from Bunny, so they're unaffected. Set `writePlaceholderFiles` to `false` to turn this off.

Libraries with `tusUploadsEnabled` off never need one, since their videos are stored in the volume.

### Known limitations

Deleting an asset outright removes its Bunny video. Moving one to the trash deliberately doesn't, since a trashed asset can be restored and a deleted video couldn't be: the video stays until the asset is either restored or purged for good.

When Craft's garbage collection eventually purges it, the video is deleted too. That needs a little care, because GC deletes elements with raw SQL and fires no element events, and `Gc::run()` hard-deletes before it fires `EVENT_RUN`. So the videos table's foreign key is `ON DELETE SET NULL` rather than a cascade: purging an asset leaves the row behind with its video ID intact and a null `assetId`, which is an unambiguous marker that the video outlived its asset. BunnyMate clears those on `Gc::EVENT_RUN`, which runs immediately afterwards.

That distinction matters. A null row is definitely a purged asset's video, never a video someone uploaded through Bunny's dashboard, so nothing has to guess and nothing needs scheduling. A video that fails to delete keeps its row and is retried on the next run.

If the uploader can't confirm in time that a folder is set up for Bunny Stream, it lets Craft handle the upload normally. The video still reaches Bunny via the fetch fallback, just without bypassing PHP's upload limits.

## Price, license and support

The plugin is released under the Craft license and could be subject to license fees.
It's made for Værsågod and friends, and no support is given. Submitted issues are
resolved if it scratches an itch.

## Changelog

See [CHANGELOG.md](https://github.com/vaersaagod/bunnymate/blob/main/CHANGELOG.md).

# BunnyMate Changelog

## 3.0.0 - Unreleased

### Added
- Added a `Bunny Storage` filesystem type, for storing assets in a Bunny Edge Storage zone. Asset URLs are derived from the configured pull zone, so no separate Base URL is needed.
- Added the `apiKey` setting, for purging the Bunny CDN cache.
- Added the `purgeEnabled` setting.
- Changed files are now purged from the CDN cache when assets on a Bunny Storage filesystem are added, replaced, copied, moved or deleted.

- Added Bunny Stream support: video libraries can be mapped to volumes, and video assets are backed by Bunny Stream videos.
- Added the `bunnymate/webhook` endpoint, which keeps video status in sync with Bunny. Payloads are verified with HMAC-SHA256.
- Added `asset.bunnyVideo`, exposing playback, thumbnail and embed URLs, encoding status and video metadata.
- Added the `videoLibraries`, `volumeVideoLibraries` and `overrideAssetUrls` settings.
- `asset.url` now returns the Bunny playback URL for video assets backed by Bunny Stream: an MP4 rendition, so it plays in a plain `<video>` element. Added the `videoUrlRendition` setting to choose which.
- `mp4Url()` now falls back to the closest available rendition, rather than returning null, when the one asked for wasn't encoded.
- MP4 URLs are now resolved against the renditions an MP4 actually exists for, measured once a video finishes encoding. Bunny's reported resolutions describe its HLS renditions, so trusting them for MP4 URLs produced 404s.
- Videos uploaded to a mapped volume through the regular Assets screen now go straight from the browser to Bunny Stream over TUS, bypassing PHP's upload limits entirely. They're named `.mp4` whatever the source was, since that's what Bunny serves.
- Videos that reach a mapped volume any other way (a normal upload, an import, a programmatic save) are now sent to Bunny Stream too, via Bunny's URL fetch. Added the `autoUploadVideos` setting to control this.
- Replacing a video asset's file now replaces its Bunny Stream video.
- Video assets now use Bunny's poster frame as their control panel thumbnail, rather than a generic file-type icon.
- Video thumbnails are now marked with a play icon, so they're distinguishable from images at a glance.
- Videos that are still encoding now show a placeholder thumbnail with a spinner, instead of a generic file-type icon.
- Control panel thumbnails are now resized with Imager X where it's installed, so a 4K poster frame becomes a ~3KB thumbnail instead of a 150KB one. Added the `transformThumbnails` setting.
- Added the per-library `optimizerEnabled` setting, which lets control panel thumbnails be requested at the size Craft asked for when Bunny Optimizer is enabled on the pull zone.
- An empty placeholder file is now written for videos uploaded straight to Bunny, so Craft's asset indexer doesn't list them as missing and offer to delete them. Added the `writePlaceholderFiles` setting.
- Previewing a Bunny Stream video in the control panel now opens Bunny's player.
- Video assets now get their dimensions, file size and modified date from Bunny, rather than being left empty because the asset holds no file. The size reported is the uploaded file's, not Bunny's total storage for the video.
- Added a Bunny Stream panel to asset edit screens, showing encoding status and metadata, with a button to refresh it from Bunny when the webhook hasn't landed.
- Added `asset.bunnyVideo.originalUrl` and `hasOriginal`, for the uploaded file itself where the library keeps originals.
- Added `asset.bunnyVideo.downloadUrl`, which serves the original as a download under its original filename, and the `allowOriginalDownloads` setting governing it on the front end. The asset edit screen uses it.
- Added `asset.bunnyVideo.statusLabel`, which reports a video's state from its encoding progress rather than Bunny's status code.
- Added `asset.getBunnyVideoTag()`, which renders a `<video>` element with an HLS source, an MP4 fallback and an aspect ratio. A poster frame is opt-in with `poster: true`. Lazyloading and hls.js are opt-out, and neither loads any JavaScript unless the tag needs it.
- Added the `hlsJsUrl`, `lazyloadBunnyVideo`, `defaultMinResolution` and `defaultMaxResolution` settings, governing the rendered player.
- `lazyload` now governs only whether the sources are held in `data-src`. hls.js is attached as the video approaches the viewport regardless, rather than on page load when the tag isn't lazyloaded.
- hls.js is now used wherever it can run, rather than only where the browser reports no native HLS support. Chrome and Edge report `"maybe"` for HLS but can't decode it, so they previously got neither native playback nor hls.js, and fell back to the capped MP4 rendition.
- Fixed signed playback URLs being rejected by Bunny. Tokens were built as `SHA256_HEX(key + videoGuid + expires)`; Bunny's CDN token authentication wants the URL-safe base64 of `SHA256_RAW(key + path + expires)`. Verified against a token-authenticated pull zone.
- HLS playback URLs are now signed over the video's directory rather than the playlist alone, so the sub-playlists and segments the playlist names are covered by the same token. Note that such a token opens every file for that video, including the original.
- The player now carries the token onto every request hls.js makes. A query string isn't inherited when hls.js resolves a segment against the manifest URL, so segments were arriving unsigned.
- Added the `deferSignedUrls` setting. With it on, signed playback URLs are minted as the response is prepared rather than when the template renders, so a token can't be baked into a `{% cache %}` block and outlive itself. Substitution survives `json_encode` and HTML escaping, so URLs inside a `data-sources` attribute are covered too, as are JSON responses. Off by default: it only rewrites the response body, so a URL rendered into something else — an email, say — would keep its placeholder.
- Fixed iframe embed URLs being signed as though they were playback URLs. Bunny guards the player with a separate token, exposed as its own switch and signed as a hex digest over the video GUID rather than base64 over a path. Added the per-library `playerTokenAuthEnabled` setting, which shares `tokenAuthKey` and is now validated against it.
- Added `asset.bunnyVideo.mp4Sources()`, which returns MP4 URLs for a player that manages the `src` itself — a video-loop component that plays on intersection and swaps rendition on a media query. Takes a map of media query to resolution.
- `signedUrlDuration` now accepts a date interval string such as `'PT1H'` as well as a number of seconds, matching how Craft's own duration settings are written. An unparseable value now says so, where it used to coerce to zero and fail the minimum-length check instead.
- Added the `transformDefaults` and `transformConfigOverrides` settings, passed to Imager X's `transformImage()` when it transforms a control panel thumbnail. Defaults sit under the transform BunnyMate builds, so the requested size still wins while `mode` and the rest are configurable; overrides take precedence, except that `curlOptions` merges key by key so the referrer Bunny requires isn't lost.
- Added the `bunnymate/videos/create-missing` console command, which creates Bunny Stream videos for video assets in mapped volumes that don't have one yet. Auto-upload only catches videos as they arrive and deliberately ignores resaves, so this is what covers a volume that already held videos when it was mapped. Takes `--volume`, `--limit` and `--dry-run`.
- Added a `bunnyVideo()` parameter to asset queries, so `craft.assets.bunnyVideo('ready').all()` returns Bunny Stream videos without fetching every asset and filtering in Twig. Takes `true`, `false`, `'ready'`, `'encoding'`, `'failed'`, `'missing'`, or specific statuses.

- Bunny videos are now deleted when Craft's garbage collection purges their trashed asset.
- A video deleted on Bunny is now recognised when an asset is refreshed, and reported as missing rather than left looking playable.

### Changed
- BunnyMate is no longer a private plugin. Its handle is now `bunnymate` rather than `_bunnymate`, so the config file moves from `config/_bunnymate.php` to `config/bunnymate.php`, and the webhook endpoint stays at `bunnymate/webhook`.
- BunnyMate now requires PHP 8.3 or later.

## 2.0.0 - 2024-05-27

### Added
- Craft 5 compatibility release

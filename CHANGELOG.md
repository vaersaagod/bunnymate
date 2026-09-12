# BunnyMate Changelog

## Unreleased

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
- Added a Bunny Stream panel to asset edit screens, showing encoding status and metadata, with a button to refresh it from Bunny when the webhook hasn't landed.
- Added `asset.bunnyVideo.statusLabel`, which reports a video's state from its encoding progress rather than Bunny's status code.

- Bunny videos are now deleted when Craft's garbage collection purges their trashed asset.
- A video deleted on Bunny is now recognised when an asset is refreshed, and reported as missing rather than left looking playable.

### Changed
- BunnyMate now requires PHP 8.3 or later.

## 2.0.0 - 2024-05-27

### Added
- Craft 5 compatibility release

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
- `asset.url` now returns the Bunny playback URL for video assets backed by Bunny Stream.
- Videos uploaded to a mapped volume through the regular Assets screen now go straight from the browser to Bunny Stream over TUS, bypassing PHP's upload limits entirely.
- Added the Bunny Video Upload utility, which does the same thing as a standalone screen.
- Videos that reach a mapped volume any other way (a normal upload, an import, a programmatic save) are now sent to Bunny Stream too, via Bunny's URL fetch. Added the `autoUploadVideos` setting to control this.
- Replacing a video asset's file now replaces its Bunny Stream video.
- Added a Bunny Stream panel to asset edit screens, showing encoding status and metadata, with a button to refresh it from Bunny when the webhook hasn't landed.

### Fixed
- Fixed an error in the Bunny Video Upload utility, caused by calling `getFileKinds()` on the assets service rather than the assets helper.

### Changed
- BunnyMate now requires PHP 8.3 or later.

## 2.0.0 - 2024-05-27

### Added
- Craft 5 compatibility release

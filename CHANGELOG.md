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
- Added the `videoLibraries`, `defaultVideoLibrary`, `volumeVideoLibraries` and `overrideAssetUrls` settings.
- `asset.url` now returns the Bunny playback URL for video assets backed by Bunny Stream.
- Added the Bunny Video Upload utility, which uploads video files from the browser straight to Bunny Stream over TUS, bypassing PHP's upload limits.

### Changed
- BunnyMate now requires PHP 8.3 or later.

## 2.0.0 - 2024-05-27

### Added
- Craft 5 compatibility release

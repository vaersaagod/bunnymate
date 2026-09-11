# BunnyMate Changelog

## Unreleased

### Added
- Added a `Bunny Storage` filesystem type, for storing assets in a Bunny Edge Storage zone. Asset URLs are derived from the configured pull zone, so no separate Base URL is needed.
- Added the `apiKey` setting, for purging the Bunny CDN cache.
- Added the `purgeEnabled` setting.
- Changed files are now purged from the CDN cache when assets on a Bunny Storage filesystem are added, replaced, copied, moved or deleted.

### Changed
- BunnyMate now requires PHP 8.3 or later.

## 2.0.0 - 2024-05-27

### Added
- Craft 5 compatibility release

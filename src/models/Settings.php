<?php

namespace vaersaagod\bunnymate\models;

use craft\base\Model;

/**
 * BunnyMate settings model
 *
 * @author Værsågod
 * @since 2.0.0
 */
class Settings extends Model
{

    // Public Properties
    // =========================================================================

    /** @var bool Whether pull zone URLs should be used at all */
    public bool $pullingEnabled = true;

    /** @var array[] Pull zone configs, indexed by handle */
    public array $pullZones = [];

    /** @var string|null The handle of the pull zone to use by default */
    public ?string $defaultPullZone = null;

    /**
     * @var string|null Bunny account API key, used to purge the CDN cache.
     *
     * This is the account-wide API key from the Bunny dashboard, *not* a storage zone password.
     * Can be set to an environment variable, e.g. `$BUNNY_API_KEY`.
     *
     * @since 2.1.0
     */
    public ?string $apiKey = null;

    /**
     * @var array[] Bunny Stream video library configs, indexed by handle.
     *
     * Each config takes `id`, `apiKey`, `readOnlyApiKey` and `hostname`, plus an optional
     * `tokenAuthKey` when the library's pull zone has token authentication enabled. Every
     * value can be set to an environment variable.
     *
     * @since 2.1.0
     */
    public array $videoLibraries = [];

    /**
     * @var array<string, string> Maps volume handles to video library handles.
     *
     * Videos uploaded to a volume listed here are sent to the mapped library. Volumes that
     * aren't listed are left alone.
     *
     * @since 2.1.0
     */
    public array $volumeVideoLibraries = [];

    /**
     * @var bool Whether video assets in mapped volumes should be sent to Bunny Stream
     * automatically when they're created.
     *
     * This covers videos that arrive any way other than the TUS uploader: a normal CP upload,
     * a feed import, or a programmatic save. Bunny pulls the file from the asset's URL, so the
     * volume has to be reachable from the public internet.
     *
     * @since 2.1.0
     */
    public bool $autoUploadVideos = true;

    /**
     * @var bool Whether an empty placeholder file should be written for videos uploaded
     * straight to Bunny.
     *
     * Those assets have no bytes in the volume, and Craft's asset indexer lists assets whose
     * files it can't find as missing, offering to delete them with every one pre-selected.
     * The placeholder gives the indexer something to match.
     *
     * The cost is that the asset's reported size is 0, and anything reading the asset's own
     * file gets an empty one. Playback is unaffected, since that comes from Bunny.
     *
     * @since 2.1.0
     */
    public bool $writePlaceholderFiles = true;

    /**
     * @var bool Whether control panel thumbnails should be resized with Imager X, when it's
     * installed.
     *
     * Bunny only resizes poster frames when Optimizer is enabled on the pull zone, so without
     * this a 4K video yields a 4K JPEG for every thumbnail. Imager resizes once and caches the
     * result locally.
     *
     * @since 2.1.0
     */
    public bool $transformThumbnails = true;

    /**
     * @var bool Whether `asset.url` should return the Bunny playback URL for videos.
     *
     * Assets backed by Bunny Stream have no file of their own, so without this their URL
     * resolves to a path that 404s.
     *
     * @since 2.1.0
     */
    public bool $overrideAssetUrls = true;

    /**
     * @var bool Whether changed files should be purged from the CDN cache when assets are
     * added, replaced, moved or deleted via a Bunny Storage filesystem.
     *
     * Individual URLs are purged. The pull zone is never purged as a whole.
     * @since 2.1.0
     */
    public bool $purgeEnabled = true;

}

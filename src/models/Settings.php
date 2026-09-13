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
     * @var string|null Where to load hls.js from, for adaptive playback.
     *
     * Only Safari plays HLS natively, so without this the player falls back to an MP4
     * rendition elsewhere, which works everywhere but tops out at whatever Bunny's MP4
     * fallback produced. Set to null to never load it.
     *
     * @since 2.1.0
     */
    public ?string $hlsJsUrl = 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js';

    /**
     * @var string|null The lowest rendition adaptive playback should settle on, e.g. `'720p'`.
     *
     * Only applies when a tag uses HLS. Adaptive playback starts low and works upwards, so a
     * floor stops a slow first measurement leaving a prominent video soft for several seconds.
     * Nothing to do with MP4 playback, where the rendition is chosen outright.
     *
     * @since 2.1.0
     */
    public ?string $defaultMinResolution = null;

    /**
     * @var string|null The highest rendition adaptive playback should use, e.g. `'1080p'`.
     *
     * Only applies when a tag uses HLS. hls.js already caps to the size the video is displayed
     * at, so this is for holding it lower still.
     *
     * @since 2.1.0
     */
    public ?string $defaultMaxResolution = null;

    /**
     * @var bool Whether rendered video tags should hold their sources in `data-src` until
     * scrolled into view.
     *
     * On by default. [[\vaersaagod\bunnymate\behaviors\VideoAssetBehavior::getBunnyVideoTag()]]
     * renders a self-contained player, so nothing outside it is waiting to call `play()` and
     * deferring the media is a straight win.
     *
     * Turn it off where something else drives playback: a lazyloaded tag has no `src` until
     * the player script has run, so a component that plays the video itself will call `play()`
     * on an empty element. That case is better served by building the element from
     * [[\vaersaagod\bunnymate\models\BunnyVideo::getMp4Sources()]] than by this tag.
     *
     * This has nothing to do with hls.js, which is always attached on approach regardless.
     *
     * @since 2.1.0
     */
    public bool $lazyloadBunnyVideo = true;

    /**
     * @var bool Whether to defer signing playback URLs until the response is prepared.
     *
     * Only relevant to libraries with token authentication enabled. A token carries an expiry,
     * so one rendered into a `{% cache %}` block outlives itself and the page serves 403s for
     * the rest of that cache's life. With this on, site requests render a placeholder and the
     * real token is minted as the response goes out, so caches store the placeholder and each
     * visitor gets a token of their own.
     *
     * Off by default, because it only substitutes the response body. A URL that is rendered
     * but doesn't travel in the response -- an email body, say -- keeps its placeholder and
     * reaches its reader unusable. Turn it on when pages carrying signed URLs are cached by
     * Craft, and check what else those URLs are rendered into before you do.
     *
     * It can't help a page served without booting Craft at all, since there's no response to
     * rewrite. Static caches holding signed URLs have to expire inside `signedUrlDuration`
     * whatever this is set to.
     *
     * @since 2.1.0
     */
    public bool $deferSignedUrls = false;

    /**
     * @var bool Whether original files can be downloaded from the front end.
     *
     * Originals aren't capped the way the MP4 renditions are, so this serves the
     * full-resolution master to anyone who asks for it. Control panel downloads are gated on
     * the asset's own permissions and aren't affected by this.
     *
     * @since 2.1.0
     */
    public bool $allowOriginalDownloads = true;

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
     * @var array|null Transform defaults passed to Imager X when transforming a thumbnail.
     *
     * Handed to `transformImage()` as its third argument, so these sit *under* the transform
     * BunnyMate builds: the width and height Craft asked for always win, and everything else
     * -- `mode`, `position`, `format`, `quality`, `effects` -- is yours to set. BunnyMate's own
     * only default is `mode: crop`, which this can override.
     *
     * @since 3.0.0
     */
    public ?array $transformDefaults = null;

    /**
     * @var array|null Config overrides passed to Imager X when transforming a thumbnail.
     *
     * Handed to `transformImage()` as its fourth argument, and takes precedence over
     * BunnyMate's own.
     *
     * The one thing BunnyMate sets here is a `curlOptions` referrer, because Bunny libraries
     * reject referrer-less requests by default and Imager fetches over curl, which sends none.
     * `curlOptions` is therefore merged key by key rather than replaced, so setting some other
     * curl option doesn't quietly drop the referrer and leave every thumbnail a 403. Setting
     * `CURLOPT_REFERER` yourself does replace it.
     *
     * @since 3.0.0
     */
    public ?array $transformConfigOverrides = null;

    /**
     * @var string|null The MP4 rendition `asset.url` should point at, e.g. `'1080p'`.
     *
     * Null uses the highest rendition Bunny produced. A rendition that wasn't produced for a
     * given video falls back to the closest one below it, so this never yields a dead URL.
     *
     * @since 2.1.0
     */
    public ?string $videoUrlRendition = null;

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

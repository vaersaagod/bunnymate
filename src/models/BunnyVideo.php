<?php

namespace vaersaagod\bunnymate\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * A Bunny Stream video, as attached to a Craft asset.
 *
 * This is what `asset.bunnyVideo` returns.
 *
 * @property-read VideoLibrary $library
 * @property-read VideoStatus $status
 * @property-read bool $isReady
 * @property-read bool $isFailed
 * @property-read bool $isMissing
 * @property-read string|null $hlsUrl
 * @property-read string|null $thumbnailUrl
 * @property-read string|null $previewUrl
 * @property-read bool $hasOriginal
 * @property-read string|null $originalUrl
 * @property-read string|null $originalFilename
 * @property-read string|null $downloadUrl
 * @property-read int|null $originalSize
 * @property-read float|null $aspectRatio
 * @property-read array $mp4Sources
 *
 * @author Værsågod
 * @since 2.1.0
 */
class BunnyVideo extends Model
{

    // Const Properties
    // =========================================================================

    /** The metadata key the measured MP4 renditions are stored under */
    public const MP4_RESOLUTIONS_KEY = 'bunnymateMp4Resolutions';

    /** The metadata key the uploaded filename is stored under */
    public const ORIGINAL_FILENAME_KEY = 'bunnymateOriginalFilename';

    /** The metadata key the original file's size is stored under */
    public const ORIGINAL_SIZE_KEY = 'bunnymateOriginalSize';

    /**
     * The highest rendition assumed to have an MP4, until one is measured.
     *
     * Bunny's MP4 fallback tops out here today. It's only a starting point: the real set is
     * measured once a video finishes encoding, and that's what's used from then on.
     */
    private const ASSUMED_MP4_MAX = 1080;

    // Public Properties
    // =========================================================================

    /** @var int|null The ID of the Craft asset this video belongs to */
    public ?int $assetId = null;

    /** @var string The handle of the video library this video lives in */
    public string $libraryHandle = '';

    /** @var string The video's GUID in Bunny Stream */
    public string $videoGuid = '';

    /** @var int The raw Bunny status code */
    public int $statusCode = 0;

    /** @var array The video model as last returned by Bunny */
    public array $metadata = [];

    // Private Properties
    // =========================================================================

    /** @var VideoLibrary|null */
    private ?VideoLibrary $_library = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Config normalization
        if (isset($config['metadata']) && is_string($config['metadata'])) {
            $config['metadata'] = Json::decodeIfJson($config['metadata']) ?: [];
        }
        if (!is_array($config['metadata'] ?? [])) {
            $config['metadata'] = [];
        }
        parent::__construct($config);
    }

    /**
     * Returns the library this video lives in.
     *
     * @return VideoLibrary
     * @throws InvalidConfigException if the library isn't configured
     */
    public function getLibrary(): VideoLibrary
    {
        if (!isset($this->_library)) {
            $this->_library = BunnyMate::getInstance()->getStream()->getLibrary($this->libraryHandle);
        }
        return $this->_library;
    }

    /**
     * Returns the video's status.
     *
     * @return VideoStatus
     */
    public function getStatus(): VideoStatus
    {
        return VideoStatus::tryFrom($this->statusCode) ?? VideoStatus::Queued;
    }

    /**
     * Returns whether the video can be played.
     *
     * @return bool
     */
    public function getIsReady(): bool
    {
        return $this->getStatus()->isPlayable();
    }

    /**
     * Returns a human-readable label for the video's state.
     *
     * Bunny's own status settles on [[VideoStatus::ResolutionFinished]] even once a video is
     * fully encoded; [[VideoStatus::Finished]] only ever arrives as a passing webhook. Going by
     * status alone would therefore label two identically finished videos differently, depending
     * on which webhook happened to land last, so encoding progress is what's reported once a
     * video is playable.
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        if (!$this->getIsReady()) {
            return $this->getStatus()->label();
        }
        return $this->getEncodeProgress() >= 100
            ? Craft::t('bunnymate', 'Ready')
            : Craft::t('bunnymate', 'Playable');
    }

    /**
     * Returns whether Bunny no longer has this video.
     *
     * @return bool
     */
    public function getIsMissing(): bool
    {
        return $this->getStatus()->isMissing();
    }

    /**
     * Returns whether the video failed to upload or encode.
     *
     * @return bool
     */
    public function getIsFailed(): bool
    {
        return $this->getStatus()->isFailed();
    }

    /**
     * Returns the HLS playlist URL, or null if the video isn't playable yet.
     *
     * @return string|null
     * @throws InvalidConfigException
     */
    public function getHlsUrl(): ?string
    {
        if (!$this->getIsReady()) {
            return null;
        }
        // Directory-scoped: the playlist only names sub-playlists and segments, each of
        // which is fetched separately and needs to be covered by the same token
        return $this->getLibrary()->getVideoUrl($this->videoGuid, 'playlist.m3u8', defer: true, directory: true);
    }

    /**
     * Returns an MP4 URL for the video.
     *
     * Requires MP4 fallback to be enabled on the library. With no resolution given, the highest
     * available one is used.
     *
     * Only renditions an MP4 exists for are considered, which is not the same as the ones Bunny
     * encoded. See [[getAvailableMp4Resolutions()]]. A requested resolution that isn't among
     * them falls back to the closest one below it, or to the lowest available if the request
     * was below everything on offer.
     *
     * @param string|null $resolution e.g. `720p`
     * @return string|null Null only if the video has no MP4 renditions at all
     * @throws InvalidConfigException
     */
    public function getMp4Url(?string $resolution = null): ?string
    {
        if (!$this->getIsReady()) {
            return null;
        }

        if (empty($this->getAvailableMp4Resolutions())) {
            return null;
        }

        $resolution = $this->resolveRendition($resolution);

        return $this->getLibrary()->getVideoUrl($this->videoGuid, "play_$resolution.mp4", defer: true);
    }

    /**
     * Returns MP4 sources for a player that manages the `src` itself.
     *
     * [[\vaersaagod\bunnymate\behaviors\VideoAssetBehavior::getBunnyVideoTag()]] renders a
     * self-contained player. Where something else drives playback -- a video-loop component
     * that plays on intersection, pauses off-screen and swaps rendition on a media query --
     * that tag is the wrong shape, because it owns the `<source>` elements it would need to
     * hand over. This returns just the URLs, to build the element around.
     *
     * With no arguments, one source at the highest available rendition:
     *
     * ```twig
     * {{ tag('video', { 'data-sources': video.mp4Sources()|json_encode }) }}
     * ```
     *
     * Given a map of media query to resolution, one source per query, in the order given:
     *
     * ```twig
     * {% set sources = video.mp4Sources({
     *     '(max-width: 767px)': '480p',
     *     '(min-width: 768px)': '1080p',
     * }) %}
     * ```
     *
     * A rendition the video doesn't have falls back to the closest below it, exactly as
     * [[getMp4Url()]] does, so the same map can be used across videos encoded differently.
     *
     * @param array<string, string> $map Media query => resolution
     * @return array<int, array{src: string, media?: string}> Empty when there's no playable MP4
     * @throws InvalidArgumentException if given a list rather than a media query map
     * @since 2.1.0
     */
    public function getMp4Sources(array $map = []): array
    {
        if ($map === []) {
            $url = $this->getMp4Url();
            return $url !== null ? [['src' => $url]] : [];
        }

        if (array_is_list($map)) {
            // Left alone, the numeric keys would end up as the media queries, and a player
            // would silently match none of them
            throw new InvalidArgumentException(
                'mp4Sources() expects a map of media query to resolution, e.g. ' .
                "{ '(max-width: 767px)': '480p' }. A list of resolutions has no media queries " .
                'to match on.'
            );
        }

        $sources = [];

        foreach ($map as $media => $resolution) {
            $url = $this->getMp4Url($resolution);
            if ($url === null) {
                continue;
            }
            $sources[] = ['src' => $url, 'media' => (string)$media];
        }

        return $sources;
    }

    /**
     * Resolves a requested rendition against the ones Bunny actually produced.
     *
     * @param string|null $resolution
     * @return string The closest available rendition
     */
    public function resolveRendition(?string $resolution): string
    {
        $available = $this->getAvailableMp4Resolutions();

        // No preference, or one that was actually encoded
        if ($resolution === null) {
            return $available[array_key_last($available)];
        }
        if (in_array($resolution, $available, true)) {
            return $resolution;
        }

        // Otherwise the closest one below it, so nobody is served something larger than asked
        $wanted = (int)$resolution;
        $lower = array_values(array_filter($available, static fn(string $r): bool => (int)$r <= $wanted));
        if (!empty($lower)) {
            return $lower[array_key_last($lower)];
        }

        // Everything on offer is larger than asked for, so take the smallest of them
        return $available[0];
    }

    /**
     * Returns the thumbnail URL.
     *
     * Unlike the playback URLs this is available before encoding finishes, so it can be used
     * as a poster frame while a video is still processing.
     *
     * Passing dimensions only has an effect when Bunny Optimizer is enabled on the library's
     * pull zone. Without it Bunny ignores the parameters and serves the poster frame at its
     * full resolution, so there's no point sending them.
     *
     * @param int|null $width
     * @param int|null $height
     * @return string|null
     * @throws InvalidConfigException
     */
    public function getThumbnailUrl(?int $width = null, ?int $height = null): ?string
    {
        $library = $this->getLibrary();
        $filename = $this->metadata['thumbnailFileName'] ?? 'thumbnail.jpg';

        if ($library->optimizerEnabled && ($width || $height)) {
            $params = array_filter([
                'width' => $width,
                'height' => $height,
            ]);
            $filename .= '?' . http_build_query($params);
        }

        // Not deferred: a thumbnail URL is routinely handed to a server-side transformer --
        // Imager fetches it, transforms it and serves a local copy -- and a placeholder would
        // reach Bunny unsigned
        return $library->getVideoUrl($this->videoGuid, $filename);
    }

    /**
     * Returns whether Bunny still has the file that was uploaded.
     *
     * Only true when the library keeps original files. With that off, Bunny discards the
     * upload once it has encoded it, and there is nothing to recover.
     *
     * @return bool
     */
    public function getHasOriginal(): bool
    {
        return (bool)($this->metadata['hasOriginal'] ?? false);
    }

    /**
     * Returns a URL for the file that was uploaded, untouched.
     *
     * This is the upload itself rather than a rendition, so it isn't capped the way the MP4s
     * are: a 4K upload is served back at 4K, at its original size. Anyone holding the URL can
     * download it, so enable token authentication on the library if that matters.
     *
     * @return string|null Null when the library doesn't keep originals
     * @throws InvalidConfigException
     */
    public function getOriginalUrl(): ?string
    {
        if (!$this->getHasOriginal()) {
            return null;
        }
        // Not deferred: the download controller fetches this itself, server-side
        return $this->getLibrary()->getVideoUrl($this->videoGuid, 'original');
    }

    /**
     * Returns the filename the video was uploaded as.
     *
     * Assets are renamed to .mp4 on upload, since that's what Bunny serves, so this is the
     * only record of what the file was actually called. Null for videos that predate this
     * being stored, or that reached Bunny some other way.
     *
     * @return string|null
     */
    public function getOriginalFilename(): ?string
    {
        $filename = $this->metadata[self::ORIGINAL_FILENAME_KEY] ?? null;
        return is_string($filename) && $filename !== '' ? $filename : null;
    }

    /**
     * Returns the size of the original file in bytes, if it's known.
     *
     * Not the same as Bunny's `storageSize`, which covers every rendition as well.
     *
     * @return int|null
     */
    public function getOriginalSize(): ?int
    {
        $size = $this->metadata[self::ORIGINAL_SIZE_KEY] ?? null;
        return is_numeric($size) && $size > 0 ? (int)$size : null;
    }

    /**
     * Returns a URL that downloads a file, rather than playing it.
     *
     * With no resolution that's the originally uploaded file; with one it's that MP4 rendition.
     *
     * Bunny serves the original as video/mp4 with no Content-Disposition, and offers no way to
     * change that, so a browser plays it instead of saving it. `download` on a link doesn't
     * help either, since it's ignored cross-origin. This URL goes through Craft, which sets the
     * header and the filename and streams the file on from Bunny.
     *
     * @return string|null Null when the library doesn't keep originals
     */
    public function getDownloadUrl(?string $resolution = null): ?string
    {
        if ($this->assetId === null) {
            return null;
        }
        if ($resolution === null && !$this->getHasOriginal()) {
            return null;
        }
        if ($resolution !== null && !in_array($resolution, $this->getAvailableMp4Resolutions(), true)) {
            return null;
        }

        $params = ['assetId' => $this->assetId];
        if ($resolution !== null) {
            $params['resolution'] = $resolution;
        }

        return UrlHelper::actionUrl('bunnymate/download/video', $params);
    }

    /**
     * Returns the animated preview URL.
     *
     * @return string|null
     * @throws InvalidConfigException
     */
    public function getPreviewUrl(): ?string
    {
        if (!$this->getIsReady()) {
            return null;
        }
        return $this->getLibrary()->getVideoUrl($this->videoGuid, 'preview.webp', defer: true);
    }

    /**
     * Returns the iframe embed URL.
     *
     * @param array $params Player params, e.g. `{ autoplay: 'true', loop: 'true' }`
     * @return string
     * @throws InvalidConfigException
     */
    public function getEmbedUrl(array $params = []): string
    {
        return $this->getLibrary()->getEmbedUrl($this->videoGuid, $params);
    }

    /**
     * Returns the resolutions an MP4 actually exists for, ascending.
     *
     * Bunny's MP4 fallback doesn't cover everything it encodes: a video with HLS renditions up
     * to 2160p may only have MP4s up to 1080p, and the API reports nothing about where the cut
     * is. So the real set is measured once the video has finished encoding and stored with its
     * metadata; until then a conservative assumption stands in.
     *
     * @return string[]
     * @throws InvalidConfigException
     */
    public function getAvailableMp4Resolutions(): array
    {
        // Measured when the video finished encoding, so this is fact rather than inference
        $measured = $this->metadata[self::MP4_RESOLUTIONS_KEY] ?? null;
        if (is_array($measured)) {
            return $measured;
        }

        return array_values(array_filter(
            $this->getAvailableResolutions(),
            static fn(string $r): bool => (int)$r <= self::ASSUMED_MP4_MAX,
        ));
    }

    /**
     * Returns the resolutions Bunny has encoded, ascending.
     *
     * @return string[]
     */
    public function getAvailableResolutions(): array
    {
        $value = $this->metadata['availableResolutions'] ?? null;
        if (empty($value)) {
            return [];
        }
        $resolutions = is_array($value) ? $value : explode(',', (string)$value);
        $resolutions = array_values(array_filter(array_map('trim', $resolutions)));
        usort($resolutions, static fn(string $a, string $b): int => (int)$a <=> (int)$b);
        return $resolutions;
    }

    /**
     * Returns the video's width in pixels, if known.
     *
     * @return int|null
     */
    public function getWidth(): ?int
    {
        return isset($this->metadata['width']) ? (int)$this->metadata['width'] : null;
    }

    /**
     * Returns the video's height in pixels, if known.
     *
     * @return int|null
     */
    public function getHeight(): ?int
    {
        return isset($this->metadata['height']) ? (int)$this->metadata['height'] : null;
    }

    /**
     * Returns the video's duration in seconds, if known.
     *
     * @return int|null
     */
    public function getLength(): ?int
    {
        return isset($this->metadata['length']) ? (int)$this->metadata['length'] : null;
    }

    /**
     * Returns the video's aspect ratio, if its dimensions are known.
     *
     * @return float|null
     */
    public function getAspectRatio(): ?float
    {
        $width = $this->getWidth();
        $height = $this->getHeight();

        if (!$width || !$height) {
            return null;
        }

        return $width / $height;
    }

    /**
     * Returns how far along encoding is, as a percentage.
     *
     * @return int
     */
    public function getEncodeProgress(): int
    {
        return (int)($this->metadata['encodeProgress'] ?? 0);
    }

}

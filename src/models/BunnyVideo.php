<?php

namespace vaersaagod\bunnymate\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;

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
 *
 * @author Værsågod
 * @since 2.1.0
 */
class BunnyVideo extends Model
{

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
            ? Craft::t('_bunnymate', 'Ready')
            : Craft::t('_bunnymate', 'Playable');
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
        return $this->getLibrary()->getVideoUrl($this->videoGuid, 'playlist.m3u8');
    }

    /**
     * Returns an MP4 URL for the video.
     *
     * Requires MP4 fallback to be enabled on the library. With no resolution given, the highest
     * available one is used.
     *
     * A requested resolution that Bunny didn't produce falls back to the closest one below it,
     * or to the lowest available if the request was below everything on offer. Bunny's list of
     * available resolutions is what's consulted, so this never returns a URL that would 404.
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

        $available = $this->getAvailableResolutions();
        if (empty($available)) {
            return null;
        }

        $resolution = $this->resolveRendition($resolution);

        return $this->getLibrary()->getVideoUrl($this->videoGuid, "play_$resolution.mp4");
    }

    /**
     * Resolves a requested rendition against the ones Bunny actually produced.
     *
     * @param string|null $resolution
     * @return string The closest available rendition
     */
    public function resolveRendition(?string $resolution): string
    {
        $available = $this->getAvailableResolutions();

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

        return $library->getVideoUrl($this->videoGuid, $filename);
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
        return $this->getLibrary()->getVideoUrl($this->videoGuid, 'preview.webp');
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
     * Returns how far along encoding is, as a percentage.
     *
     * @return int
     */
    public function getEncodeProgress(): int
    {
        return (int)($this->metadata['encodeProgress'] ?? 0);
    }

}

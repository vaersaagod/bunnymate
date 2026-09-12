<?php

namespace vaersaagod\bunnymate\behaviors;

use craft\elements\Asset;

use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Markup;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\BunnyVideo;

use yii\base\Behavior;
use yii\base\InvalidConfigException;

/**
 * Attaches Bunny Stream video data to assets.
 *
 * Deliberately a behavior rather than a custom field type: nothing needs to be added to an
 * asset field layout for `asset.bunnyVideo` to work, and no video metadata ends up in the
 * content table.
 *
 * @property-read Asset $owner
 * @property-read BunnyVideo|null $bunnyVideo
 *
 * @author Værsågod
 * @since 2.1.0
 */
class VideoAssetBehavior extends Behavior
{

    // Public Methods
    // =========================================================================

    /**
     * Returns the Bunny Stream video attached to this asset, if there is one.
     *
     * @return BunnyVideo|null
     */
    public function getBunnyVideo(): ?BunnyVideo
    {
        if ($this->owner->kind !== Asset::KIND_VIDEO) {
            return null;
        }
        return BunnyMate::getInstance()->getVideos()->getVideoForAsset($this->owner);
    }

    /**
     * Renders a `<video>` element for this asset's Bunny Stream video.
     *
     * Bunny publishes no player component of its own, so this is a plain video element with
     * two sources: the HLS playlist, which Safari plays natively, and an MP4 rendition, which
     * everything else falls back to without any JavaScript. Where `hlsJsUrl` is set, hls.js is
     * loaded as well, so browsers that can't play HLS natively still get adaptive playback
     * rather than a rendition capped at whatever Bunny's MP4 fallback produced.
     *
     * Options:
     *
     * - `inline`: autoplaying, muted, looping and playing inline, for use as a background
     * - `lazyload`: hold off loading until scrolled into view, defaulting to the setting
     * - `controls`, `muted`, `autoplay`, `loop`, `playsinline`: set individually
     * - `poster`: true for Bunny's poster frame, or a URL to use instead. Off by default.
     * - `hls`: false to drop HLS altogether and play an MP4 rendition instead
     * - `minResolution` / `maxResolution`: bounds for adaptive playback, e.g. `'720p'`, which
     *   only mean anything while `hls` is on
     * - `attributes`: anything else to put on the element
     * - `nonce`: a CSP nonce for the script tag
     *
     * @param array|null $options
     * @return Markup|null Null if the asset has no playable Bunny video
     * @throws InvalidConfigException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function getBunnyVideoTag(?array $options = null): ?Markup
    {
        $video = $this->getBunnyVideo();

        if (!$video || !$video->getIsReady()) {
            return null;
        }

        return BunnyMate::getInstance()->renderVideoTag($this->owner, $video, $options ?? []);
    }

    /**
     * Returns whether this asset has a Bunny Stream video that's ready to play.
     *
     * @return bool
     */
    public function getHasBunnyVideo(): bool
    {
        return $this->getBunnyVideo()?->getIsReady() ?? false;
    }

}

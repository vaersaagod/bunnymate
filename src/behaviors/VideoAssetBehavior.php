<?php

namespace vaersaagod\bunnymate\behaviors;

use craft\elements\Asset;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\BunnyVideo;

use yii\base\Behavior;

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
     * Returns whether this asset has a Bunny Stream video that's ready to play.
     *
     * @return bool
     */
    public function getHasBunnyVideo(): bool
    {
        return $this->getBunnyVideo()?->getIsReady() ?? false;
    }

}

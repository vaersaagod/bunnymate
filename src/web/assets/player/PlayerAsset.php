<?php

namespace vaersaagod\bunnymate\web\assets\player;

use Craft;

use yii\base\InvalidConfigException;

/**
 * Publishes the front-end player script.
 *
 * Not an AssetBundle: this runs on the site, not the control panel, so it's published and
 * registered as a plain script rather than pulling Craft's CP asset stack along with it.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class PlayerAsset
{

    // Static Methods
    // =========================================================================

    /**
     * Returns the published URL of the player script.
     *
     * @return string
     * @throws InvalidConfigException
     */
    public static function scriptUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl(
            __DIR__ . '/dist/bunnymate-player.js',
            true,
        );
    }

}

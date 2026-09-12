<?php

namespace vaersaagod\bunnymate\web\assets\thumb;

use Craft;

use yii\base\InvalidConfigException;

/**
 * Publishes the placeholder shown while a Bunny video is still encoding.
 *
 * Not an AssetBundle: nothing needs to be loaded into a page, only published so it has a URL
 * Craft can put in a thumbnail's srcset. A data URI can't be used there, since srcset treats
 * commas as candidate separators.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class ThumbAsset
{

    // Static Methods
    // =========================================================================

    /**
     * Returns the published URL of the processing placeholder.
     *
     * @return string
     * @throws InvalidConfigException
     */
    public static function processingUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl(
            __DIR__ . '/dist/processing.svg',
            true,
        );
    }

}

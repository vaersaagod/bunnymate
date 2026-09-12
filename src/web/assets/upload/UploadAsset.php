<?php

namespace vaersaagod\bunnymate\web\assets\upload;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

use yii\base\InvalidConfigException;

/**
 * Assets for the Bunny Stream uploader.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class UploadAsset extends AssetBundle
{

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        // tus.min.js is deliberately not listed here. It's 86KB and most CP screens never
        // start an upload, so it's published alongside and pulled in on demand instead.
        $this->js = [
            'bunnymate-stream-uploader.js',
        ];

        $this->css = [
            'bunnymate-upload.css',
        ];

        parent::init();
    }

    /**
     * Returns the published URL of the TUS client, for loading on demand.
     *
     * @return string
     * @throws InvalidConfigException
     * @since 2.1.0
     */
    public static function tusUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl(
            __DIR__ . '/dist/tus.min.js',
            true,
        );
    }

}

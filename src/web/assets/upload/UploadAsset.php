<?php

namespace vaersaagod\bunnymate\web\assets\upload;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

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

        $this->js = [
            'tus.min.js',
            'bunnymate-upload.js',
            'bunnymate-stream-uploader.js',
        ];

        $this->css = [
            'bunnymate-upload.css',
        ];

        parent::init();
    }

}

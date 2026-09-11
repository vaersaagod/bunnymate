<?php

namespace vaersaagod\bunnymate\web\assets\videopanel;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Assets for the Bunny Stream panel on asset edit screens.
 *
 * Deliberately separate from the uploader bundle, so edit screens don't have to load the
 * TUS client they'll never use.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class VideoPanelAsset extends AssetBundle
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
            'bunnymate-video-panel.js',
        ];

        parent::init();
    }

}

<?php

namespace vaersaagod\bunnymate\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\Cp;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\web\assets\upload\UploadAsset;

/**
 * A utility for uploading videos straight to Bunny Stream.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class VideoUpload extends Utility
{

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('_bunnymate', 'Bunny Video Upload');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'bunnymate-video-upload';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'video';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $volumeOptions = [];
        $stream = BunnyMate::getInstance()->getStream();

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            try {
                if (!$stream->getLibraryForVolume($volume->handle)) {
                    continue;
                }
            } catch (\Throwable $e) {
                Craft::error($e->getMessage(), __METHOD__);
                continue;
            }
            if (!Craft::$app->getUser()->checkPermission("saveAssets:$volume->uid")) {
                continue;
            }
            $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
            if (!$folder) {
                continue;
            }
            $volumeOptions[] = [
                'label' => Craft::t('site', $volume->name),
                'value' => $folder->id,
            ];
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(UploadAsset::class);

        return $view->renderTemplate('_bunnymate/_utilities/video-upload', [
            'volumeOptions' => $volumeOptions,
        ]);
    }

}

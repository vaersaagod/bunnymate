<?php

namespace vaersaagod\bunnymate\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Manages the Bunny Stream video attached to an asset.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class VideosController extends Controller
{

    // Public Methods
    // =========================================================================

    /**
     * Pulls an asset's video metadata down from Bunny again.
     *
     * The webhook normally keeps this in step, so this is for when it doesn't: a webhook URL
     * that isn't configured, an environment Bunny can't reach, or a delivery that was missed.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionRefresh(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $assetId = (int)Craft::$app->getRequest()->getRequiredBodyParam('assetId');
        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if (!$asset) {
            throw new BadRequestHttpException("Invalid asset ID: $assetId");
        }

        $this->requirePermission('saveAssets:' . $asset->getVolume()->uid);

        $plugin = BunnyMate::getInstance();
        $videos = $plugin->getVideos();
        $video = $videos->getVideoForAsset($asset);

        if (!$video) {
            throw new BadRequestHttpException("Asset $assetId has no Bunny Stream video");
        }

        $stream = $plugin->getStream();
        $library = $stream->getLibrary($video->libraryHandle);
        $metadata = $stream->getVideo($library, $video->videoGuid);

        if ($metadata === null) {
            return $this->asFailure(Craft::t('_bunnymate', 'Bunny doesn’t have a video with this ID any more.'));
        }

        $videos->saveVideo(
            $asset->id,
            $video->libraryHandle,
            $video->videoGuid,
            VideoStatus::tryFrom((int)($metadata['status'] ?? 0)),
            $metadata,
        );

        // Hand back a freshly rendered panel, so the sidebar can swap itself out
        $fresh = Craft::$app->getAssets()->getAssetById($assetId);

        return $this->asSuccess(
            Craft::t('_bunnymate', 'Video refreshed.'),
            [
                'html' => BunnyMate::getInstance()->renderVideoPanel($fresh, false),
            ],
        );
    }

}

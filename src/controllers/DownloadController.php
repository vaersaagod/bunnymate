<?php

namespace vaersaagod\bunnymate\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;

use vaersaagod\bunnymate\BunnyMate;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the originally uploaded file as a download.
 *
 * Bunny serves originals as video/mp4 with no Content-Disposition, and offers no way to change
 * that: no query parameter sets it and the pull zone has no setting for it. A `download`
 * attribute on a link doesn't help either, since browsers ignore it cross-origin. So the file
 * is streamed through Craft, which can set the header and the filename.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class DownloadController extends Controller
{

    // Public Properties
    // =========================================================================

    /** @inheritdoc */
    public array|bool|int $allowAnonymous = true;

    // Public Methods
    // =========================================================================

    /**
     * Streams an asset's original file back as a download.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException if downloads aren't allowed from the front end
     * @throws NotFoundHttpException if the asset has no original to download
     */
    public function actionOriginal(): Response
    {
        $request = Craft::$app->getRequest();
        $assetId = (int)$request->getRequiredParam('assetId');

        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        if (!$asset) {
            throw new NotFoundHttpException("Invalid asset ID: $assetId");
        }

        // Control panel requests are gated on the asset's own permissions. Site requests are
        // gated on a setting, since this serves the full-resolution master to anyone who asks.
        if ($request->getIsCpRequest()) {
            $this->requirePermission('viewAssets:' . $asset->getVolume()->uid);
        } elseif (!BunnyMate::getInstance()->getSettings()->allowOriginalDownloads) {
            throw new ForbiddenHttpException('Original downloads aren’t allowed.');
        }

        $video = BunnyMate::getInstance()->getVideos()->getVideoForAsset($asset);
        if (!$video || !$video->getHasOriginal()) {
            throw new NotFoundHttpException('This asset has no original file.');
        }

        $url = $video->getOriginalUrl();
        if ($url === null) {
            throw new NotFoundHttpException('This asset has no original file.');
        }

        try {
            $response = Craft::createGuzzleClient()->get($url, [
                'stream' => true,
                'timeout' => 0,
                'headers' => [
                    // Libraries block referrer-less requests by default
                    'Referer' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
                ],
            ]);
        } catch (\Throwable $e) {
            Craft::error("Unable to fetch the original for asset $assetId: {$e->getMessage()}", __METHOD__);
            throw new NotFoundHttpException('This asset’s original file couldn’t be fetched.');
        }

        if ($response->getStatusCode() !== 200) {
            Craft::error("Bunny returned HTTP {$response->getStatusCode()} for the original of asset $assetId", __METHOD__);
            throw new NotFoundHttpException('This asset’s original file couldn’t be fetched.');
        }

        $filename = $video->getOriginalFilename() ?? $asset->getFilename();

        // Streamed rather than buffered: originals aren't capped like the renditions are, so
        // this can be a multi-gigabyte file
        return Craft::$app->getResponse()->sendStreamAsFile(
            $response->getBody()->detach(),
            $filename,
            [
                'mimeType' => $response->getHeaderLine('Content-Type') ?: 'application/octet-stream',
                'fileSize' => (int)$response->getHeaderLine('Content-Length') ?: null,
                'inline' => false,
            ],
        );
    }

}

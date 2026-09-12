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
     * Streams one of an asset's files back as a download.
     *
     * With no resolution, that's the originally uploaded file. With one, it's that MP4
     * rendition.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException if downloads aren't allowed from the front end
     * @throws NotFoundHttpException if the file isn't available
     */
    public function actionVideo(): Response
    {
        $request = Craft::$app->getRequest();
        $assetId = (int)$request->getRequiredParam('assetId');
        $resolution = $request->getParam('resolution') ?: null;

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
        if (!$video) {
            throw new NotFoundHttpException('This asset has no Bunny Stream video.');
        }

        if ($resolution !== null) {
            if (!in_array($resolution, $video->getAvailableMp4Resolutions(), true)) {
                throw new NotFoundHttpException("This video has no $resolution rendition.");
            }
            $url = $video->getMp4Url($resolution);
        } else {
            $url = $video->getOriginalUrl();
        }

        if ($url === null) {
            throw new NotFoundHttpException('This file isn’t available.');
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

        $filename = $this->_filename($asset, $video, $resolution);

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

    // Private Methods
    // =========================================================================

    /**
     * Returns the filename a download should be saved as.
     *
     * The original keeps the name it was uploaded under, since the asset itself was renamed to
     * .mp4. Renditions are named after the asset with their resolution appended, so several of
     * them can be downloaded without overwriting each other.
     *
     * @param Asset $asset
     * @param \vaersaagod\bunnymate\models\BunnyVideo $video
     * @param string|null $resolution
     * @return string
     */
    private function _filename(Asset $asset, $video, ?string $resolution): string
    {
        if ($resolution === null) {
            return $video->getOriginalFilename() ?? $asset->getFilename();
        }

        return sprintf('%s-%s.mp4', pathinfo($asset->getFilename(), PATHINFO_FILENAME), $resolution);
    }

}

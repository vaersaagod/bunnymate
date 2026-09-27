<?php

namespace vaersaagod\bunnymate\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\web\Controller;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\BunnyVideo;

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
     * The params arrive as arguments rather than being read off the request: Craft passes a
     * route's params (from the `downloadPath` route) to the action this way, but doesn't add them
     * to the query string. Yii fills the arguments from the query string too, so an action URL
     * works the same.
     *
     * @param string|null $videoGuid Identifies the video on the front end
     * @param string|null $assetId Identifies the asset in the control panel
     * @param string|null $resolution An MP4 rendition, e.g. `720p`, or null for the original
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException if downloads aren't allowed from the front end
     * @throws NotFoundHttpException if the file isn't available
     */
    public function actionVideo(?string $videoGuid = null, ?string $assetId = null, ?string $resolution = null): Response
    {
        $request = Craft::$app->getRequest();
        $resolution = $resolution ?: null;

        [$asset, $video] = $request->getIsCpRequest()
            ? $this->_resolveCpDownload($assetId)
            : $this->_resolveSiteDownload($videoGuid);
        $assetId = $asset->id;

        // Both are signed now rather than deferred, since the file is fetched from here and not by
        // the browser: getMp4Url() defers on site requests, and a placeholder token reaching
        // Bunny from the server gets a 403
        if ($resolution !== null) {
            if (!in_array($resolution, $video->getAvailableMp4Resolutions(), true)) {
                throw new NotFoundHttpException("This video has no $resolution rendition.");
            }
            $url = $video->getLibrary()->getVideoUrl($video->videoGuid, "play_$resolution.mp4");
        } else {
            $url = $video->getOriginalUrl();
        }

        $file = $resolution !== null ? "the $resolution rendition" : 'the original';

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
            Craft::error("Unable to fetch $file for asset $assetId: {$e->getMessage()}", __METHOD__);
            throw new NotFoundHttpException('This file couldn’t be fetched.');
        }

        if ($response->getStatusCode() !== 200) {
            Craft::error("Bunny returned HTTP {$response->getStatusCode()} for $file of asset $assetId", __METHOD__);
            throw new NotFoundHttpException('This file couldn’t be fetched.');
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
     * Finds what a control panel download is for, by asset ID.
     *
     * An ID gives nothing away here: control panel downloads are gated on the asset's own
     * permissions, so only someone who could open the asset anyway gets anything.
     *
     * @param string|null $assetId
     * @return array{0: Asset, 1: BunnyVideo}
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    private function _resolveCpDownload(?string $assetId): array
    {
        if (empty($assetId)) {
            throw new BadRequestHttpException('Request missing required param: assetId');
        }
        $assetId = (int)$assetId;

        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        if (!$asset) {
            throw new NotFoundHttpException("Invalid asset ID: $assetId");
        }

        $this->requirePermission('viewAssets:' . $asset->getVolume()->uid);

        $video = BunnyMate::getInstance()->getVideos()->getVideoForAsset($asset);
        if (!$video) {
            throw new NotFoundHttpException('This asset has no Bunny Stream video.');
        }

        return [$asset, $video];
    }

    /**
     * Finds what a front-end download is for, by video GUID.
     *
     * Only the GUID is accepted here, never an asset ID: front-end downloads are open to anyone
     * while `allowOriginalDownloads` is on, and sequential IDs would let them be counted through
     * to download every video there is. A GUID is random, is already public in every playback
     * URL, and is a single indexed lookup in BunnyMate's own table. The asset's volume also has to
     * have public URLs.
     *
     * @param string|null $videoGuid
     * @return array{0: Asset, 1: BunnyVideo}
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    private function _resolveSiteDownload(?string $videoGuid): array
    {
        // Gated on a setting, since this serves the full-resolution master to anyone who asks
        if (!BunnyMate::getInstance()->getSettings()->allowOriginalDownloads) {
            throw new ForbiddenHttpException('Original downloads aren’t allowed.');
        }

        if (empty($videoGuid)) {
            throw new BadRequestHttpException('Request missing required param: videoGuid');
        }
        if (!StringHelper::isUUID($videoGuid)) {
            throw new NotFoundHttpException('Invalid video.');
        }

        $video = BunnyMate::getInstance()->getVideos()->getVideoByGuid($videoGuid);
        $asset = $video && $video->assetId !== null
            ? Asset::find()->id($video->assetId)->one()
            : null;
        if (!$video || !$asset) {
            throw new NotFoundHttpException('Invalid video.');
        }

        // A volume whose files have no public URLs keeps them off the front end, and its
        // downloads with them. The same check decides whether downloadUrl links here at all.
        // Answered the same as an unknown video, so it gives nothing away.
        if (!$video->getHasPublicUrls()) {
            throw new NotFoundHttpException('Invalid video.');
        }

        return [$asset, $video];
    }

    /**
     * Returns the filename a download should be saved as.
     *
     * The original keeps the name it was uploaded under, since the asset itself was renamed to
     * .mp4. Renditions are named after the asset with their resolution appended, so several of
     * them can be downloaded without overwriting each other.
     *
     * @param Asset $asset
     * @param BunnyVideo $video
     * @param string|null $resolution
     * @return string
     */
    private function _filename(Asset $asset, BunnyVideo $video, ?string $resolution): string
    {
        if ($resolution === null) {
            return $video->getOriginalFilename() ?? $asset->getFilename();
        }

        return sprintf('%s-%s.mp4', pathinfo($asset->getFilename(), PATHINFO_FILENAME), $resolution);
    }

}

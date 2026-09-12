<?php

namespace vaersaagod\bunnymate\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\web\Controller;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;
use vaersaagod\bunnymate\models\BunnyVideo;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Issues TUS upload credentials, so the browser can upload straight to Bunny Stream.
 *
 * The library's API key never leaves the server: only a signature derived from it is handed
 * to the browser, scoped to one video and expiring on its own.
 *
 * @see https://bunny.net/docs/stream/tus-resumable-uploads/
 *
 * @author Værsågod
 * @since 2.1.0
 */
class UploadController extends Controller
{

    // Public Methods
    // =========================================================================

    /**
     * Creates a Bunny video and its Craft asset, and returns the credentials the browser
     * needs to upload the file over TUS.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException if the user can't upload to the target volume
     * @throws \Throwable
     */
    public function actionPrepare(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $folderId = (int)$request->getRequiredBodyParam('folderId');
        $filename = (string)$request->getRequiredBodyParam('filename');

        $folder = Craft::$app->getAssets()->getFolderById($folderId);
        if (!$folder) {
            throw new BadRequestHttpException("Invalid folder ID: $folderId");
        }

        $volume = $folder->getVolume();
        $this->requirePermission("saveAssets:$volume->uid");

        $library = BunnyMate::getInstance()->getStream()->getLibraryForVolume($volume->handle);
        if (!$library) {
            throw new BadRequestHttpException("Volume \"$volume->handle\" is not configured for Bunny Stream");
        }

        $filename = AssetsHelper::prepareAssetName($filename);
        if (!in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $this->_allowedExtensions(), true)) {
            throw new BadRequestHttpException("Files of this type can't be uploaded here");
        }

        // The source file is never stored: Bunny keeps it and serves MP4 and HLS. Craft
        // derives an asset's MIME type from its extension, so leaving a .mov here would have
        // it advertise video/quicktime for an MP4 URL, which browsers refuse to play.
        $originalFilename = $filename;
        $filename = sprintf('%s.mp4', pathinfo($filename, PATHINFO_FILENAME));

        $stream = BunnyMate::getInstance()->getStream();
        $videoGuid = $stream->createVideo($library, pathinfo($filename, PATHINFO_FILENAME));

        $asset = new Asset();
        $asset->volumeId = $volume->id;
        $asset->folderId = $folder->id;
        $asset->folderPath = $folder->path;
        $asset->filename = $filename;
        $asset->kind = Asset::KIND_VIDEO;
        // Craft sets this on its own upload path, and the asset index has a column for it
        $asset->uploaderId = Craft::$app->getUser()->getId();
        // These assets hold no file of their own, so the create scenario (which requires a
        // temp file) doesn't apply
        $asset->setScenario(Asset::SCENARIO_INDEX);

        // The video is attached below, so the auto-upload handler must not fire for this save
        $videos = BunnyMate::getInstance()->getVideos();
        $videos->suspendAutoUpload = true;
        try {
            $saved = Craft::$app->getElements()->saveElement($asset);
        } finally {
            $videos->suspendAutoUpload = false;
        }

        if (!$saved) {
            // Don't leave an orphaned video behind in Bunny
            $stream->deleteVideo($library, $videoGuid);
            return $this->asModelFailure($asset, Craft::t('_bunnymate', 'Couldn’t create the asset.'), 'asset');
        }

        $videos->saveVideo(
            $asset->id,
            $library->handle,
            $videoGuid,
            VideoStatus::Queued,
            // The asset is renamed to .mp4, so this is the only record of what was uploaded,
            // and it's what a download of the original should be called
            [BunnyVideo::ORIGINAL_FILENAME_KEY => $originalFilename],
        );

        $this->_writePlaceholderFile($asset);

        return $this->asJson([
            'success' => true,
            'assetId' => $asset->id,
            'filename' => $asset->filename,
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            ...$library->getUploadSignature($videoGuid),
        ]);
    }

    /**
     * Reports whether a folder's volume is set up for Bunny Stream.
     *
     * The uploader asks this when the selected source changes, so that by the time a file is
     * dropped it already knows whether to intercept it.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionFolderInfo(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $folderId = (int)Craft::$app->getRequest()->getRequiredParam('folderId');
        $folder = Craft::$app->getAssets()->getFolderById($folderId);

        if (!$folder || !$folder->volumeId) {
            return $this->asJson(['stream' => false]);
        }

        $volume = $folder->getVolume();

        try {
            $library = BunnyMate::getInstance()->getStream()->getLibraryForVolume($volume->handle);
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            return $this->asJson(['stream' => false]);
        }

        return $this->asJson([
            'stream' => $library !== null && Craft::$app->getUser()->checkPermission("saveAssets:$volume->uid"),
            'extensions' => $this->_allowedExtensions(),
        ]);
    }

    /**
     * Refreshes an asset's video metadata once the browser reports the upload finished.
     *
     * Bunny's webhook is the source of truth for encoding status; this just pulls the video
     * down early, so the CP has something to show before the first webhook lands.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionComplete(): Response
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

        $videos = BunnyMate::getInstance()->getVideos();
        $video = $videos->getVideoForAsset($asset);
        if (!$video) {
            throw new BadRequestHttpException("Asset $assetId has no Bunny video");
        }

        $stream = BunnyMate::getInstance()->getStream();
        $library = $stream->getLibrary($video->libraryHandle);
        $metadata = $stream->getVideo($library, $video->videoGuid);

        if ($metadata !== null) {
            $videos->saveVideo(
                $asset->id,
                $video->libraryHandle,
                $video->videoGuid,
                VideoStatus::tryFrom((int)($metadata['status'] ?? 0)),
                $metadata,
            );
        }

        return $this->asJson([
            'success' => true,
            'status' => $metadata['status'] ?? null,
        ]);
    }

    /**
     * Deletes the asset and its video, for when an upload is cancelled or fails outright.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws \Throwable
     */
    public function actionAbort(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $assetId = (int)Craft::$app->getRequest()->getRequiredBodyParam('assetId');
        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        if (!$asset) {
            return $this->asJson(['success' => true]);
        }

        $this->requirePermission('deleteAssets:' . $asset->getVolume()->uid);

        // Hard delete, so the before-delete handler tears the Bunny video down with it
        Craft::$app->getElements()->deleteElement($asset, true);

        return $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Writes an empty file at the asset's path, so Craft's asset indexer can find it.
     *
     * The bytes live on Bunny, not in the volume, so without this the indexer lists these
     * assets as missing. That isn't cosmetic: its review modal pre-checks every missing asset
     * and the primary button deletes them, which would take the Bunny videos with them.
     *
     * The file has to sit at the asset's exact path. A placeholder under any other name would
     * itself be indexed as a new, unrecognised file.
     *
     * @param Asset $asset
     * @return void
     */
    private function _writePlaceholderFile(Asset $asset): void
    {
        if (!BunnyMate::getInstance()->getSettings()->writePlaceholderFiles) {
            return;
        }
        try {
            // Written through the volume, not its filesystem: the volume is what prepends its
            // subpath, so going straight to the filesystem drops the file at the root instead
            $asset->getVolume()->write($asset->getPath(), '');
        } catch (\Throwable $e) {
            // Not worth failing the upload over; the video itself is unaffected
            Craft::warning("Unable to write a placeholder for asset $asset->id: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Returns the video extensions Craft allows to be uploaded.
     *
     * @return string[]
     */
    private function _allowedExtensions(): array
    {
        $allowed = array_map('strtolower', Craft::$app->getConfig()->getGeneral()->allowedFileExtensions);
        $videoExtensions = array_map('strtolower', AssetsHelper::getFileKinds()[Asset::KIND_VIDEO]['extensions'] ?? []);
        return array_values(array_intersect($allowed, $videoExtensions));
    }

}

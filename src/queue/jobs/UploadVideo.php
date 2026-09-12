<?php

namespace vaersaagod\bunnymate\queue\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\queue\BaseJob;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;

/**
 * Hands an existing Craft asset to Bunny Stream, which pulls the file from the asset's URL.
 *
 * This is the fallback path for videos that arrive any way other than the TUS uploader: a
 * normal CP upload, a feed import, or a programmatic save. Videos uploaded over TUS already
 * have their bytes on Bunny and never go through here.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class UploadVideo extends BaseJob
{

    // Public Properties
    // =========================================================================

    /** @var int The ID of the asset to upload */
    public int $assetId;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $asset = Craft::$app->getAssets()->getAssetById($this->assetId);
        if (!$asset || $asset->kind !== Asset::KIND_VIDEO) {
            return;
        }

        $plugin = BunnyMate::getInstance();
        $videos = $plugin->getVideos();
        $stream = $plugin->getStream();

        // Something may have attached a video since this job was queued
        if ($videos->getVideoForAsset($asset)) {
            return;
        }

        $volume = $asset->getVolume();
        $library = $stream->getLibraryForVolume($volume->handle);
        if (!$library) {
            return;
        }

        // Read the URL before any video is attached, so the asset URL override doesn't
        // kick in and hand Bunny a playback URL for a video that has no bytes yet
        $url = $asset->getUrl();
        if (empty($url)) {
            Craft::error("Unable to send asset $this->assetId to Bunny Stream: the asset has no URL", __METHOD__);
            return;
        }

        // Volumes often have a relative base URL; Bunny needs somewhere it can actually reach
        if (!UrlHelper::isAbsoluteUrl($url)) {
            $url = UrlHelper::siteUrl($url);
        }

        if (!UrlHelper::isAbsoluteUrl($url)) {
            Craft::error("Unable to send asset $this->assetId to Bunny Stream: \"$url\" isn't an absolute URL. Bunny fetches the file over HTTP, so the volume has to be reachable from the public internet.", __METHOD__);
            return;
        }

        $this->setProgress($queue, 0.2);

        $videoGuid = $stream->createVideo($library, $asset->getFilename(false));
        $videos->saveVideo($this->assetId, $library->handle, $videoGuid, VideoStatus::Queued, []);

        $this->setProgress($queue, 0.6);

        // A rejected fetch surfaces as an exception, not a false return, so both have to be
        // handled or the video is created on Bunny and never cleaned up
        try {
            $fetched = $stream->fetchVideo($library, $videoGuid, $url);
            $error = null;
        } catch (\Throwable $e) {
            $fetched = false;
            $error = $e->getMessage();
        }

        if (!$fetched) {
            // Don't leave a record pointing at a video with no bytes
            Craft::error(
                "Bunny Stream refused to fetch \"$url\" for asset $this->assetId" . ($error ? ": $error" : ''),
                __METHOD__,
            );
            $videos->deleteVideoForAsset($this->assetId);
            return;
        }

        $this->setProgress($queue, 1);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('_bunnymate', 'Sending video to Bunny Stream');
    }

}

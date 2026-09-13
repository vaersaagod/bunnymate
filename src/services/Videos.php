<?php

namespace vaersaagod\bunnymate\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;
use vaersaagod\bunnymate\models\BunnyVideo;
use vaersaagod\bunnymate\records\Video as VideoRecord;

use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Videos service. Owns the mapping between Craft assets and Bunny Stream videos.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class Videos extends Component
{

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the automatic upload of new video assets is currently suspended.
     *
     * The TUS uploader creates its asset *before* attaching the video to it, so without this
     * the auto-upload handler would see a brand new video asset with no video and queue a
     * redundant URL fetch against a file that doesn't exist yet.
     */
    public bool $suspendAutoUpload = false;

    // Private Properties
    // =========================================================================

    /** @var array<int, BunnyVideo|null> Memoized videos, keyed by asset ID */
    private array $_videosByAssetId = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the Bunny video attached to an asset, if there is one.
     *
     * @param Asset|int $asset
     * @return BunnyVideo|null
     */
    public function getVideoForAsset(Asset|int $asset): ?BunnyVideo
    {
        $assetId = $asset instanceof Asset ? $asset->id : $asset;
        if ($assetId === null) {
            return null;
        }
        if (array_key_exists($assetId, $this->_videosByAssetId)) {
            return $this->_videosByAssetId[$assetId];
        }
        $record = VideoRecord::findOne(['assetId' => $assetId]);
        return $this->_videosByAssetId[$assetId] = $record ? $this->_toModel($record) : null;
    }

    /**
     * Returns the Bunny video with the given GUID, if it's attached to an asset.
     *
     * @param string $videoGuid
     * @return BunnyVideo|null
     */
    public function getVideoByGuid(string $videoGuid): ?BunnyVideo
    {
        $record = VideoRecord::findOne(['videoGuid' => $videoGuid]);
        return $record ? $this->_toModel($record) : null;
    }

    /**
     * Attaches a Bunny video to an asset, or updates the existing attachment.
     *
     * @param int $assetId
     * @param string $libraryHandle
     * @param string $videoGuid
     * @param VideoStatus|null $status
     * @param array|null $metadata
     * @return bool
     */
    public function saveVideo(int $assetId, string $libraryHandle, string $videoGuid, ?VideoStatus $status = null, ?array $metadata = null): bool
    {
        $record = VideoRecord::findOne(['assetId' => $assetId]) ?? new VideoRecord([
            'assetId' => $assetId,
        ]);
        // Stored by ID rather than by handle: the handle is a local config key, and renaming
        // it would strand every row -- including orphans, whose Bunny videos could then never
        // be deleted. The ID is what the video actually belongs to.
        $record->library = (string)BunnyMate::getInstance()->getStream()->getLibrary($libraryHandle)->id;
        $record->videoGuid = $videoGuid;
        if ($status !== null) {
            $record->status = $status->value;
        }
        if ($metadata !== null) {
            $metadata = $this->_withMp4Resolutions($metadata, $libraryHandle, $videoGuid);
            // Bunny doesn't know the uploaded filename, so carry ours across refreshes
            $existing = Json::decodeIfJson($record->getOldAttribute('metadata') ?? '') ?: [];
            foreach ([BunnyVideo::ORIGINAL_FILENAME_KEY, BunnyVideo::MP4_RESOLUTIONS_KEY] as $key) {
                if (!isset($metadata[$key]) && isset($existing[$key])) {
                    $metadata[$key] = $existing[$key];
                }
            }
            $record->metadata = Json::encode($metadata);
        }
        if (!$record->save()) {
            Craft::error("Unable to save video for asset $assetId: " . Json::encode($record->getErrors()), __METHOD__);
            return false;
        }

        unset($this->_videosByAssetId[$assetId]);

        if ($metadata !== null) {
            $this->_syncAssetAttributes($assetId, Json::decodeIfJson($record->metadata) ?: []);
        }

        return true;
    }

    /**
     * Applies an incoming webhook status to the matching video.
     *
     * Statuses that aren't part of the encoding lifecycle (captions and metadata generation)
     * arrive *after* a video is ready, so writing them to the status column would move a ready
     * video backwards. Those refresh the metadata instead, leaving the status alone.
     *
     * @param string $videoGuid
     * @param VideoStatus $status
     * @return bool Whether a matching video was found and updated
     */
    public function applyWebhookStatus(string $videoGuid, VideoStatus $status): bool
    {
        $record = VideoRecord::findOne(['videoGuid' => $videoGuid]);
        if (!$record) {
            Craft::info("Ignoring webhook for unknown video \"$videoGuid\"", __METHOD__);
            return false;
        }
        if ($record->assetId === null) {
            // The asset was purged; garbage collection deals with the video
            return false;
        }

        $stream = BunnyMate::getInstance()->getStream();

        try {
            $library = $stream->requireLibraryById($record->library);
        } catch (InvalidConfigException $e) {
            // Nothing can be done with a video whose library is no longer configured, and the
            // save below would fail on the same lookup
            Craft::error("Ignoring webhook for video \"$videoGuid\": {$e->getMessage()}", __METHOD__);
            return false;
        }

        // Pull the full video model down, so metadata stays in step with the status
        $metadata = null;
        try {
            $metadata = $stream->getVideo($library, $videoGuid);
        } catch (\Throwable $e) {
            Craft::error("Unable to refresh metadata for video \"$videoGuid\": {$e->getMessage()}", __METHOD__);
        }

        // Deliberately routed through saveVideo rather than writing the record here: that is
        // what carries our own metadata across a refresh, measures the MP4 renditions, and
        // copies dimensions and size onto the asset
        return $this->saveVideo(
            $record->assetId,
            $library->handle,
            $videoGuid,
            $status->isLifecycle() ? $status : null,
            $metadata,
        );
    }

    /**
     * Deletes an asset's video, both locally and in Bunny.
     *
     * @param Asset|int $asset
     * @param bool $deleteRemote Whether the video should also be deleted from Bunny
     * @return bool
     */
    public function deleteVideoForAsset(Asset|int $asset, bool $deleteRemote = true): bool
    {
        $assetId = $asset instanceof Asset ? $asset->id : $asset;
        $record = VideoRecord::findOne(['assetId' => $assetId]);
        if (!$record) {
            return false;
        }
        if ($deleteRemote) {
            try {
                $stream = BunnyMate::getInstance()->getStream();
                $stream->deleteVideo($stream->requireLibraryById($record->library), $record->videoGuid);
            } catch (InvalidConfigException $e) {
                Craft::error("Unable to delete remote video \"$record->videoGuid\": {$e->getMessage()}", __METHOD__);
            }
        }
        $record->delete();
        unset($this->_videosByAssetId[$assetId]);
        return true;
    }

    /**
     * Records that Bunny no longer has an asset's video.
     *
     * The row is kept rather than deleted, so the control panel can say what happened instead
     * of the asset quietly reverting to looking like an ordinary video with no file.
     *
     * @param int $assetId
     * @return bool
     */
    public function markVideoMissing(int $assetId): bool
    {
        $record = VideoRecord::findOne(['assetId' => $assetId]);
        if (!$record) {
            return false;
        }
        $record->status = VideoStatus::Missing->value;
        if (!$record->save()) {
            return false;
        }
        unset($this->_videosByAssetId[$assetId]);
        return true;
    }

    /**
     * Deletes videos whose Craft asset has been purged.
     *
     * Trashing an asset leaves its video alone, since the asset can be restored. When garbage
     * collection later purges it for good, the foreign key nulls the row's assetId rather than
     * removing it, so the video's ID survives to be cleaned up here.
     *
     * A video that fails to delete keeps its row, so the next run tries again.
     *
     * @return int How many videos were deleted
     */
    public function deleteOrphanedVideos(): int
    {
        $records = VideoRecord::findAll(['assetId' => null]);
        if (empty($records)) {
            return 0;
        }

        $stream = BunnyMate::getInstance()->getStream();
        $deleted = 0;

        foreach ($records as $record) {
            try {
                $library = $stream->requireLibraryById($record->library);
            } catch (InvalidConfigException $e) {
                Craft::error("Unable to delete orphaned video \"$record->videoGuid\": {$e->getMessage()}", __METHOD__);
                continue;
            }
            if (!$stream->deleteVideo($library, $record->videoGuid)) {
                // Leave the row so the next run picks it up again
                continue;
            }
            $record->delete();
            $deleted++;
            Craft::info("Deleted orphaned video \"$record->videoGuid\"", __METHOD__);
        }

        return $deleted;
    }

    // Private Methods
    // =========================================================================

    /**
     * Adds the renditions an MP4 actually exists for to a video's metadata.
     *
     * Bunny doesn't report this, so it's measured once, when a video is finished encoding and
     * hasn't been measured before. Until then the model falls back to a conservative cap.
     *
     * @param array $metadata
     * @param string $libraryHandle
     * @param string $videoGuid
     * @return array
     */
    private function _withMp4Resolutions(array $metadata, string $libraryHandle, string $videoGuid): array
    {
        if (!empty($metadata[BunnyVideo::MP4_RESOLUTIONS_KEY])) {
            return $metadata;
        }
        if ((int)($metadata['encodeProgress'] ?? 0) < 100 || empty($metadata['availableResolutions'])) {
            return $metadata;
        }
        if (!($metadata['hasMP4Fallback'] ?? false)) {
            $metadata[BunnyVideo::MP4_RESOLUTIONS_KEY] = [];
            return $metadata;
        }

        $available = (new BunnyVideo(['metadata' => $metadata]))->getAvailableResolutions();

        try {
            $stream = BunnyMate::getInstance()->getStream();
            $resolutions = $stream->detectMp4Resolutions($stream->getLibrary($libraryHandle), $videoGuid, $available);
        } catch (\Throwable $e) {
            Craft::error("Unable to detect MP4 renditions for \"$videoGuid\": {$e->getMessage()}", __METHOD__);
            return $metadata;
        }

        if (!empty($resolutions)) {
            $metadata[BunnyVideo::MP4_RESOLUTIONS_KEY] = $resolutions;
        }

        if (!isset($metadata[BunnyVideo::ORIGINAL_SIZE_KEY]) && ($metadata['hasOriginal'] ?? false)) {
            try {
                $stream = BunnyMate::getInstance()->getStream();
                $size = $stream->getOriginalSize($stream->getLibrary($libraryHandle), $videoGuid);
            } catch (\Throwable $e) {
                Craft::error("Unable to size the original for \"$videoGuid\": {$e->getMessage()}", __METHOD__);
                $size = null;
            }
            if ($size !== null) {
                $metadata[BunnyVideo::ORIGINAL_SIZE_KEY] = $size;
            }
        }

        return $metadata;
    }

    /**
     * Copies what Bunny knows about a video onto its Craft asset.
     *
     * These assets hold no file of their own, so Craft has nothing to read dimensions or a size
     * from and leaves those columns empty, which makes an asset index look broken. Bunny knows
     * both, so they're written across whenever a video's metadata is refreshed.
     *
     * The size reported is the uploaded file's, not Bunny's `storageSize`, which counts every
     * rendition as well and would be wildly larger than the file anyone uploaded.
     *
     * @param int $assetId
     * @param array $metadata
     * @return void
     */
    private function _syncAssetAttributes(int $assetId, array $metadata): void
    {
        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        if (!$asset) {
            return;
        }

        $video = new BunnyVideo(['metadata' => $metadata]);
        $width = isset($metadata['width']) ? (int)$metadata['width'] : null;
        $height = isset($metadata['height']) ? (int)$metadata['height'] : null;
        $size = $video->getOriginalSize();

        $changed = false;

        foreach (['width' => $width, 'height' => $height, 'size' => $size] as $attribute => $value) {
            if ($value === null || $value <= 0 || (int)$asset->$attribute === $value) {
                continue;
            }
            $asset->$attribute = $value;
            $changed = true;
        }

        // Craft reads this from the file's modified time, which a placeholder doesn't have.
        // When the video was uploaded is the nearest thing that's actually true.
        $uploaded = DateTimeHelper::toDateTime($metadata['dateUploaded'] ?? null) ?: null;
        if ($uploaded && (!$asset->dateModified || $asset->dateModified->getTimestamp() !== $uploaded->getTimestamp())) {
            $asset->dateModified = $uploaded;
            $changed = true;
        }

        // Craft derives the MIME type from the filename otherwise, and these are renamed to
        // .mp4, so it's already right; setting it explicitly keeps it that way if that changes
        if ($asset->mimeType !== 'video/mp4') {
            $asset->mimeType = 'video/mp4';
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        // The video is already attached, so the auto-upload handler would no-op anyway, but
        // there's no reason for this save to reach it at all
        $this->suspendAutoUpload = true;
        try {
            $asset->setScenario(Asset::SCENARIO_INDEX);
            Craft::$app->getElements()->saveElement($asset, false);
        } catch (\Throwable $e) {
            Craft::error("Unable to update asset $assetId from its video: {$e->getMessage()}", __METHOD__);
        } finally {
            $this->suspendAutoUpload = false;
        }
    }

    /**
     * @param VideoRecord $record
     * @return BunnyVideo
     */
    private function _toModel(VideoRecord $record): BunnyVideo
    {
        return new BunnyVideo([
            'assetId' => $record->assetId,
            'libraryId' => $record->library,
            'videoGuid' => $record->videoGuid,
            'statusCode' => $record->status,
            'metadata' => $record->metadata,
        ]);
    }

}

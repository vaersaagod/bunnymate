<?php

namespace vaersaagod\bunnymate\services;

use Craft;
use craft\elements\Asset;
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
        $record->library = $libraryHandle;
        $record->videoGuid = $videoGuid;
        if ($status !== null) {
            $record->status = $status->value;
        }
        if ($metadata !== null) {
            $record->metadata = Json::encode($metadata);
        }
        if (!$record->save()) {
            Craft::error("Unable to save video for asset $assetId: " . Json::encode($record->getErrors()), __METHOD__);
            return false;
        }
        unset($this->_videosByAssetId[$assetId]);
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
        if ($status->isLifecycle()) {
            $record->status = $status->value;
        }
        // Pull the full video model down, so metadata stays in step with the status
        try {
            $library = BunnyMate::getInstance()->getStream()->getLibrary($record->library);
            $metadata = BunnyMate::getInstance()->getStream()->getVideo($library, $videoGuid);
            if ($metadata !== null) {
                $record->metadata = Json::encode($metadata);
            }
        } catch (\Throwable $e) {
            Craft::error("Unable to refresh metadata for video \"$videoGuid\": {$e->getMessage()}", __METHOD__);
        }
        if (!$record->save()) {
            Craft::error("Unable to save video \"$videoGuid\": " . Json::encode($record->getErrors()), __METHOD__);
            return false;
        }
        unset($this->_videosByAssetId[$record->assetId]);
        return true;
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
                $stream->deleteVideo($stream->getLibrary($record->library), $record->videoGuid);
            } catch (InvalidConfigException $e) {
                Craft::error("Unable to delete remote video \"$record->videoGuid\": {$e->getMessage()}", __METHOD__);
            }
        }
        $record->delete();
        unset($this->_videosByAssetId[$assetId]);
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param VideoRecord $record
     * @return BunnyVideo
     */
    private function _toModel(VideoRecord $record): BunnyVideo
    {
        return new BunnyVideo([
            'assetId' => $record->assetId,
            'libraryHandle' => $record->library,
            'videoGuid' => $record->videoGuid,
            'statusCode' => $record->status,
            'metadata' => $record->metadata,
        ]);
    }

}

<?php

namespace vaersaagod\bunnymate\enums;

use Craft;

/**
 * Bunny Stream video statuses.
 *
 * These are the values Bunny sends as `Status` in its webhook payload, and reports
 * as `status` on the video model.
 *
 * @see https://bunny.net/docs/stream/webhooks/
 *
 * @author Værsågod
 * @since 2.1.0
 */
enum VideoStatus: int
{

    case Queued = 0;
    case Processing = 1;
    case Encoding = 2;
    case Finished = 3;
    case ResolutionFinished = 4;
    case Failed = 5;
    case PresignedUploadStarted = 6;
    case PresignedUploadFinished = 7;
    case PresignedUploadFailed = 8;
    case CaptionsGenerated = 9;
    case TitleOrDescriptionGenerated = 10;

    // Public Methods
    // =========================================================================

    /**
     * Returns whether the video can be played.
     *
     * Note that [[ResolutionFinished]] means at least one resolution is available, so the
     * video is playable before encoding has finished entirely.
     *
     * @return bool
     */
    public function isPlayable(): bool
    {
        return match ($this) {
            self::Finished, self::ResolutionFinished, self::CaptionsGenerated, self::TitleOrDescriptionGenerated => true,
            default => false,
        };
    }

    /**
     * Returns whether the video failed to encode or upload.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::Failed, self::PresignedUploadFailed => true,
            default => false,
        };
    }

    /**
     * Returns whether this status represents a point in the encoding lifecycle.
     *
     * [[CaptionsGenerated]] and [[TitleOrDescriptionGenerated]] are notifications about
     * generated metadata, and can arrive *after* a video has finished encoding. Treating
     * them as lifecycle statuses would move a ready video backwards, so they're excluded.
     *
     * @return bool
     */
    public function isLifecycle(): bool
    {
        return match ($this) {
            self::CaptionsGenerated, self::TitleOrDescriptionGenerated => false,
            default => true,
        };
    }

    /**
     * Returns a human-readable label for this status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => Craft::t('_bunnymate', 'Queued'),
            self::Processing => Craft::t('_bunnymate', 'Processing'),
            self::Encoding => Craft::t('_bunnymate', 'Encoding'),
            self::Finished => Craft::t('_bunnymate', 'Finished'),
            self::ResolutionFinished => Craft::t('_bunnymate', 'Playable'),
            self::Failed => Craft::t('_bunnymate', 'Failed'),
            self::PresignedUploadStarted => Craft::t('_bunnymate', 'Upload started'),
            self::PresignedUploadFinished => Craft::t('_bunnymate', 'Upload finished'),
            self::PresignedUploadFailed => Craft::t('_bunnymate', 'Upload failed'),
            self::CaptionsGenerated => Craft::t('_bunnymate', 'Captions generated'),
            self::TitleOrDescriptionGenerated => Craft::t('_bunnymate', 'Metadata generated'),
        };
    }

}

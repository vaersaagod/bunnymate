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

    /**
     * Not one of Bunny's codes. Set when Bunny turns out to no longer have the video, which
     * it never announces: deleting one in the dashboard fires no webhook.
     */
    case Missing = -1;

    case Queued = 0;
    case Processing = 1;
    case Encoding = 2;
    /** Encoding finished; every rendition is available */
    case Finished = 3;
    /** One rendition is available, so the video plays, but encoding is still running */
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
            self::Failed, self::PresignedUploadFailed, self::Missing => true,
            default => false,
        };
    }

    /**
     * Returns whether Bunny no longer has this video.
     *
     * @return bool
     */
    public function isMissing(): bool
    {
        return $this === self::Missing;
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
     * Returns the Craft status indicator class for this status.
     *
     * @return string
     */
    public function indicatorClass(): string
    {
        if ($this->isFailed()) {
            return 'off';
        }
        if ($this->isPlayable()) {
            return 'on';
        }
        return 'gray';
    }

    /**
     * Returns a human-readable label for this status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Missing => Craft::t('bunnymate', 'Missing from Bunny'),
            self::Queued => Craft::t('bunnymate', 'Queued'),
            self::Processing => Craft::t('bunnymate', 'Processing'),
            self::Encoding => Craft::t('bunnymate', 'Encoding'),
            self::Finished => Craft::t('bunnymate', 'Ready'),
            self::ResolutionFinished => Craft::t('bunnymate', 'Playable'),
            self::Failed => Craft::t('bunnymate', 'Failed'),
            self::PresignedUploadStarted => Craft::t('bunnymate', 'Upload started'),
            self::PresignedUploadFinished => Craft::t('bunnymate', 'Upload finished'),
            self::PresignedUploadFailed => Craft::t('bunnymate', 'Upload failed'),
            self::CaptionsGenerated => Craft::t('bunnymate', 'Captions generated'),
            self::TitleOrDescriptionGenerated => Craft::t('bunnymate', 'Metadata generated'),
        };
    }

}

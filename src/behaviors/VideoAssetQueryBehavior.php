<?php

namespace vaersaagod\bunnymate\behaviors;

use craft\db\Query;
use craft\elements\db\AssetQuery;

use vaersaagod\bunnymate\db\Table;
use vaersaagod\bunnymate\enums\VideoStatus;

use yii\base\Behavior;
use yii\base\InvalidArgumentException;

/**
 * Restricts an asset query to assets that have a Bunny Stream video.
 *
 * Attached to every asset query through `craft\db\Query::EVENT_DEFINE_BEHAVIORS`, so
 * `craft.assets.bunnyVideo()` works the same way a custom field's query parameter would,
 * without a field existing.
 *
 * @property-read AssetQuery $owner
 *
 * @author Værsågod
 * @since 2.1.0
 */
class VideoAssetQueryBehavior extends Behavior
{

    // Const Properties
    // =========================================================================

    public const BEHAVIOR_NAME = 'bunnymate:videoQuery';

    // Public Properties
    // =========================================================================

    /** @var mixed What the query should match, or null to leave it alone */
    public mixed $bunnyVideo = null;

    // Public Methods
    // =========================================================================

    /**
     * Restricts the query by whether, and how, assets have a Bunny Stream video.
     *
     * Accepts:
     *
     * - `true` (the default): assets that have one
     * - `false`: assets that don't
     * - `'ready'`: assets whose video can be played
     * - `'encoding'`: assets whose video is still uploading or encoding
     * - `'failed'`: assets whose video failed to encode or upload, or is gone from Bunny
     * - `'missing'`: assets whose video Bunny no longer has
     * - a [[VideoStatus]] case, a raw status code, or an array of any of these
     *
     * @param mixed $value
     * @return AssetQuery
     */
    public function bunnyVideo(mixed $value = true): AssetQuery
    {
        $this->bunnyVideo = $value;

        return $this->owner;
    }

    /**
     * Applies the restriction to a query that's being prepared.
     *
     * @param AssetQuery $query
     * @return void
     */
    public function applyTo(AssetQuery $query): void
    {
        $value = $this->bunnyVideo;

        if ($value === null || $query->subQuery === null) {
            return;
        }

        $matching = (new Query())
            ->select(['assetId'])
            ->from(Table::VIDEOS);

        $statuses = $this->_statusesFor($value);
        if ($statuses !== null) {
            $matching->where(['status' => $statuses]);
        }

        $query->subQuery->andWhere([
            $value === false ? 'not in' : 'in',
            'elements.id',
            $matching,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the status codes a value restricts to, or null for any.
     *
     * @param mixed $value
     * @return int[]|null
     * @throws InvalidArgumentException if $value isn't a status this understands
     */
    private function _statusesFor(mixed $value): ?array
    {
        if ($value === true || $value === false) {
            return null;
        }

        if ($value instanceof VideoStatus) {
            return [$value->value];
        }

        if (is_array($value)) {
            return array_merge(...array_map(fn($item): array => $this->_statusesFor($item) ?? [], $value));
        }

        if (is_int($value)) {
            return [$value];
        }

        return match ((string)$value) {
            'ready' => $this->_codesWhere(static fn(VideoStatus $status): bool => $status->isPlayable()),
            'encoding' => $this->_codesWhere(
                static fn(VideoStatus $status): bool => !$status->isPlayable() && !$status->isFailed()
            ),
            'failed' => $this->_codesWhere(static fn(VideoStatus $status): bool => $status->isFailed()),
            'missing' => [VideoStatus::Missing->value],
            // Silently matching everything would turn a typo into a query that looks like it
            // worked, so say so instead
            default => throw new InvalidArgumentException(
                "Invalid bunnyVideo() value: \"$value\". Expected true, false, \"ready\", \"encoding\", \"failed\", \"missing\", or a status code."
            ),
        };
    }

    /**
     * @param callable $filter
     * @return int[]
     */
    private function _codesWhere(callable $filter): array
    {
        return array_values(array_map(
            static fn(VideoStatus $status): int => $status->value,
            array_filter(VideoStatus::cases(), $filter),
        ));
    }

}

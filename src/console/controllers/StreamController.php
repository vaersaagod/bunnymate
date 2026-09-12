<?php

namespace vaersaagod\bunnymate\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\db\Table;

use yii\console\ExitCode;
use yii\db\Query;

/**
 * Manages Bunny Stream videos.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class StreamController extends Controller
{

    // Public Properties
    // =========================================================================

    /** @var bool Whether the orphaned videos should actually be deleted */
    public bool $delete = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'prune') {
            $options[] = 'delete';
        }
        return $options;
    }

    /**
     * Lists videos in a library that no longer belong to a Craft asset.
     *
     * Videos are orphaned when an asset is trashed and later purged by Craft's garbage
     * collection, which deletes elements with raw SQL and fires no element events, so nothing
     * gets the chance to tell Bunny. They also accumulate from videos uploaded outside Craft.
     *
     * Nothing is deleted without `--delete`.
     *
     * @param string $library The handle of the video library to prune
     * @return int
     */
    public function actionPrune(string $library): int
    {
        $plugin = BunnyMate::getInstance();

        try {
            $lib = $plugin->getStream()->getLibrary($library);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $videos = $plugin->getStream()->getVideos($lib);
        if ($videos === null) {
            $this->stderr("Couldn’t list videos in “$library”." . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $known = (new Query())
            ->select(['videoGuid'])
            ->from(Table::VIDEOS)
            ->where(['library' => $library])
            ->column();

        $orphans = array_values(array_filter(
            $videos,
            static fn(array $video): bool => !in_array($video['guid'] ?? '', $known, true),
        ));

        $this->stdout(sprintf(
            "%d video%s in “%s”, %d of which %s no Craft asset." . PHP_EOL . PHP_EOL,
            count($videos),
            count($videos) === 1 ? '' : 's',
            $library,
            count($orphans),
            count($orphans) === 1 ? 'has' : 'have',
        ));

        if (empty($orphans)) {
            return ExitCode::OK;
        }

        foreach ($orphans as $video) {
            $this->stdout(sprintf(
                '  %s  %s  %s' . PHP_EOL,
                $video['guid'] ?? '?',
                str_pad(Craft::$app->getFormatter()->asShortSize($video['storageSize'] ?? 0), 10),
                $video['title'] ?? '',
            ));
        }

        if (!$this->delete) {
            $this->stdout(PHP_EOL . 'Re-run with --delete to remove them.' . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($this->interactive && !$this->confirm(PHP_EOL . 'Delete these videos from Bunny? This can’t be undone.')) {
            return ExitCode::OK;
        }

        $deleted = 0;
        foreach ($orphans as $video) {
            $guid = $video['guid'] ?? null;
            if ($guid === null) {
                continue;
            }
            if ($plugin->getStream()->deleteVideo($lib, $guid)) {
                $deleted++;
                $this->stdout("  deleted $guid" . PHP_EOL, Console::FG_GREEN);
            } else {
                $this->stderr("  failed to delete $guid" . PHP_EOL, Console::FG_RED);
            }
        }

        $this->stdout(PHP_EOL . "Deleted $deleted of " . count($orphans) . '.' . PHP_EOL);

        return ExitCode::OK;
    }

}

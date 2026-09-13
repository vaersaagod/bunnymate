<?php

namespace vaersaagod\bunnymate\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\queue\jobs\UploadVideo;

use yii\console\ExitCode;

/**
 * Manages the Bunny Stream videos behind Craft's video assets.
 *
 * @author Værsågod
 * @since 3.0.0
 */
class VideosController extends Controller
{

    // Public Properties
    // =========================================================================

    /** @var string|null Only consider assets in this volume */
    public ?string $volume = null;

    /** @var int|null Stop after this many assets */
    public ?int $limit = null;

    /** @var bool Report what would be sent, without sending anything */
    public bool $dryRun = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [...parent::options($actionID), 'volume', 'limit', 'dryRun'];
    }

    /**
     * Creates Bunny Stream videos for video assets that don't have one yet.
     *
     * For a volume that's been mapped to a library after it already held videos -- a site
     * moving to Bunny Stream from somewhere else, most likely -- nothing sends those existing
     * assets anywhere. Uploads only reach Bunny as they happen, and a resave deliberately
     * doesn't trigger one, so this is what covers the back catalogue.
     *
     * Bunny fetches each file over HTTP from the asset's own URL, so this has to run
     * somewhere those URLs are reachable from the public internet. A local environment won't
     * do, however correctly it's configured.
     *
     * Assets that already have a video are skipped, which makes the command safe to re-run
     * and safe to interrupt.
     *
     * @return int
     */
    public function actionCreateMissing(): int
    {
        $stream = BunnyMate::getInstance()->getStream();
        $settings = BunnyMate::getInstance()->getSettings();

        // Only volumes mapped to a library are eligible; the rest are left alone entirely
        $handles = array_keys($settings->volumeVideoLibraries);

        if ($this->volume !== null) {
            if (!in_array($this->volume, $handles, true)) {
                $this->stderr("Volume \"$this->volume\" isn't mapped to a video library in volumeVideoLibraries." . PHP_EOL, Console::FG_RED);
                return ExitCode::CONFIG;
            }
            $handles = [$this->volume];
        }

        if (empty($handles)) {
            $this->stdout('No volumes are mapped to a video library, so there is nothing to send.' . PHP_EOL);
            return ExitCode::OK;
        }

        $total = 0;
        $pending = [];

        foreach ($handles as $handle) {
            try {
                $library = $stream->getLibraryForVolume($handle);
            } catch (\Throwable $e) {
                $this->stderr("  $handle: " . $e->getMessage() . PHP_EOL, Console::FG_RED);
                continue;
            }
            if (!$library) {
                continue;
            }

            $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);
            if (!$volume) {
                $this->stderr("  $handle: no such volume." . PHP_EOL, Console::FG_RED);
                continue;
            }

            $query = Asset::find()
                ->volume($volume)
                ->kind(Asset::KIND_VIDEO)
                ->bunnyVideo(false)
                ->orderBy(['dateCreated' => SORT_ASC]);

            $count = (int)$query->count();
            $this->stdout("  $handle -> $library->handle: ", Console::FG_GREY);
            $this->stdout("$count asset" . ($count === 1 ? '' : 's') . " without a Bunny video" . PHP_EOL);

            if ($count === 0) {
                continue;
            }

            // Bunny pulls the file rather than being handed it, so an unreachable URL is the
            // one failure worth catching before a few hundred jobs are queued behind it
            $sample = (clone $query)->one();
            if ($sample !== null) {
                // Resolved the same way the job resolves it, so what's checked here is what
                // Bunny will actually be given
                $url = (string)$sample->getUrl();
                if ($url !== '' && !UrlHelper::isAbsoluteUrl($url)) {
                    $url = UrlHelper::siteUrl($url);
                }

                if ($url === '' || !UrlHelper::isAbsoluteUrl($url)) {
                    $this->stderr("    No absolute URL for Bunny to fetch from. Skipping $handle." . PHP_EOL, Console::FG_RED);
                    continue;
                }

                if (preg_match('/\.(ddev\.site|test|local|localhost)(:\d+)?\//', $url) || str_contains($url, '://localhost')) {
                    $this->stdout("    Warning: $url" . PHP_EOL, Console::FG_YELLOW);
                    $this->stdout("    That looks like a local URL. Bunny fetches over the public internet and won't reach it." . PHP_EOL, Console::FG_YELLOW);
                }
            }

            $total += $count;
            $pending[$handle] = $query;
        }

        if ($total === 0) {
            $this->stdout('Nothing to do.' . PHP_EOL, Console::FG_GREEN);
            return ExitCode::OK;
        }

        if ($this->limit !== null && $this->limit < $total) {
            $this->stdout("Limiting to $this->limit of $total." . PHP_EOL);
        }

        if ($this->dryRun) {
            $this->stdout("Dry run: nothing queued." . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$this->confirm("Queue " . ($this->limit !== null ? min($this->limit, $total) : $total) . " video(s) for upload to Bunny Stream?", true)) {
            return ExitCode::OK;
        }

        $queued = 0;

        foreach ($pending as $handle => $query) {
            foreach ($query->each() as $asset) {
                if ($this->limit !== null && $queued >= $this->limit) {
                    break 2;
                }
                Queue::push(new UploadVideo(['assetId' => $asset->id]));
                $queued++;
                $this->stdout("  queued #$asset->id $asset->filename" . PHP_EOL, Console::FG_GREY);
            }
        }

        $this->stdout("Queued $queued video(s). Bunny fetches each file itself, so encoding progress arrives on the webhook." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

}

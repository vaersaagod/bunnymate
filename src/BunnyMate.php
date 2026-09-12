<?php

namespace vaersaagod\bunnymate;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineAssetUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\services\Assets;
use craft\services\Fs;
use craft\services\Utilities;
use craft\web\UrlManager;
use craft\web\View;

use vaersaagod\bunnymate\behaviors\VideoAssetBehavior;
use vaersaagod\bunnymate\fs\BunnyStorageFs;
use vaersaagod\bunnymate\models\Settings;
use vaersaagod\bunnymate\queue\jobs\UploadVideo;
use vaersaagod\bunnymate\services\Purge;
use vaersaagod\bunnymate\services\Stream;
use vaersaagod\bunnymate\services\Videos;
use vaersaagod\bunnymate\utilities\VideoUpload;
use vaersaagod\bunnymate\web\assets\upload\UploadAsset;
use vaersaagod\bunnymate\web\assets\videopanel\VideoPanelAsset;
use vaersaagod\bunnymate\web\twig\BunnyMateExtension;

use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\ModelEvent;

/**
 * BunnyMate plugin
 *
 * @property-read Purge $purge
 * @property-read Stream $stream
 * @property-read Videos $videos
 *
 * @author Værsågod
 * @since 1.0.0
 */
class BunnyMate extends Plugin
{

    // Public Properties
    // =========================================================================

    /** @var string */
    public string $schemaVersion = '1.1.0';

    /** @var bool */
    public bool $hasCpSettings = false;

    /** @var bool */
    public bool $hasCpSection = false;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'purge' => ['class' => Purge::class],
                'stream' => ['class' => Stream::class],
                'videos' => ['class' => Videos::class],
            ],
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->_registerFsTypes();
        $this->_registerVideoBehaviors();
        $this->_registerWebhookRoute();
        $this->_registerAssetUrlOverride();
        $this->_registerAssetCleanup();
        $this->_registerUtilities();
        $this->_registerVideoAutoUpload();
        $this->_registerStreamUploader();
        $this->_registerVideoPanel();
        $this->_registerVideoThumbs();

        Craft::$app->onInit(static function () {
            Craft::$app->getView()->registerTwigExtension(new BunnyMateExtension());
        });
    }

    /**
     * @return Settings
     */
    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();
        return $settings;
    }

    /**
     * Returns the Bunny Stream service.
     *
     * @return Stream
     * @throws InvalidConfigException
     * @since 2.1.0
     */
    public function getStream(): Stream
    {
        /** @var Stream $stream */
        $stream = $this->get('stream');
        return $stream;
    }

    /**
     * Returns the videos service.
     *
     * @return Videos
     * @throws InvalidConfigException
     * @since 2.1.0
     */
    public function getVideos(): Videos
    {
        /** @var Videos $videos */
        $videos = $this->get('videos');
        return $videos;
    }

    /**
     * Returns the purge service.
     *
     * @return Purge
     * @throws InvalidConfigException
     * @since 2.1.0
     */
    public function getPurge(): Purge
    {
        /** @var Purge $purge */
        $purge = $this->get('purge');
        return $purge;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers BunnyMate's filesystem types.
     *
     * Registration is deliberately unconditional: filesystem types must resolve in console
     * and queue requests too, or asset operations outside the CP will fail.
     *
     * @return void
     */
    private function _registerFsTypes(): void
    {
        Event::on(
            Fs::class,
            Fs::EVENT_REGISTER_FILESYSTEM_TYPES,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = BunnyStorageFs::class;
            }
        );
    }

    /**
     * Attaches the Bunny video behavior to assets.
     *
     * @return void
     */
    private function _registerVideoBehaviors(): void
    {
        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_BEHAVIORS,
            static function (DefineBehaviorsEvent $event) {
                $event->behaviors['bunnymate:video'] = VideoAssetBehavior::class;
            }
        );
    }

    /**
     * Registers the Bunny Stream webhook route.
     *
     * @return void
     */
    private function _registerWebhookRoute(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event) {
                $event->rules['bunnymate/webhook'] = '_bunnymate/webhook';
            }
        );
    }

    /**
     * Points `asset.url` at the Bunny playback URL for Bunny Stream videos.
     *
     * These assets have no file of their own, so without this their URL resolves to a path
     * that 404s. `beforeDefineUrl` is used rather than `defineUrl` because setting the URL
     * here makes Craft skip its own resolution entirely, which would otherwise go looking on
     * the filesystem for a file that was never written.
     *
     * @return void
     */
    private function _registerAssetUrlOverride(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_DEFINE_URL,
            function (DefineAssetUrlEvent $event) {
                if (!$this->getSettings()->overrideAssetUrls) {
                    return;
                }
                $asset = $event->asset;
                if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }
                $video = $this->getVideos()->getVideoForAsset($asset);
                if (!$video) {
                    return;
                }
                try {
                    // A transform on a video can only sensibly mean the poster frame
                    $url = $event->transform !== null
                        ? $video->getThumbnailUrl()
                        : ($video->getHlsUrl() ?? $video->getThumbnailUrl());
                } catch (\Throwable $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                    return;
                }
                $event->url = $url;
                $event->handled = true;
            }
        );
    }

    /**
     * Deletes the Bunny video when its asset is hard-deleted.
     *
     * This has to run on `beforeDelete`, not `afterDelete`: a hard delete removes the
     * `elements` row, which cascades through `assets` to the videos table, so by the time
     * `afterDelete` fires there is no row left to read the video GUID from.
     *
     * Soft deletes are left alone, since a trashed asset can still be restored. Note that
     * this means an asset which is trashed and later purged by garbage collection leaves its
     * Bunny video behind, as GC deletes elements with raw SQL and fires no element events.
     *
     * @return void
     */
    private function _registerAssetCleanup(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_DELETE,
            function (Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                if ($asset->kind !== Asset::KIND_VIDEO || !$asset->hardDelete) {
                    return;
                }
                $this->getVideos()->deleteVideoForAsset($asset);
            }
        );
    }

    /**
     * Registers BunnyMate's CP utilities.
     *
     * @return void
     */
    private function _registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = VideoUpload::class;
            }
        );
    }

    /**
     * Sends video assets to Bunny Stream when they arrive any way other than the TUS uploader.
     *
     * Bunny pulls the file from the asset's URL, so this only works for volumes that are
     * reachable from the public internet. Videos uploaded over TUS already have their bytes on
     * Bunny, and are skipped via the videos service's suspend flag.
     *
     * @return void
     */
    private function _registerVideoAutoUpload(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_PROPAGATE,
            function (ModelEvent $event) {
                if (!$this->getSettings()->autoUploadVideos) {
                    return;
                }
                /** @var Asset $asset */
                $asset = $event->sender;
                if (
                    $asset->kind !== Asset::KIND_VIDEO
                    || $asset->resaving
                    || $asset->propagating
                    || ElementHelper::isDraftOrRevision($asset)
                ) {
                    return;
                }
                $videos = $this->getVideos();
                if ($videos->suspendAutoUpload || $videos->getVideoForAsset($asset)) {
                    return;
                }
                try {
                    if (!$this->getStream()->getLibraryForVolume($asset->getVolume()->handle)) {
                        return;
                    }
                } catch (\Throwable $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                    return;
                }
                Queue::push(new UploadVideo([
                    'assetId' => $asset->id,
                ]));
            }
        );

        // When a video's file is replaced, drop the old Bunny video so the save that follows
        // sends the new file up in its place
        Event::on(
            Assets::class,
            Assets::EVENT_BEFORE_REPLACE_ASSET,
            function (ReplaceAssetEvent $event) {
                $asset = $event->asset;
                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }
                $this->getVideos()->deleteVideoForAsset($asset);
            }
        );
    }

    /**
     * Swaps Craft's asset uploader for one that sends videos straight to Bunny Stream.
     *
     * Craft dispatches uploaders by filesystem class, so this registers for every filesystem
     * used by a Bunny Stream volume. Volumes sharing that filesystem but not mapped to a
     * library are unaffected: the uploader checks the target folder and falls through to
     * Craft's own behaviour for anything it shouldn't handle.
     *
     * @return void
     */
    private function _registerStreamUploader(): void
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsCpRequest() || $request->getIsConsoleRequest() || $request->getIsAjax()) {
            return;
        }

        Craft::$app->onInit(function () {
            $fsTypes = $this->_getStreamFsTypes();
            if (empty($fsTypes)) {
                return;
            }
            $view = Craft::$app->getView();
            $view->registerAssetBundle(UploadAsset::class);
            $view->registerJs(
                sprintf(
                    'Craft.BunnyMate.tusUrl = %s; Craft.BunnyMate.registerStreamUploader(%s);',
                    Json::encode(UploadAsset::tusUrl()),
                    Json::encode($fsTypes),
                ),
                View::POS_END,
            );
        });
    }

    /**
     * Returns the distinct filesystem classes used by volumes mapped to a video library.
     *
     * @return string[]
     */
    private function _getStreamFsTypes(): array
    {
        $settings = $this->getSettings();
        if (empty($settings->volumeVideoLibraries)) {
            return [];
        }
        $fsTypes = [];
        foreach (array_keys($settings->volumeVideoLibraries) as $volumeHandle) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
            if (!$volume) {
                continue;
            }
            try {
                $fsTypes[] = $volume->getFs()::class;
            } catch (\Throwable $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }
        return array_values(array_unique($fsTypes));
    }

    /**
     * Renders the Bunny Stream panel for an asset.
     *
     * @param Asset $asset
     * @param bool $static Whether the panel should render without its refresh control
     * @return string Empty if the asset has no Bunny Stream video
     * @since 2.1.0
     */
    public function renderVideoPanel(Asset $asset, bool $static = false): string
    {
        $video = $this->getVideos()->getVideoForAsset($asset);
        if (!$video) {
            return '';
        }

        $status = $video->getStatus();
        $statusLabel = $status->label();
        if (!$video->getIsReady() && !$video->getIsFailed() && $video->getEncodeProgress() > 0) {
            $statusLabel .= sprintf(' (%d%%)', $video->getEncodeProgress());
        }

        $resolutions = $video->getAvailableResolutions();
        $length = $video->getLength();

        $rows = [
            Craft::t('_bunnymate', 'Status') => Html::tag('span', '', [
                    'class' => ['status', $status->indicatorClass()],
                    'style' => 'margin-inline-end: 5px;',
                ]) . Html::encode($statusLabel),
            Craft::t('_bunnymate', 'Duration') => $length
                ? sprintf('%d:%02d', intdiv($length, 60), $length % 60)
                : null,
            Craft::t('_bunnymate', 'Dimensions') => $video->getWidth() && $video->getHeight()
                ? sprintf('%d &times; %d', $video->getWidth(), $video->getHeight())
                : null,
            // A single text node on purpose: `.meta > .field > .input` is a flex container,
            // so whitespace between sibling elements is dropped rather than rendered
            Craft::t('_bunnymate', 'Renditions') => !empty($resolutions)
                ? Html::encode(Craft::t('_bunnymate', 'up to {resolution}', [
                    'resolution' => end($resolutions),
                ]))
                : null,
            Craft::t('_bunnymate', 'Video ID') => Html::tag('code', Html::encode($video->videoGuid), [
                'style' => 'font-size: 0.8em; word-break: break-all;',
            ]),
        ];

        $fields = '';
        foreach ($rows as $label => $value) {
            if ($value === null) {
                continue;
            }
            $fields .= $this->_metaFieldHtml($label, $value);
        }

        return Craft::$app->getView()->renderTemplate('_bunnymate/_components/video-panel', [
            'asset' => $asset,
            'video' => $video,
            'fields' => $fields,
            'static' => $static,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Adds a Bunny Stream panel to the sidebar of asset edit screens.
     *
     * The panel shows encoding status and offers a manual refresh, for when the webhook
     * hasn't landed: no webhook URL configured, an environment Bunny can't reach, or a
     * delivery that was missed.
     *
     * @return void
     */
    private function _registerVideoPanel(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function (DefineHtmlEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }
                $panel = $this->renderVideoPanel($asset, $event->static);
                if ($panel === '') {
                    return;
                }
                if (!$event->static) {
                    $view = Craft::$app->getView();
                    $view->registerAssetBundle(VideoPanelAsset::class);
                    $view->registerJs('new Craft.BunnyMate.VideoPanel();', View::POS_READY);
                }
                // The panel brings its own fieldset, legend and .meta read-only wrapper:
                // Craft only wraps an element's own metaFieldsHtml(), not appended markup
                $event->html .= $panel;
            }
        );
    }

    /**
     * Returns one static label/value row, in the shape Craft's sidebar cards use.
     *
     * Cp::metadataHtml() isn't used here because it hardcodes `.meta read-only`, which is
     * styled as a naked, muted readout. These rows go inside a plain `.meta`, which is the
     * filled card treatment, so they have to be `.field` rows like Craft's own.
     *
     * @param string $label
     * @param string $value Already-escaped HTML
     * @return string
     */
    private function _metaFieldHtml(string $label, string $value): string
    {
        return Html::tag(
            'div',
            Html::tag('div', Html::tag('label', Html::encode($label)), ['class' => 'heading']) .
            Html::tag('div', $value, ['class' => ['input', 'ltr']]),
            ['class' => 'field'],
        );
    }

    /**
     * Uses Bunny's poster frame for control panel thumbnails.
     *
     * Without this, video assets fall back to a generic file-type icon: the fileless ones have
     * nothing to generate a thumbnail from, and the rest are videos, which Craft can't
     * transform as images.
     *
     * Note that unless Bunny Optimizer is enabled on the library's pull zone, the poster frame
     * is served at its full resolution and scaled down by the browser. Bunny ignores `?width=`
     * without it, so there's no way to ask for a smaller one.
     *
     * @return void
     */
    private function _registerVideoThumbs(): void
    {
        Event::on(
            Assets::class,
            Assets::EVENT_DEFINE_THUMB_URL,
            function (DefineAssetThumbUrlEvent $event) {
                $asset = $event->asset;
                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }
                $video = $this->getVideos()->getVideoForAsset($asset);
                if (!$video || !$video->getIsReady()) {
                    return;
                }
                try {
                    $url = $video->getThumbnailUrl($event->width, $event->height);
                } catch (\Throwable $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                    return;
                }
                if (empty($url)) {
                    return;
                }
                $event->url = $url;
            }
        );
    }

}

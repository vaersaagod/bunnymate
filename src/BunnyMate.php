<?php

namespace vaersaagod\bunnymate;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\AssetPreviewEvent;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineAssetUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineElementHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\helpers\Queue;
use craft\services\Assets;
use craft\services\Fs;
use craft\services\Gc;
use craft\web\UrlManager;
use craft\web\View;

use vaersaagod\bunnymate\assetpreviews\BunnyVideoPreview;
use vaersaagod\bunnymate\behaviors\VideoAssetBehavior;
use vaersaagod\bunnymate\fs\BunnyStorageFs;
use vaersaagod\bunnymate\models\Settings;
use vaersaagod\bunnymate\queue\jobs\UploadVideo;
use vaersaagod\bunnymate\services\Purge;
use vaersaagod\bunnymate\services\Stream;
use vaersaagod\bunnymate\services\Videos;
use vaersaagod\bunnymate\web\assets\thumb\ThumbAsset;
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
    public string $schemaVersion = '1.2.0';

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
        $this->_registerVideoAutoUpload();
        $this->_registerStreamUploader();
        $this->_registerVideoPanel();
        $this->_registerVideoThumbs();
        $this->_registerVideoThumbBadge();
        $this->_registerVideoPreview();
        $this->_registerGarbageCollection();

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
     * The highest MP4 rendition is preferred over the HLS playlist, since this URL is what
     * ends up in plain <video> elements. Templates wanting adaptive streaming can ask for
     * `asset.bunnyVideo.hlsUrl` directly.
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
                if (!$video || $video->getIsMissing()) {
                    return;
                }
                try {
                    // A transform on a video can only sensibly mean the poster frame
                    $url = $event->transform !== null
                        ? $video->getThumbnailUrl()
                        // An MP4 rendition first: this URL ends up in plain <video> elements,
                        // including Craft's own on the asset edit screen, and only Safari
                        // plays an HLS playlist natively
                        : ($video->getMp4Url($this->getSettings()->videoUrlRendition)
                            ?? $video->getHlsUrl()
                            ?? $video->getThumbnailUrl());
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
        $statusLabel = $video->getStatusLabel();
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
            // What the file was actually called, before the asset was renamed to .mp4
            Craft::t('_bunnymate', 'Original') => $video->getOriginalFilename() !== null
                ? Html::tag('span', Html::encode($video->getOriginalFilename()), [
                    'style' => 'word-break: break-all;',
                ])
                : null,
        ];

        $fields = '';
        foreach ($rows as $label => $value) {
            if ($value === null) {
                continue;
            }
            $fields .= $this->_metaFieldHtml($label, $value);
        }

        // Highest first: the useful ones are at that end of the list
        $renditions = [];
        foreach (array_reverse($video->getAvailableMp4Resolutions()) as $resolution) {
            $renditions[$resolution] = $video->getDownloadUrl($resolution);
        }

        return Craft::$app->getView()->renderTemplate('_bunnymate/_components/video-panel', [
            'asset' => $asset,
            'video' => $video,
            'fields' => $fields,
            'originalUrl' => $video->getDownloadUrl(),
            'originalSize' => $video->getOriginalSize(),
            'renditions' => $renditions,
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
     * Bunny only resizes the poster frame when Optimizer is enabled on the pull zone, so where
     * Imager X is installed it's used to resize instead, which also caches the result locally
     * rather than serving the full-resolution frame on every request.
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
                if (!$video || $video->getIsMissing()) {
                    // Nothing to show a poster or a spinner for; let Craft's icon stand
                    return;
                }
                if (!$video->getIsReady()) {
                    // Bunny has no usable poster frame yet, and the generic file icon gives no
                    // hint that anything is still happening
                    try {
                        $event->url = ThumbAsset::processingUrl();
                    } catch (\Throwable $e) {
                        Craft::error($e->getMessage(), __METHOD__);
                    }
                    return;
                }
                try {
                    // Imager gets the unsized frame, so one cached source serves every size
                    $useImager = $this->getSettings()->transformThumbnails && $this->_getImagerService() !== null;
                    $url = $useImager
                        ? $video->getThumbnailUrl()
                        : $video->getThumbnailUrl($event->width, $event->height);
                } catch (\Throwable $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                    return;
                }
                if (empty($url)) {
                    return;
                }
                $event->url = $this->transformThumbUrl($url, $event->width, $event->height);
            }
        );
    }

    /**
     * Resizes a Bunny poster frame with Imager X, if it's installed.
     *
     * Returns the URL untouched when Imager isn't available or the transform fails, so a
     * thumbnail is always produced, just a larger one.
     *
     * @param string $url
     * @param int $width
     * @param int $height
     * @return string
     * @since 2.1.0
     */
    public function transformThumbUrl(string $url, int $width, int $height): string
    {
        if (!$this->getSettings()->transformThumbnails) {
            return $url;
        }

        $imager = $this->_getImagerService();
        if ($imager === null) {
            return $url;
        }

        try {
            $transformed = $imager->transformImage($url, [
                'width' => $width,
                'height' => $height,
                'mode' => 'crop',
            ], null, [
                // Bunny libraries block requests without a referrer by default, and Imager
                // downloads over curl, which sends none
                'curlOptions' => [
                    CURLOPT_REFERER => UrlHelper::baseSiteUrl(),
                ],
            ]);
        } catch (\Throwable $e) {
            Craft::error("Unable to transform \"$url\": {$e->getMessage()}", __METHOD__);
            return $url;
        }

        return $transformed?->getUrl() ?: $url;
    }

    /**
     * Returns Imager X's transform service, or null if the plugin isn't available.
     *
     * Imager is an optional dependency, so this is deliberately resolved by handle rather than
     * by importing anything from it.
     *
     * @return mixed
     */
    private function _getImagerService(): mixed
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('imager-x');
        if ($plugin === null) {
            return null;
        }
        try {
            return $plugin->get('imager');
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Marks video thumbnails in the control panel with a small "VIDEO" badge.
     *
     * Bunny's poster frame is a still image, so without this a video is indistinguishable from
     * a photo in an asset index. Videos still encoding get a spinner instead of the play icon,
     * over the placeholder their thumbnail falls back to. Videos with no Bunny video already
     * show a file-type icon, which is obvious enough on its own, so they're left alone.
     *
     * @return void
     */
    private function _registerVideoThumbBadge(): void
    {
        foreach ([Cp::EVENT_DEFINE_ELEMENT_CHIP_HTML, Cp::EVENT_DEFINE_ELEMENT_CARD_HTML] as $eventName) {
            Event::on(
                Cp::class,
                $eventName,
                function (DefineElementHtmlEvent $event) {
                    $event->html = $this->_badgeVideoThumb($event->element, $event->html);
                }
            );
        }
    }

    /**
     * Adds the badge class to an element's thumbnail, if it's a playable Bunny video.
     *
     * @param ElementInterface $element
     * @param string $html
     * @return string
     */
    private function _badgeVideoThumb(ElementInterface $element, string $html): string
    {
        if (!$element instanceof Asset || $element->kind !== Asset::KIND_VIDEO) {
            return $html;
        }

        $video = $this->getVideos()->getVideoForAsset($element);
        if (!$video || $video->getIsMissing()) {
            return $html;
        }

        $class = $video->getIsReady()
            ? 'bunnymate-video-thumb'
            : 'bunnymate-video-thumb bunnymate-video-thumb--processing';

        $marked = str_replace(
            '<div class="thumb"',
            sprintf('<div class="thumb %s"', $class),
            $html,
        );

        if ($marked === $html) {
            // Craft's thumbnail markup changed; better no badge than mangled markup
            return $html;
        }

        Craft::$app->getView()->registerCss($this->_videoThumbBadgeCss(), key: 'bunnymate-video-thumb');

        return $marked;
    }

    /**
     * Returns the CSS for the video thumbnail badge.
     *
     * Chips carry their own size class, so one marker class on the thumbnail can be sized for
     * where it ends up: 28px over a 120px large chip or card, 14px over a 30px small chip.
     *
     * @return string
     */
    private function _videoThumbBadgeCss(): string
    {
        $playIcon = $this->_playIconDataUri();

        return <<<CSS
            .thumb.bunnymate-video-thumb::after {
                content: "";
                /* Card thumbnails aren't positioned, unlike chip thumbnails */
                position: absolute;
                inset-block-start: 50%;
                inset-inline-start: 50%;
                transform: translate(-50%, -50%);
                width: 28px;
                height: 28px;
                background-image: url("$playIcon");
                background-repeat: no-repeat;
                background-size: contain;
                pointer-events: none;
            }

            .thumb.bunnymate-video-thumb {
                position: relative;
            }

            /* A 30px chip needs a smaller one */
            .chip.small > .thumb.bunnymate-video-thumb::after {
                width: 14px;
                height: 14px;
            }

            /* Still encoding: a spinner rather than a play control that wouldn't work */
            .thumb.bunnymate-video-thumb--processing::after {
                background-image: none;
                border: 2px solid rgba(255, 255, 255, 0.35);
                border-block-start-color: #fff;
                border-radius: 50%;
                box-sizing: border-box;
                animation: bunnymate-spin 0.8s linear infinite;
            }

            @keyframes bunnymate-spin {
                to { transform: translate(-50%, -50%) rotate(360deg); }
            }

            @media (prefers-reduced-motion: reduce) {
                .thumb.bunnymate-video-thumb--processing::after {
                    animation: none;
                }
            }
            CSS;
    }

    /**
     * Returns a play icon as an SVG data URI.
     *
     * Inlined rather than published as an asset: it's one small shape, and this keeps the
     * badge working without an asset bundle of its own.
     *
     * @return string
     */
    private function _playIconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">'
            . '<circle cx="8" cy="8" r="8" fill="rgba(0,0,0,0.65)"/>'
            . '<path d="M6.2 4.6 11.6 8l-5.4 3.4z" fill="#ffffff"/>'
            . '</svg>';

        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
    }

    /**
     * Previews Bunny Stream videos with Bunny's player.
     *
     * Craft's own video preview points a <video> element at the asset URL, which for these is
     * an HLS playlist most browsers won't play natively.
     *
     * Only videos with a playable Bunny video are claimed, so anything else keeps whatever
     * handler it would otherwise get. Note that Craft takes the *last* handler to claim an
     * asset, so a plugin that claims every video regardless will win over this one.
     *
     * @return void
     */
    private function _registerVideoPreview(): void
    {
        Event::on(
            Assets::class,
            Assets::EVENT_REGISTER_PREVIEW_HANDLER,
            static function (AssetPreviewEvent $event) {
                $asset = $event->asset;
                if ($asset->kind !== Asset::KIND_VIDEO) {
                    return;
                }
                $video = BunnyMate::getInstance()->getVideos()->getVideoForAsset($asset);
                if (!$video || !$video->getIsReady()) {
                    return;
                }
                $event->previewHandler = new BunnyVideoPreview($asset);
            }
        );
    }

    /**
     * Cleans up Bunny videos whose assets have been purged.
     *
     * Craft's garbage collection hard-deletes elements before it fires this event, so by the
     * time it runs the foreign key has already nulled the assetId on any affected row, which
     * is exactly what marks a video as having outlived its asset.
     *
     * @return void
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function () {
                $deleted = $this->getVideos()->deleteOrphanedVideos();
                if ($deleted > 0) {
                    Craft::info("Deleted $deleted orphaned Bunny Stream video(s)", __METHOD__);
                }
            }
        );
    }

}

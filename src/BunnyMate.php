<?php

namespace vaersaagod\bunnymate;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineAssetUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Fs;
use craft\services\Utilities;
use craft\web\UrlManager;

use vaersaagod\bunnymate\behaviors\VideoAssetBehavior;
use vaersaagod\bunnymate\fs\BunnyStorageFs;
use vaersaagod\bunnymate\models\Settings;
use vaersaagod\bunnymate\services\Purge;
use vaersaagod\bunnymate\services\Stream;
use vaersaagod\bunnymate\services\Videos;
use vaersaagod\bunnymate\utilities\VideoUpload;
use vaersaagod\bunnymate\web\twig\BunnyMateExtension;

use yii\base\Event;
use yii\base\InvalidConfigException;

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

}

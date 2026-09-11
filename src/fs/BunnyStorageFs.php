<?php

namespace vaersaagod\bunnymate\fs;

use Craft;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use craft\helpers\StringHelper;

use League\Flysystem\FilesystemAdapter;

use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNAdapter;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNClient;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNRegion;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\helpers\BunnyMateHelper;
use vaersaagod\bunnymate\models\PullZone;

use yii\base\Application;
use yii\base\InvalidConfigException;

/**
 * Bunny Storage filesystem.
 *
 * Files are stored in a Bunny Edge Storage zone, and served over the BunnyMate pull zone
 * that fronts it. The pull zone is configured in `config/_bunnymate.php` rather than on the
 * filesystem itself, so that one hostname is shared with `bunnyPullUrl()`.
 *
 * @property-read string|null $settingsHtml
 *
 * @author Værsågod
 * @since 2.1.0
 */
class BunnyStorageFs extends FlysystemFs
{

    // Static Properties
    // =========================================================================

    /**
     * @var bool The base URL comes from the configured pull zone, so Craft's own
     * "Base URL" field is hidden. See [[getRootUrl()]].
     */
    protected static bool $showUrlSetting = false;

    // Public Properties
    // =========================================================================

    /** @var string The Bunny Edge Storage zone name */
    public string $storageZone = '';

    /** @var string The storage zone password, used as the API access key */
    public string $accessKey = '';

    /** @var string The storage zone's region code */
    public string $region = BunnyCDNRegion::FALKENSTEIN;

    /** @var string Subfolder within the storage zone to use as the filesystem root */
    public string $subfolder = '';

    /** @var string|null The handle of the BunnyMate pull zone that serves this storage zone */
    public ?string $pullZone = null;

    // Protected Properties
    // =========================================================================

    /** @var array<string, bool> Paths queued for CDN invalidation, keyed by path */
    protected array $pathsToInvalidate = [];

    // Private Properties
    // =========================================================================

    /** @var bool Whether the after-request purge handler has been attached */
    private bool $_purgeHandlerAttached = false;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Bunny Storage';
    }

    /**
     * Returns the available Bunny storage regions, as an options array.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function regionOptions(): array
    {
        return [
            ['label' => Craft::t('_bunnymate', 'Falkenstein, DE (default)'), 'value' => BunnyCDNRegion::FALKENSTEIN],
            ['label' => Craft::t('_bunnymate', 'Stockholm, SE'), 'value' => BunnyCDNRegion::STOCKHOLM],
            ['label' => Craft::t('_bunnymate', 'United Kingdom'), 'value' => BunnyCDNRegion::UNITED_KINGDOM],
            ['label' => Craft::t('_bunnymate', 'New York, US'), 'value' => BunnyCDNRegion::NEW_YORK],
            ['label' => Craft::t('_bunnymate', 'Los Angeles, US'), 'value' => BunnyCDNRegion::LOS_ANGELES],
            ['label' => Craft::t('_bunnymate', 'Singapore'), 'value' => BunnyCDNRegion::SINGAPORE],
            ['label' => Craft::t('_bunnymate', 'Sydney, AU'), 'value' => BunnyCDNRegion::SYDNEY],
            ['label' => Craft::t('_bunnymate', 'São Paulo, BR'), 'value' => BunnyCDNRegion::BRAZIL],
            ['label' => Craft::t('_bunnymate', 'Johannesburg, ZA'), 'value' => BunnyCDNRegion::JOHANNESBURG],
        ];
    }

    /**
     * Returns the configured pull zones, as an options array.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function pullZoneOptions(): array
    {
        $pullZones = BunnyMate::getInstance()->getSettings()->pullZones;
        $options = [];
        foreach (array_keys($pullZones) as $handle) {
            $options[] = [
                'label' => $handle,
                'value' => $handle,
            ];
        }
        return $options;
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Config normalization
        if (isset($config['subfolder'])) {
            $config['subfolder'] = trim(str_replace('\\', '/', (string)$config['subfolder']), '/');
        }
        if (empty($config['region'])) {
            $config['region'] = BunnyCDNRegion::FALKENSTEIN;
        }
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'accessKey' => Craft::t('_bunnymate', 'Access Key'),
            'pullZone' => Craft::t('_bunnymate', 'Pull Zone'),
            'region' => Craft::t('_bunnymate', 'Region'),
            'storageZone' => Craft::t('_bunnymate', 'Storage Zone'),
            'subfolder' => Craft::t('_bunnymate', 'Subfolder'),
        ]);
    }

    /**
     * @inheritdoc
     *
     * FlysystemFs doesn't invalidate on write, so overwriting a file in place would otherwise
     * keep serving the old copy from the edge until the pull zone's TTL expired.
     */
    public function write(string $path, string $contents, array $config = []): void
    {
        parent::write($path, $contents, $config);
        $this->invalidateCdnPath($path);
    }

    /**
     * @inheritdoc
     *
     * This is the path Craft's "Replace file" action takes, so it must invalidate.
     * See [[write()]].
     */
    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        parent::writeFileFromStream($path, $stream, $config);
        $this->invalidateCdnPath($path);
    }

    /**
     * @inheritdoc
     *
     * The destination is invalidated, not the source: copying doesn't change the source, but it
     * does replace whatever the destination URL used to serve.
     */
    public function copyFile(string $path, string $newPath, $config = []): void
    {
        parent::copyFile($path, $newPath, $config);
        $this->invalidateCdnPath($newPath);
    }

    /**
     * @inheritdoc
     *
     * FlysystemFs invalidates the old path; the destination needs it too, for the same reason
     * as [[copyFile()]].
     */
    public function renameFile(string $path, string $newPath, $config = []): void
    {
        parent::renameFile($path, $newPath, $config);
        $this->invalidateCdnPath($newPath);
    }

    /**
     * @inheritdoc
     *
     * The directory path is queued with a trailing slash, which makes Bunny treat the purge as a
     * wildcard and clear everything beneath it. Without the slash it would purge the bare
     * directory URL, which isn't a cached object, leaving every file under it stale.
     *
     * @see https://bunny.net/docs/reference/purgepublic_indexpost
     */
    public function deleteDirectory(string $path): void
    {
        parent::deleteDirectory($path);
        $path = rtrim($path, '/');
        unset($this->pathsToInvalidate[$path]);
        $this->invalidateCdnPath("$path/");
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('_bunnymate/fsSettings', [
            'fs' => $this,
            'pullZoneOptions' => static::pullZoneOptions(),
            'regionOptions' => static::regionOptions(),
        ]);
    }

    /**
     * @inheritdoc
     *
     * The root URL is derived from the configured pull zone's hostname, plus the subfolder.
     *
     * Note that this deliberately reads the hostname directly rather than going through
     * [[PullZone::getUrl()]]: files in a storage zone have no origin to fall back on, so the
     * `pullingEnabled` and per-zone `enabled` toggles must not be able to strip their URLs.
     *
     * @throws InvalidConfigException if the configured pull zone doesn't exist
     * @throws \craft\errors\SiteNotFoundException
     */
    public function getRootUrl(): ?string
    {
        if (!$this->hasUrls) {
            return null;
        }
        $url = $this->_getPullZone()->hostname;
        if ($this->subfolder !== '') {
            $url .= '/' . App::parseEnv($this->subfolder);
        }
        return StringHelper::ensureRight($url, '/');
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['storageZone', 'accessKey', 'region'], 'required'],
            [['pullZone'], 'required', 'when' => static fn(self $fs): bool => $fs->hasUrls],
            [['storageZone', 'accessKey', 'region', 'subfolder', 'pullZone'], 'string'],
        ]);
    }

    /**
     * @inheritdoc
     * @return BunnyCDNAdapter
     */
    protected function createAdapter(): FilesystemAdapter
    {
        $client = new BunnyCDNClient(
            (string)App::parseEnv($this->storageZone),
            (string)App::parseEnv($this->accessKey),
            (string)App::parseEnv($this->region),
        );
        return new BunnyCDNAdapter(
            client: $client,
            pullzone_url: '',
            root: (string)App::parseEnv($this->subfolder),
        );
    }

    /**
     * @inheritdoc
     *
     * Paths are collected over the course of the request and handed to a single queue job
     * afterwards, so that bulk operations (a folder rename, say) don't fire one blocking
     * HTTP request per file.
     */
    protected function invalidateCdnPath(string $path): bool
    {
        if (!BunnyMate::getInstance()->getPurge()->getIsEnabled()) {
            return true;
        }
        if (!$this->_purgeHandlerAttached) {
            Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'purgeQueuedPaths']);
            $this->_purgeHandlerAttached = true;
        }
        $this->pathsToInvalidate[$path] = true;
        return true;
    }

    /**
     * Hands any queued paths off to the purge queue job.
     *
     * @return void
     * @throws InvalidConfigException if the configured pull zone doesn't exist
     * @throws \craft\errors\SiteNotFoundException
     */
    public function purgeQueuedPaths(): void
    {
        if (empty($this->pathsToInvalidate)) {
            return;
        }
        $paths = array_keys($this->pathsToInvalidate);
        $this->pathsToInvalidate = [];
        $rootUrl = $this->getRootUrl();
        if ($rootUrl === null) {
            return;
        }
        $urls = array_map(static fn(string $path): string => $rootUrl . ltrim($path, '/'), $paths);
        BunnyMate::getInstance()->getPurge()->queueUrls($urls);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the pull zone that serves this filesystem.
     *
     * @return PullZone
     * @throws InvalidConfigException if the configured pull zone doesn't exist
     * @throws \craft\errors\SiteNotFoundException
     */
    private function _getPullZone(): PullZone
    {
        return BunnyMateHelper::getPullZone($this->pullZone);
    }

}

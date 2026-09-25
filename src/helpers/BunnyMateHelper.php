<?php

namespace vaersaagod\bunnymate\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\PullZone;

use yii\base\InvalidConfigException;

class BunnyMateHelper
{

    /** @var string[]|null Base URLs of the sites, volumes, pull zones and libraries BunnyMate knows */
    private static ?array $_knownBaseUrls = null;

    /**
     * Returns a pull zone URL for an asset, a path, or a URL.
     *
     * A path, or a URL on the current site, is moved onto the pull zone. So is an absolute URL
     * on a host BunnyMate knows nothing about, keeping its path, query string and fragment:
     * `https://example.com/a.mp4` becomes `https://zone.b-cdn.net/a.mp4`. That's for a pull
     * zone whose origin is that host.
     *
     * Everything else keeps its URL, as it always has: an asset on a remote volume, and any
     * string on a host BunnyMate does know -- a site, a volume, a pull zone or a video library.
     * Those are what `asset.url` resolves to, so a template passing one as a string rather
     * than the asset itself isn't moved onto an origin that can't serve it.
     *
     * @param string|Asset|null $pathOrAsset
     * @param string|null $pullZoneHandle
     * @return string
     * @throws \Exception
     */
    public static function bunnyPullUrl(string|Asset|null $pathOrAsset = '', ?string $pullZoneHandle = null): string
    {
        if (empty($pathOrAsset)) {
            return '';
        }
        $isAsset = $pathOrAsset instanceof Asset;
        $path = $isAsset ? ($pathOrAsset->getUrl() ?? '') : $pathOrAsset;
        if (empty($path)) {
            return '';
        }
        return static::getPullZone($pullZoneHandle)
            ->getUrl($path, rewriteAbsoluteUrls: !$isAsset && !static::_isKnownUrl($path));
    }

    /**
     * Returns whether a URL sits under a base URL, ignoring the scheme and letter case.
     *
     * Compared against the whole base, path included, so `cdn.example.com/site` doesn't claim
     * `cdn.example.com/other`.
     *
     * @param string $url
     * @param string $base
     * @return bool
     * @since 3.2.0
     */
    public static function isUrlUnder(string $url, string $base): bool
    {
        $strip = static fn(string $u): string => (string)preg_replace('/^(https?:)?\/\//i', '', $u);
        $base = rtrim($strip($base), '/');
        $url = $strip($url);

        if ($base === '') {
            return false;
        }

        return strcasecmp($url, $base) === 0
            || stripos($url, "$base/") === 0
            || stripos($url, "$base?") === 0
            || stripos($url, "$base#") === 0;
    }

    /**
     * @param string|null $pullZoneHandle
     * @return PullZone
     * @throws InvalidConfigException
     * @throws \craft\errors\SiteNotFoundException
     */
    public static function getPullZone(?string $pullZoneHandle = null): PullZone
    {
        $settings = BunnyMate::getInstance()->getSettings();
        $pullZoneHandle = $pullZoneHandle ?? $settings->defaultPullZone ?? null;
        if (empty($pullZoneHandle)) {
            throw new \RuntimeException("No pull zone handle defined");
        }
        $pullZoneConfig = $settings->pullZones[$pullZoneHandle] ?? null;
        if (!$pullZoneConfig) {
            throw new InvalidConfigException("Invalid pull zone \"$pullZoneHandle\"");
        }
        return new PullZone($pullZoneConfig);
    }


    // Private Methods
    // =========================================================================

    /**
     * Returns whether an absolute URL is on a host BunnyMate already knows: a site, a volume,
     * a pull zone or a video library.
     *
     * @param string $url
     * @return bool
     */
    private static function _isKnownUrl(string $url): bool
    {
        foreach (static::_knownBaseUrls() as $base) {
            if (static::isUrlUnder($url, $base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    private static function _knownBaseUrls(): array
    {
        if (static::$_knownBaseUrls !== null) {
            return static::$_knownBaseUrls;
        }

        $bases = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $bases[] = (string)$site->getBaseUrl();
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            try {
                $bases[] = (string)$volume->getRootUrl();
            } catch (\Throwable) {
                // A volume whose filesystem can't be resolved has no URL to protect
            }
        }

        $settings = BunnyMate::getInstance()->getSettings();

        foreach ($settings->pullZones as $config) {
            $bases[] = (string)App::parseEnv((string)($config['hostname'] ?? ''));
        }

        $stream = BunnyMate::getInstance()->getStream();
        foreach (array_keys($settings->videoLibraries) as $handle) {
            try {
                $bases[] = $stream->getLibrary($handle)->hostname;
            } catch (\Throwable) {
                // Misconfigured; it fails loudly wherever it's actually used
            }
        }

        return static::$_knownBaseUrls = array_values(array_unique(array_filter(
            $bases,
            // Only bases with a host of their own; a relative base URL can't be compared
            static fn(string $base): bool => UrlHelper::isAbsoluteUrl($base)
                || UrlHelper::isProtocolRelativeUrl($base)
                || (bool)preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(\/|$)/i', $base),
        )));
    }

}

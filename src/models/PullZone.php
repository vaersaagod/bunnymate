<?php

namespace vaersaagod\bunnymate\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\helpers\BunnyMateHelper;

class PullZone extends Model
{

    /** @var bool */
    public bool $enabled = true;

    /** @var string */
    public string $hostname;

    /**
     * @param $config
     * @throws \craft\errors\SiteNotFoundException
     */
    public function __construct($config = [])
    {
        $hostname = $config['hostname'];
        if (!UrlHelper::isFullUrl($hostname)) {
            $hostname = StringHelper::ensureLeft($hostname, 'https://');
        } else {
            $hostname = UrlHelper::urlWithScheme($hostname, 'https');
        }
        $config['hostname'] = StringHelper::removeRight($hostname, '/');
        parent::__construct($config);
    }

    /**
     * Returns a path, or a URL on the current site, on this pull zone.
     *
     * An absolute URL on some other host is returned untouched, unless `$rewriteAbsoluteUrls`
     * is set, in which case its path, query string and fragment are moved onto this pull zone.
     * That's only right when the pull zone's origin serves the same paths as that host.
     *
     * @param string $path
     * @param bool $rewriteAbsoluteUrls Whether an absolute URL on another host is moved onto this pull zone
     * @return string
     * @throws \craft\errors\SiteNotFoundException
     */
    public function getUrl(string $path = '', bool $rewriteAbsoluteUrls = false): string
    {
        $baseSiteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
        $url = StringHelper::removeLeft($path, $baseSiteUrl);
        $isAbsolute = UrlHelper::isAbsoluteUrl($url) || UrlHelper::isProtocolRelativeUrl($url);

        if ($isAbsolute && $rewriteAbsoluteUrls && $this->_isActive() && !$this->_isOwnUrl($url)) {
            $url = $this->_pathOf($url);
            $isAbsolute = false;
        }

        if (!$this->_isActive() || $isAbsolute) {
            if (!$url) {
                return '';
            }
            if (UrlHelper::isFullUrl($url)) {
                return $url;
            }
            return UrlHelper::url($url);
        }
        if ($url) {
            $url = StringHelper::ensureLeft($url, '/');
        }
        return UrlHelper::url($this->hostname . $url);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether pull zone URLs should be produced at all.
     *
     * @return bool
     */
    private function _isActive(): bool
    {
        return $this->enabled && BunnyMate::getInstance()->getSettings()->pullingEnabled;
    }

    /**
     * Returns whether a URL is already on this pull zone, so rewriting it again is a no-op.
     *
     * @param string $url
     * @return bool
     */
    private function _isOwnUrl(string $url): bool
    {
        return BunnyMateHelper::isUrlUnder($url, $this->hostname);
    }

    /**
     * Returns an absolute URL's path, query string and fragment, without its scheme and host.
     *
     * @param string $url
     * @return string
     */
    private function _pathOf(string $url): string
    {
        // parse_url() reads a protocol-relative URL's host correctly, but give it a scheme
        // anyway so the result doesn't depend on that
        $parts = parse_url(UrlHelper::isProtocolRelativeUrl($url) ? "https:$url" : $url) ?: [];

        return ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

}

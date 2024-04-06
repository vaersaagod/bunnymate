<?php

namespace vaersaagod\bunnymate\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\BunnyMate;

class PullZone extends Model
{

    /** @var bool */
    public bool $enabled = true;

    /** @var string */
    public string $hostname;

    /**
     * @param array $config
     * @throws \craft\errors\SiteNotFoundException
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        if (!UrlHelper::isFullUrl($this->hostname)) {
            $this->hostname = StringHelper::ensureLeft($this->hostname, 'https://');
        } else {
            $this->hostname = UrlHelper::urlWithScheme($this->hostname, 'https');
        }
        $this->hostname = StringHelper::removeRight($this->hostname, '/');
    }

    /**
     * @param string $path
     * @return string
     */
    public function getUrl(string $path = ''): string
    {
        $baseSiteUrl = Craft::getAlias('@web');
        $url = StringHelper::removeLeft($path, $baseSiteUrl);
        if (
            !$this->enabled
            || !BunnyMate::getInstance()->getSettings()->pullingEnabled
            || UrlHelper::isAbsoluteUrl($url)
            || UrlHelper::isProtocolRelativeUrl($url)
        ) {
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

}

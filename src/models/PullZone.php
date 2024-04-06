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
     * @param string $path
     * @return string
     * @throws \craft\errors\SiteNotFoundException
     */
    public function getUrl(string $path = ''): string
    {
        $baseSiteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
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

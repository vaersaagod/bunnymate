<?php

namespace vaersaagod\bunnymate\helpers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\PullZone;

use yii\base\InvalidConfigException;

class BunnyMateHelper
{

    /**
     * @param string $path
     * @param string|null $pullzone
     * @return string
     * @throws \Exception
     */
    public static function bunnyPullUrl(string $path = '', ?string $pullzone = null): string
    {
        $pullZone = static::getPullZone($pullzone);
        return $pullZone->getUrl($path);
    }

    /**
     * @param string|null $key
     * @return array
     * @throws \Exception
     */
    public static function getPullZone(?string $key = null): PullZone
    {
        $settings = Bunny::getInstance()->getSettings();
        $key = $key ?? $settings->defaultPullZone;
        $pullZoneConfig = $settings->pullZones[$key] ?? null;
        if (!$pullZoneConfig) {
            throw new InvalidConfigException("Invalid pull zone \"$key\"");
        }
        return new PullZone($pullZoneConfig);
    }

}

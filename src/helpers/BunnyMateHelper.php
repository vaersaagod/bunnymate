<?php

namespace vaersaagod\bunnymate\helpers;

use craft\elements\Asset;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\PullZone;

use yii\base\InvalidConfigException;

class BunnyMateHelper
{

    /**
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
        if ($pathOrAsset instanceof Asset) {
            $path = $pathOrAsset->getUrl();
        } else {
            $path = $pathOrAsset;
        }
        return static::getPullZone($pullZoneHandle)
            ->getUrl($path);
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

}

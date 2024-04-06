<?php

namespace vaersaagod\bunnymate;

use Craft;
use craft\base\Plugin;

use vaersaagod\bunnymate\models\Settings;
use vaersaagod\bunnymate\web\twig\BunnyMateExtension;

class BunnyMate extends Plugin
{

    /** @var string */
    public string $schemaVersion = '1.0.0';

    /** @var bool */
    public bool $hasCpSettings = false;

    /** @var bool */
    public bool $hasCpSection = false;

    /** @inheritdoc */
    public function init()
    {
        parent::init();

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
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

}

<?php

namespace vaersaagod\bunnymate;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fs;

use vaersaagod\bunnymate\fs\BunnyStorageFs;
use vaersaagod\bunnymate\models\Settings;
use vaersaagod\bunnymate\services\Purge;
use vaersaagod\bunnymate\web\twig\BunnyMateExtension;

use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * BunnyMate plugin
 *
 * @property-read Purge $purge
 *
 * @author Værsågod
 * @since 1.0.0
 */
class BunnyMate extends Plugin
{

    // Public Properties
    // =========================================================================

    /** @var string */
    public string $schemaVersion = '1.0.0';

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

}

<?php

namespace vaersaagod\bunnymate\models;

use craft\base\Model;

class Settings extends Model
{

    /** @var bool */
    public bool $pullingEnabled = true;

    /** @var array[] */
    public array $pullZones = [];

    /** @var string */
    public string $defaultPullZone;

}

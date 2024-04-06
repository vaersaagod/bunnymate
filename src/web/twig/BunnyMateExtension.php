<?php

namespace vaersaagod\bunnymate\web\twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use vaersaagod\bunnymate\helpers\BunnyMateHelper;

class BunnyMateExtension extends AbstractExtension
{

    /**
     * @return TwigFunction[]
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('bunnyPullUrl', [BunnyMateHelper::class, 'bunnyPullUrl']),
        ];
    }

}

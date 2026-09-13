<?php

namespace vaersaagod\bunnymate\queue\jobs;

use Craft;
use craft\queue\BaseJob;

use vaersaagod\bunnymate\BunnyMate;

/**
 * Purges URLs from the Bunny CDN cache.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class PurgeUrls extends BaseJob
{

    // Public Properties
    // =========================================================================

    /** @var string[] The URLs to purge */
    public array $urls = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $purge = BunnyMate::getInstance()->getPurge();
        $total = count($this->urls);
        foreach (array_values($this->urls) as $index => $url) {
            $this->setProgress($queue, $total > 0 ? ($index / $total) : 1, Craft::t('bunnymate', '{step} of {total}', [
                'step' => $index + 1,
                'total' => $total,
            ]));
            $purge->purgeUrl($url);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('bunnymate', 'Purging the Bunny CDN cache');
    }

}

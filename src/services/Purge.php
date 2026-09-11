<?php

namespace vaersaagod\bunnymate\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Queue;

use GuzzleHttp\Exception\GuzzleException;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\queue\jobs\PurgeUrls;

use yii\base\Component;

/**
 * Purge service. Purges URLs from the Bunny CDN cache.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class Purge extends Component
{

    // Const Properties
    // =========================================================================

    /**
     * The Bunny API endpoint used to purge a single URL.
     *
     * @see https://bunny.net/docs/reference/purgepublic_indexpost
     */
    public const PURGE_ENDPOINT = 'https://api.bunny.net/purge';

    // Public Methods
    // =========================================================================

    /**
     * Returns whether cache purging is enabled and configured.
     *
     * @return bool
     */
    public function getIsEnabled(): bool
    {
        $settings = BunnyMate::getInstance()->getSettings();
        if (!$settings->purgeEnabled) {
            return false;
        }
        return !empty($this->_getApiKey());
    }

    /**
     * Pushes a queue job that purges the given URLs from the CDN cache.
     *
     * Purging is deferred to the queue so that asset saves, deletes and folder
     * renames aren't blocked by one HTTP request per file.
     *
     * @param string[] $urls
     * @return void
     */
    public function queueUrls(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (empty($urls) || !$this->getIsEnabled()) {
            return;
        }
        Queue::push(new PurgeUrls([
            'urls' => $urls,
        ]));
    }

    /**
     * Purges a single URL from the CDN cache.
     *
     * Failures are logged rather than thrown: a stale edge cache shouldn't fail
     * the operation that triggered the purge.
     *
     * @param string $url
     * @return bool Whether the URL was purged successfully
     */
    public function purgeUrl(string $url): bool
    {
        $apiKey = $this->_getApiKey();
        if (empty($apiKey)) {
            Craft::warning("Unable to purge \"$url\": no Bunny API key configured", __METHOD__);
            return false;
        }
        try {
            Craft::createGuzzleClient()
                ->post(self::PURGE_ENDPOINT, [
                    'headers' => [
                        'AccessKey' => $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        'url' => $url,
                        'async' => 'false',
                    ],
                    'timeout' => 30,
                ]);
        } catch (GuzzleException $e) {
            Craft::error("Failed to purge \"$url\": {$e->getMessage()}", __METHOD__);
            return false;
        }
        Craft::info("Purged \"$url\" from the Bunny CDN cache", __METHOD__);
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the parsed Bunny account API key, if there is one.
     *
     * @return string|null
     */
    private function _getApiKey(): ?string
    {
        $apiKey = BunnyMate::getInstance()->getSettings()->apiKey;
        if (empty($apiKey)) {
            return null;
        }
        $apiKey = App::parseEnv($apiKey);
        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

}

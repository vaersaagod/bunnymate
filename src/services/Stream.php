<?php

namespace vaersaagod\bunnymate\services;

use Craft;
use craft\helpers\Json;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\models\VideoLibrary;

use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Stream service. Talks to the Bunny Stream API.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class Stream extends Component
{

    // Const Properties
    // =========================================================================

    /** @see https://bunny.net/docs/stream/http-api/ */
    public const API_BASE = 'https://video.bunnycdn.com';

    // Private Properties
    // =========================================================================

    /** @var VideoLibrary[] Memoized libraries, keyed by handle */
    private array $_libraries = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns a configured video library.
     *
     * @param string $handle The library's key in the `videoLibraries` setting
     * @return VideoLibrary
     * @throws InvalidConfigException if the library isn't configured, or is misconfigured
     */
    public function getLibrary(string $handle): VideoLibrary
    {
        if (isset($this->_libraries[$handle])) {
            return $this->_libraries[$handle];
        }
        $config = BunnyMate::getInstance()->getSettings()->videoLibraries[$handle] ?? null;
        if (!is_array($config)) {
            throw new InvalidConfigException("Invalid video library \"$handle\"");
        }
        $library = new VideoLibrary([...$config, 'handle' => $handle]);
        if (!$library->validate()) {
            $errors = implode(' ', array_merge(...array_values($library->getErrors())));
            throw new InvalidConfigException("Misconfigured video library \"$handle\": $errors");
        }
        return $this->_libraries[$handle] = $library;
    }

    /**
     * Returns the library a given volume's videos belong to.
     *
     * @param string $volumeHandle
     * @return VideoLibrary|null Null if the volume isn't set up for Bunny Stream
     * @throws InvalidConfigException if the volume maps to a library that isn't configured
     */
    public function getLibraryForVolume(string $volumeHandle): ?VideoLibrary
    {
        $settings = BunnyMate::getInstance()->getSettings();
        $libraryHandle = $settings->volumeVideoLibraries[$volumeHandle] ?? null;
        if (empty($libraryHandle)) {
            return null;
        }
        return $this->getLibrary($libraryHandle);
    }

    /**
     * Returns a library by its Bunny library ID.
     *
     * Used to resolve incoming webhooks, which identify the library by ID rather than handle.
     *
     * @param string|int $libraryId
     * @return VideoLibrary|null
     */
    public function getLibraryById(string|int $libraryId): ?VideoLibrary
    {
        $settings = BunnyMate::getInstance()->getSettings();
        foreach (array_keys($settings->videoLibraries) as $handle) {
            try {
                $library = $this->getLibrary($handle);
            } catch (InvalidConfigException $e) {
                Craft::warning($e->getMessage(), __METHOD__);
                continue;
            }
            if ($library->matchesId($libraryId)) {
                return $library;
            }
        }
        return null;
    }

    /**
     * Creates an empty video in a library, and returns its GUID.
     *
     * The binary is uploaded separately, either by the browser over TUS or by [[fetchVideo()]].
     *
     * @param VideoLibrary $library
     * @param string $title
     * @return string The new video's GUID
     * @throws GuzzleException
     * @throws \Exception if Bunny doesn't return a GUID
     */
    public function createVideo(VideoLibrary $library, string $title): string
    {
        $payload = ['title' => $title];
        if (!empty($library->collectionId)) {
            $payload['collectionId'] = $library->collectionId;
        }
        $response = $this->_request($library, 'POST', "/library/$library->id/videos", [
            'json' => $payload,
        ]);
        $guid = $response['guid'] ?? null;
        if (empty($guid)) {
            throw new \Exception('Bunny did not return a video GUID');
        }
        return $guid;
    }

    /**
     * Tells Bunny to fetch a video from a remote URL.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @param string $url The URL Bunny should pull the video from
     * @param array $headers Any headers Bunny should send when fetching
     * @return bool
     * @throws GuzzleException
     */
    public function fetchVideo(VideoLibrary $library, string $videoGuid, string $url, array $headers = []): bool
    {
        $payload = ['url' => $url];
        if (!empty($headers)) {
            $payload['headers'] = $headers;
        }
        $response = $this->_request($library, 'POST', "/library/$library->id/videos/$videoGuid/fetch", [
            'json' => $payload,
        ]);
        return (bool)($response['success'] ?? false);
    }

    /**
     * Returns a video's current state from Bunny.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @return array|null Null if the video doesn't exist
     * @throws GuzzleException
     */
    public function getVideo(VideoLibrary $library, string $videoGuid): ?array
    {
        try {
            return $this->_request($library, 'GET', "/library/$library->id/videos/$videoGuid");
        } catch (\RuntimeException $e) {
            Craft::warning("Unable to get video \"$videoGuid\": {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * Deletes a video from Bunny.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @return bool
     */
    public function deleteVideo(VideoLibrary $library, string $videoGuid): bool
    {
        try {
            $this->_request($library, 'DELETE', "/library/$library->id/videos/$videoGuid");
        } catch (\Throwable $e) {
            Craft::error("Unable to delete video \"$videoGuid\": {$e->getMessage()}", __METHOD__);
            return false;
        }
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Sends a request to the Bunny Stream API and returns the decoded response.
     *
     * @param VideoLibrary $library
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return array
     * @throws GuzzleException
     * @throws \RuntimeException if Bunny returns a non-2xx response
     */
    private function _request(VideoLibrary $library, string $method, string $uri, array $options = []): array
    {
        $response = $this->_getClient()->request($method, self::API_BASE . $uri, [
            ...$options,
            'headers' => [
                'AccessKey' => $library->apiKey,
                'Accept' => 'application/json',
                ...($options['headers'] ?? []),
            ],
        ]);
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Bunny Stream returned HTTP $status for $method $uri: $body");
        }
        return Json::decodeIfJson($body) ?: [];
    }

    /**
     * @return Client
     */
    private function _getClient(): Client
    {
        return Craft::createGuzzleClient([
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

}

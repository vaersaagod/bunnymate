<?php

namespace vaersaagod\bunnymate\services;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;

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

    /**
     * How long a HEAD request for one of a video's files may take, in seconds.
     *
     * These probe which MP4 renditions exist and how large each file is. They run one after
     * another inside a webhook request and the Bunny Stream panel's Refresh button, so they're
     * kept short: a probe that times out leaves what was stored from last time in place.
     */
    private const HEAD_REQUEST_TIMEOUT = 4;

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
     * Returns a library by its Bunny library ID, or throws if it isn't configured.
     *
     * Videos record the library they live in by ID, so a row whose library has been dropped
     * from the config can't be acted on at all -- its URLs can't be signed and its video can't
     * be deleted from Bunny. Saying which ID is missing is the only useful thing left to do.
     *
     * @param string|int $libraryId
     * @return VideoLibrary
     * @throws InvalidConfigException if no configured library has that ID
     */
    public function requireLibraryById(string|int $libraryId): VideoLibrary
    {
        return $this->getLibraryById($libraryId)
            ?? throw new InvalidConfigException("No video library is configured with the ID \"$libraryId\".");
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
     * Returns every video in a library.
     *
     * @param VideoLibrary $library
     * @return array[]|null Null if the library couldn't be listed
     * @throws GuzzleException
     */
    public function getVideos(VideoLibrary $library): ?array
    {
        $videos = [];
        $page = 1;

        do {
            $response = $this->_request($library, 'GET', "/library/$library->id/videos?page=$page&itemsPerPage=100");
            $items = $response['items'] ?? [];
            $videos = [...$videos, ...$items];
            $page++;
            // Bunny reports the total, so paging stops once everything is accounted for
            $total = (int)($response['totalItems'] ?? count($videos));
        } while (!empty($items) && count($videos) < $total);

        return $videos;
    }

    /**
     * Works out which renditions a video actually has an MP4 for.
     *
     * Bunny's MP4 fallback stops short of the renditions it encodes for HLS, and nothing in
     * the API says where: the video payload carries `availableResolutions`, which describes
     * the HLS set, and `hasMP4Fallback`, which is a plain boolean. So the only way to know is
     * to ask for the files.
     *
     * MP4s are produced from the bottom up, so the highest one that exists settles the rest.
     * That makes this a handful of requests rather than one per rendition, and it only runs
     * when a video's metadata is refreshed, not per page view.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @param string[] $available The renditions Bunny reports, ascending
     * @return string[] Those an MP4 exists for, ascending
     */
    public function detectMp4Resolutions(VideoLibrary $library, string $videoGuid, array $available): array
    {
        $client = Craft::createGuzzleClient([
            'http_errors' => false,
            'timeout' => self::HEAD_REQUEST_TIMEOUT,
        ]);

        foreach (array_reverse($available) as $index => $resolution) {
            $url = $library->getVideoUrl($videoGuid, "play_$resolution.mp4");
            try {
                $response = $client->head($url, [
                    'headers' => [
                        // Libraries block referrer-less requests by default
                        'Referer' => UrlHelper::baseSiteUrl(),
                    ],
                ]);
            } catch (GuzzleException $e) {
                Craft::warning("Unable to probe \"$url\": {$e->getMessage()}", __METHOD__);
                return [];
            }
            if ($response->getStatusCode() < 400) {
                // Everything at or below this one is present
                return array_slice($available, 0, count($available) - $index);
            }
        }

        return [];
    }

    /**
     * Returns the size of a video's original file, in bytes.
     *
     * The video payload's `storageSize` covers every rendition plus the original, so it says
     * nothing useful about the uploaded file on its own. This asks for it directly.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @return int|null Null when the original isn't available
     */
    public function getOriginalSize(VideoLibrary $library, string $videoGuid): ?int
    {
        return $this->_getFileSize($library, $videoGuid, 'original');
    }

    /**
     * Returns the sizes of a video's MP4 renditions, in bytes, keyed by resolution.
     *
     * Bunny's API has no per-file sizes, so each rendition is asked for directly: one HEAD
     * request apiece, and only when a video's metadata is refreshed.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @param string[] $resolutions Renditions an MP4 exists for, e.g. from [[detectMp4Resolutions()]]
     * @return array<string, int> Those whose size could be read
     * @since 3.2.0
     */
    public function getMp4Sizes(VideoLibrary $library, string $videoGuid, array $resolutions): array
    {
        $sizes = [];
        foreach ($resolutions as $resolution) {
            $size = $this->_getFileSize($library, $videoGuid, "play_$resolution.mp4");
            if ($size !== null) {
                $sizes[$resolution] = $size;
            }
        }
        return $sizes;
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
     * Returns the size of one of a video's files, in bytes, from a HEAD request.
     *
     * @param VideoLibrary $library
     * @param string $videoGuid
     * @param string $file e.g. `original`, `play_720p.mp4`
     * @return int|null Null when the file isn't there or reports no length
     */
    private function _getFileSize(VideoLibrary $library, string $videoGuid, string $file): ?int
    {
        try {
            $response = Craft::createGuzzleClient(['http_errors' => false, 'timeout' => self::HEAD_REQUEST_TIMEOUT])
                ->head($library->getVideoUrl($videoGuid, $file), [
                    'headers' => [
                        // Libraries block referrer-less requests by default
                        'Referer' => UrlHelper::baseSiteUrl(),
                    ],
                ]);
        } catch (GuzzleException $e) {
            Craft::warning("Unable to size \"$file\" for \"$videoGuid\": {$e->getMessage()}", __METHOD__);
            return null;
        }

        if ($response->getStatusCode() >= 400) {
            return null;
        }

        $length = (int)$response->getHeaderLine('Content-Length');

        return $length > 0 ? $length : null;
    }

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

<?php

namespace vaersaagod\bunnymate\models;

use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;

/**
 * A Bunny Stream video library, as configured in `config/_bunnymate.php`.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class VideoLibrary extends Model
{

    // Public Properties
    // =========================================================================

    /** @var string The library's handle, as keyed in the `videoLibraries` config array */
    public string $handle = '';

    /** @var string|int The Bunny video library ID */
    public string|int $id = '';

    /** @var string The library's API key. Grants write access, so keep it server-side. */
    public string $apiKey = '';

    /**
     * @var string The library's read-only API key.
     *
     * Bunny signs webhook payloads with this, so it doubles as the webhook signing secret.
     */
    public string $readOnlyApiKey = '';

    /** @var string The library's playback hostname, e.g. `vz-xxxxxxxx-xxx.b-cdn.net` */
    public string $hostname = '';

    /**
     * @var string|null The pull zone's token authentication key.
     *
     * Only needed when token authentication is enabled on the library's pull zone, in which
     * case every playback URL must be signed. See [[signUrl()]].
     */
    public ?string $tokenAuthKey = null;

    /** @var int How long signed URLs should remain valid, in seconds */
    public int $signedUrlDuration = 3600;

    /**
     * @var bool Whether the library has player token authentication enabled.
     *
     * This is a different thing from the pull zone's token authentication, and Bunny exposes
     * it as a separate switch: the pull zone's key guards the playback files, while this
     * guards the iframe player at `iframe.mediadelivery.net`. They're enabled independently
     * and signed with different keys, so both have to be configured to match Bunny.
     */
    public bool $playerTokenAuthEnabled = false;

    /**
     * @var bool Whether Bunny Optimizer is enabled on this library's pull zone.
     *
     * Optimizer is what resizes images from the URL. With it off, `?width=` is ignored and
     * Bunny serves the full-resolution poster frame, so control panel thumbnails are the
     * whole image scaled down by the browser.
     */
    public bool $optimizerEnabled = false;

    /** @var string|null An optional collection to create videos in */
    public ?string $collectionId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        // Every credential can be set to an environment variable
        $this->id = (string)App::parseEnv((string)$this->id);
        $this->apiKey = (string)App::parseEnv($this->apiKey);
        $this->readOnlyApiKey = (string)App::parseEnv($this->readOnlyApiKey);
        $this->hostname = (string)App::parseEnv($this->hostname);
        if ($this->tokenAuthKey !== null) {
            $this->tokenAuthKey = (string)App::parseEnv($this->tokenAuthKey) ?: null;
        }
        // Normalize the hostname to a bare host, so URLs can be built predictably
        $this->hostname = rtrim(preg_replace('/^https?:\/\//', '', $this->hostname), '/');
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['id', 'apiKey', 'readOnlyApiKey', 'hostname'], 'required'],
            [['signedUrlDuration'], 'integer', 'min' => 60],
        ]);
    }

    /**
     * Returns whether playback URLs from this library need signing.
     *
     * @return bool
     */
    public function getIsTokenAuthEnabled(): bool
    {
        return !empty($this->tokenAuthKey);
    }

    /**
     * Returns a playback URL for a file belonging to the given video.
     *
     * @param string $videoGuid
     * @param string $file e.g. `playlist.m3u8`, `thumbnail.jpg`, `play_720p.mp4`
     * @return string
     */
    public function getVideoUrl(string $videoGuid, string $file, bool $directory = false): string
    {
        $path = "/$videoGuid/" . ltrim($file, '/');
        $url = "https://$this->hostname$path";

        // A token covers exactly the path it was signed over. HLS needs the whole video
        // directory, because the master playlist points at per-rendition sub-playlists and
        // those at segments, each fetched as its own request.
        $signPath = $directory ? "/$videoGuid/" : $path;

        return $this->signUrl($url, $signPath);
    }

    /**
     * Returns whether Bunny Optimizer is enabled on this library's pull zone.
     *
     * @return bool
     */
    public function getIsOptimizerEnabled(): bool
    {
        return $this->optimizerEnabled;
    }

    /**
     * Returns the iframe embed URL for a video.
     *
     * @param string $videoGuid
     * @param array $params Additional player params, e.g. `['autoplay' => 'true']`
     * @return string
     */
    public function getEmbedUrl(string $videoGuid, array $params = []): string
    {
        $url = "https://iframe.mediadelivery.net/embed/$this->id/$videoGuid";

        if (!$this->playerTokenAuthEnabled) {
            return empty($params) ? $url : UrlHelper::urlWithParams($url, $params);
        }

        // The player is served by Bunny, not by the library's pull zone, so the pull zone's
        // token means nothing to it. It takes a library-level token instead, keyed on the
        // library's own API key -- the same signature TUS uploads are authorised with.
        $expires = time() + $this->signedUrlDuration;

        return UrlHelper::urlWithParams($url, [
            ...$params,
            'token' => $this->getPlayerToken($videoGuid, $expires),
            'expires' => $expires,
        ]);
    }

    /**
     * Returns the token the iframe player expects, for a library with player token
     * authentication enabled.
     *
     * @param string $videoGuid
     * @param int $expires UNIX timestamp
     * @return string
     */
    public function getPlayerToken(string $videoGuid, int $expires): string
    {
        return hash('sha256', $this->apiKey . $videoGuid . $expires);
    }

    /**
     * Returns the token a playback URL expects, for a pull zone with token authentication
     * enabled.
     *
     * @param string $videoGuid
     * @param int $expires UNIX timestamp
     * @return string
     */
    public function getPlaybackToken(string $signPath, int $expires): string
    {
        // Bunny's CDN token authentication: the SHA256 of key + path + expiry, raw rather than
        // hex, in URL-safe base64 with the padding dropped. Signing the video GUID instead of
        // the path is rejected -- verified against a live token-authenticated pull zone.
        $raw = hash('sha256', $this->tokenAuthKey . $signPath . $expires, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Signs a URL for a token-authenticated library, or returns it untouched if token
     * authentication isn't enabled.
     *
     * Bunny expects the URL-safe base64 of `SHA256_RAW(tokenAuthKey + signPath + expires)`,
     * with the hash and the expiry appended as `token` and `expires` query params.
     *
     * `$signPath` is what the resulting token grants access to: a file path covers that one
     * file, and a path ending in a slash covers everything beneath it, nested paths included.
     *
     * @see https://bunny.net/docs/cdn/security/token-authentication/
     *
     * @param string $url
     * @param string $signPath Path the token should cover, e.g. `/{guid}/play_720p.mp4`
     * @param int|null $expires UNIX timestamp; defaults to now plus [[signedUrlDuration]]
     * @return string
     */
    public function signUrl(string $url, string $signPath, ?int $expires = null): string
    {
        if (!$this->getIsTokenAuthEnabled()) {
            return $url;
        }
        $expires ??= time() + $this->signedUrlDuration;

        return UrlHelper::urlWithParams($url, [
            'token' => $this->getPlaybackToken($signPath, $expires),
            'expires' => $expires,
        ]);
    }

    /**
     * Returns the signature a TUS upload needs, along with its expiry.
     *
     * Bunny expects `SHA256_HEX(libraryId + apiKey + expires + videoGuid)`. The API key is
     * never sent to the browser; only the resulting signature is.
     *
     * @see https://bunny.net/docs/stream/tus-resumable-uploads/
     *
     * @param string $videoGuid
     * @param int|null $expires UNIX timestamp; defaults to now plus one hour
     * @return array{signature: string, expires: int, libraryId: string, videoId: string}
     */
    public function getUploadSignature(string $videoGuid, ?int $expires = null): array
    {
        $expires ??= time() + 3600;
        return [
            'signature' => hash('sha256', $this->id . $this->apiKey . $expires . $videoGuid),
            'expires' => $expires,
            'libraryId' => (string)$this->id,
            'videoId' => $videoGuid,
        ];
    }

    /**
     * Returns whether a given string matches this library's ID.
     *
     * @param string|int $libraryId
     * @return bool
     */
    public function matchesId(string|int $libraryId): bool
    {
        return (string)$libraryId === (string)$this->id;
    }

}

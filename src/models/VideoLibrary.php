<?php

namespace vaersaagod\bunnymate\models;

use craft\base\Model;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;

use vaersaagod\bunnymate\helpers\SignedUrls;

use yii\base\InvalidConfigException;

/**
 * A Bunny Stream video library, as configured in `config/bunnymate.php`.
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

    /**
     * @var mixed How long signed URLs should remain valid.
     *
     * A number of seconds, or a date interval string such as `'PT1H'`, matching how Craft's
     * own duration settings are written. Normalised to seconds in [[init()]], so everything
     * downstream can treat it as an int.
     */
    public mixed $signedUrlDuration = 3600;

    /**
     * @var bool Whether the library has player token authentication enabled.
     *
     * Bunny exposes this as a switch separate from CDN token authentication: that one guards
     * the playback files, this one the iframe player at `iframe.mediadelivery.net`. They're
     * enabled independently, and signed differently -- the player takes a hex digest over the
     * video GUID where the CDN takes base64 over a path -- but both are keyed on the pull
     * zone's security key, so this needs [[tokenAuthKey]] set either way.
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

    /**
     * @var bool Whether the control panel uploader sends videos for this library straight to
     * Bunny over TUS.
     *
     * With it off, the library's volumes are left to Craft's own uploader: the file is
     * stored in the volume like any other asset, and Bunny fetches it from the asset's URL,
     * which needs `autoUploadVideos` on and the volume reachable from the public internet.
     * That suits libraries holding smaller files, which fit through PHP's upload limits and
     * don't need a placeholder file standing in for them.
     *
     * @since 3.1.0
     */
    public bool $tusUploadsEnabled = true;

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

        // Seconds or a date interval string, as Craft's own duration settings take. Resolved
        // here so the rest of the model, and the validator's minimum, deal only in seconds.
        $duration = $this->signedUrlDuration;

        // A config file or an env var readily yields "3600" rather than 3600, and Craft's
        // helper only takes an int or an interval -- it would read the string as an interval
        // and fail on something that was never wrong
        if (is_string($duration) && is_numeric($duration)) {
            $duration = (int)$duration;
        }

        try {
            $this->signedUrlDuration = ConfigHelper::durationInSeconds($duration);
        } catch (\Throwable $e) {
            // A bad interval would otherwise surface as "must be an integer", which says
            // nothing about what was actually wrong with it
            throw new InvalidConfigException(
                "Invalid signedUrlDuration for video library \"$this->handle\": " . Json::encode($duration) .
                '. Expected a number of seconds, or a date interval string such as "PT1H".'
            );
        }
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['id', 'apiKey', 'readOnlyApiKey', 'hostname'], 'required'],
            [
                ['tokenAuthKey'],
                'required',
                'when' => static fn(self $model): bool => $model->playerTokenAuthEnabled,
                'message' => 'tokenAuthKey is required when playerTokenAuthEnabled is on: the player token is signed with the pull zone\'s security key.',
            ],
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
     * @param array $params Query params, e.g. Bunny Optimizer's `width` and `height`. Kept out
     *                      of `$file`, because a signed URL covers them separately from the path.
     * @return string
     */
    public function getVideoUrl(string $videoGuid, string $file, bool $defer = false, bool $directory = false, array $params = []): string
    {
        $path = "/$videoGuid/" . ltrim($file, '/');
        $url = "https://$this->hostname$path";

        // A token covers exactly the path it was signed over. HLS needs the whole video
        // directory, because the master playlist points at per-rendition sub-playlists and
        // those at segments, each fetched as its own request.
        if ($directory && $this->getIsTokenAuthEnabled()) {
            return $this->_signDirectoryUrl($path, "/$videoGuid/", $defer);
        }

        return $this->signUrl($url, $path, defer: $defer, params: $params);
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

        if (SignedUrls::shouldDefer()) {
            return UrlHelper::urlWithParams($url, [
                ...$params,
                'token' => SignedUrls::placeholder(SignedUrls::KIND_TOKEN, $this->handle, $videoGuid, SignedUrls::TYPE_EMBED),
                'expires' => SignedUrls::placeholder(SignedUrls::KIND_EXPIRES, $this->handle, $videoGuid, SignedUrls::TYPE_EMBED),
            ]);
        }

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
        // Hex SHA256 over the video GUID rather than a path, and unlike the CDN token it isn't
        // base64'd. Both are keyed on the pull zone's security key even though this one guards
        // the player -- verified against a library with embed view token authentication on.
        return hash('sha256', $this->tokenAuthKey . $videoGuid . $expires);
    }

    /**
     * Returns the token a playback URL expects, for a pull zone with token authentication
     * enabled.
     *
     * @param string $signPath
     * @param int $expires UNIX timestamp
     * @param array $params Any other query params the URL carries, which the token has to cover
     * @return string
     */
    public function getPlaybackToken(string $signPath, int $expires, array $params = []): string
    {
        // Bunny's CDN token authentication: the SHA256 of key + path + expiry, raw rather than
        // hex, in URL-safe base64 with the padding dropped. Signing the video GUID instead of
        // the path is rejected -- verified against a live token-authenticated pull zone.
        // Any other query params are hashed too, after the expiry: sorted by key and joined
        // as `key=value&key=value`, unencoded. Leave them out and Bunny answers 403.
        ksort($params);
        $paramData = implode('&', array_map(
            static fn(string|int $key, mixed $value): string => "$key=$value",
            array_keys($params),
            $params,
        ));

        $raw = hash('sha256', $this->tokenAuthKey . $signPath . $expires . $paramData, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Signs a URL for a token-authenticated library, or returns it untouched if token
     * authentication isn't enabled.
     *
     * Bunny expects the URL-safe base64 of `SHA256_RAW(tokenAuthKey + signPath + expires + params)`,
     * with the hash and the expiry appended as `token` and `expires` query params. Other query
     * params go in `$params` rather than on `$url`, so they're covered by the token.
     *
     * `$signPath` is what the resulting token grants access to: a file path covers that one
     * file, and a path ending in a slash covers everything beneath it, nested paths included.
     *
     * @see https://bunny.net/docs/cdn/security/token-authentication/
     *
     * @param string $url
     * @param string $signPath Path the token should cover, e.g. `/{guid}/play_720p.mp4`
     * @param int|null $expires UNIX timestamp; defaults to now plus [[signedUrlDuration]]
     * @param bool $defer
     * @param array $params Query params to add to the URL, e.g. `['width' => 240]`
     * @return string
     */
    public function signUrl(string $url, string $signPath, ?int $expires = null, bool $defer = false, array $params = []): string
    {
        if (!$this->getIsTokenAuthEnabled()) {
            return empty($params) ? $url : UrlHelper::urlWithParams($url, $params);
        }
        // Signing now would bake an expiry into whatever cache this ends up in, so on site
        // requests the real token is minted as the response goes out instead
        if ($defer && $expires === null && SignedUrls::shouldDefer()) {
            return UrlHelper::urlWithParams($url, [
                ...$params,
                'token' => SignedUrls::placeholder(SignedUrls::KIND_TOKEN, $this->handle, $signPath, params: $params),
                'expires' => SignedUrls::placeholder(SignedUrls::KIND_EXPIRES, $this->handle, $signPath, params: $params),
            ]);
        }

        $expires ??= time() + $this->signedUrlDuration;

        return UrlHelper::urlWithParams($url, [
            ...$params,
            'token' => $this->getPlaybackToken($signPath, $expires, $params),
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


    // Private Methods
    // =========================================================================

    /**
     * Signs a URL with a token covering a whole directory, carried in the path.
     *
     * A query string token only ever reaches the request it's on. Bunny's HLS playlists
     * name their renditions, audio and segments by relative URL, and resolving a relative URL
     * drops the query string, so every request after the master playlist went out unsigned
     * and got a 403. With the token in a `bcdn_token=` path segment instead, relative URLs
     * resolve beneath it and each request carries it. `token_path` is part of what's hashed,
     * which is Bunny's own format for a directory token.
     *
     * @see https://bunny.net/docs/cdn/security/token-authentication/
     *
     * @param string $path e.g. `/{guid}/playlist.m3u8`
     * @param string $signPath The directory the token covers, e.g. `/{guid}/`
     * @param bool $defer
     * @return string
     */
    private function _signDirectoryUrl(string $path, string $signPath, bool $defer): string
    {
        $params = ['token_path' => $signPath];

        // As in signUrl(): on site requests, leave placeholders for the response to fill in.
        // They're built from characters that are safe in a path segment.
        if ($defer && SignedUrls::shouldDefer()) {
            $token = SignedUrls::placeholder(SignedUrls::KIND_TOKEN, $this->handle, $signPath, params: $params);
            $expires = SignedUrls::placeholder(SignedUrls::KIND_EXPIRES, $this->handle, $signPath, params: $params);
        } else {
            $expires = time() + $this->signedUrlDuration;
            $token = $this->getPlaybackToken($signPath, $expires, $params);
        }

        return "https://$this->hostname/bcdn_token=$token&token_path=" . rawurlencode($signPath) . "&expires=$expires$path";
    }

}

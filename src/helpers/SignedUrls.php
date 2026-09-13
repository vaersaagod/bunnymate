<?php

namespace vaersaagod\bunnymate\helpers;

use Craft;
use craft\helpers\Json;
use craft\web\Request as WebRequest;

use vaersaagod\bunnymate\BunnyMate;

/**
 * Defers URL signing until the response is prepared.
 *
 * A signed URL carries an expiry, which makes signed URLs and cached HTML fundamentally
 * incompatible: a token baked into a `{% cache %}` block dies at `signedUrlDuration`, and the
 * page then serves 403s for as long as the cache lives. Signing at render time is the problem,
 * so on site requests BunnyMate renders a placeholder instead and mints the real token as the
 * response goes out -- after any template cache has been written to or read from.
 *
 * Placeholders are built from characters that survive both `json_encode` and HTML escaping, so
 * they come back out of a `data-sources="[{...}]"` attribute intact. That matters: `json_encode`
 * turns `/` into `\/` and HTML escaping rewrites `&`, either of which would corrupt a token
 * carried through an attribute.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class SignedUrls
{

    // Const Properties
    // =========================================================================

    /** Marks a deferred token. Alphanumeric, so nothing escapes it. */
    public const KIND_TOKEN = 'tok';

    /** Marks the matching expiry, which has to agree with the token it was signed alongside */
    public const KIND_EXPIRES = 'exp';

    /** A CDN token, signed over the path it grants access to */
    public const TYPE_CDN = 'cdn';

    /** An embed view token, signed over a video GUID and digested differently */
    public const TYPE_EMBED = 'embed';

    private const PREFIX = 'BMSIG';

    /** Matches a placeholder and captures its kind and payload */
    private const PATTERN = '/' . self::PREFIX . '(tok|exp)\.([A-Za-z0-9_\-]+)\.' . self::PREFIX . '/';

    // Public Methods
    // =========================================================================

    /**
     * Returns whether signing should be deferred to the response.
     *
     * Only site requests: a control panel URL is never template-cached, and anything BunnyMate
     * fetches itself -- the MP4 probe, the original-size HEAD, the download controller -- needs
     * a token it can use immediately, not one that resolves later.
     *
     * @return bool
     */
    public static function shouldDefer(): bool
    {
        $request = Craft::$app->getRequest();

        return $request instanceof WebRequest && $request->getIsSiteRequest();
    }

    /**
     * Returns a placeholder standing in for a token or its expiry.
     *
     * The payload is signed with Craft's security key, so a placeholder appearing in
     * user-submitted content can't make the server mint a token for a video of the sender's
     * choosing.
     *
     * @param string $kind One of the `KIND_*` constants
     * @param string $libraryHandle
     * @param string $subject What the token is signed over: a path for [[TYPE_CDN]], a video
     *                         GUID for [[TYPE_EMBED]]
     * @param string $type One of the `TYPE_*` constants
     * @return string
     */
    public static function placeholder(string $kind, string $libraryHandle, string $subject, string $type = self::TYPE_CDN): string
    {
        $payload = self::_encode(['h' => $libraryHandle, 'p' => $subject, 't' => $type]);

        return self::PREFIX . $kind . '.' . $payload . '.' . self::PREFIX;
    }

    /**
     * Replaces every placeholder in a response body with a freshly signed value.
     *
     * @param string $content
     * @return string
     */
    public static function substitute(string $content): string
    {
        if (!str_contains($content, self::PREFIX)) {
            return $content;
        }

        // A token and its expiry have to agree, so each library gets one expiry for the whole
        // response rather than one per placeholder, which a slow render could spread over
        // more than a second
        $expiresByLibrary = [];

        return preg_replace_callback(
            self::PATTERN,
            static function (array $match) use (&$expiresByLibrary): string {
                [, $kind, $payload] = $match;

                $data = self::_decode($payload);
                if ($data === null) {
                    // Tampered with, or signed by a different Craft install. Leaving the
                    // placeholder in place would be worse than an unsigned URL that 403s
                    // visibly, so it's stripped.
                    return '';
                }

                ['h' => $handle, 'p' => $subject] = $data;
                $type = $data['t'] ?? self::TYPE_CDN;

                try {
                    $library = BunnyMate::getInstance()->getStream()->getLibrary($handle);
                } catch (\Throwable $e) {
                    Craft::error($e->getMessage(), __METHOD__);
                    return '';
                }

                $expires = $expiresByLibrary[$handle] ??= time() + $library->signedUrlDuration;

                if ($kind === self::KIND_EXPIRES) {
                    return (string)$expires;
                }

                return $type === self::TYPE_EMBED
                    ? $library->getPlayerToken($subject, $expires)
                    : $library->getPlaybackToken($subject, $expires);
            },
            $content
        ) ?? $content;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array $data
     * @return string
     */
    private static function _encode(array $data): string
    {
        $json = Json::encode($data);
        $base64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return Craft::$app->getSecurity()->hashData($base64);
    }

    /**
     * @param string $payload
     * @return array{h: string, p: string, t?: string}|null
     */
    private static function _decode(string $payload): ?array
    {
        $base64 = Craft::$app->getSecurity()->validateData($payload);
        if ($base64 === false) {
            return null;
        }

        $json = base64_decode(strtr($base64, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $data = Json::decodeIfJson($json);

        return is_array($data) && isset($data['h'], $data['p']) ? $data : null;
    }

}

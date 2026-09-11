<?php

namespace vaersaagod\bunnymate\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;

use vaersaagod\bunnymate\BunnyMate;
use vaersaagod\bunnymate\enums\VideoStatus;
use vaersaagod\bunnymate\models\VideoLibrary;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Receives Bunny Stream webhooks.
 *
 * Bunny posts here whenever a video's status changes. The endpoint is public by necessity,
 * so every payload is verified against the library's read-only API key before it's trusted.
 *
 * @see https://bunny.net/docs/stream/webhooks/
 *
 * @author Værsågod
 * @since 2.1.0
 */
class WebhookController extends Controller
{

    // Const Properties
    // =========================================================================

    /** The header Bunny puts the payload signature in */
    public const SIGNATURE_HEADER = 'X-BunnyStream-Signature';

    /** The header carrying the signature scheme version */
    public const VERSION_HEADER = 'X-BunnyStream-Signature-Version';

    /** The header carrying the signature algorithm */
    public const ALGORITHM_HEADER = 'X-BunnyStream-Signature-Algorithm';

    // Public Properties
    // =========================================================================

    /** @inheritdoc */
    public array|bool|int $allowAnonymous = true;

    /** @inheritdoc */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * Handles an incoming Bunny Stream webhook.
     *
     * @return Response
     * @throws BadRequestHttpException if the payload is malformed
     * @throws ForbiddenHttpException if the payload fails verification
     */
    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $rawBody = $request->getRawBody();

        $payload = Json::decodeIfJson($rawBody);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Malformed webhook payload');
        }

        $libraryId = $payload['VideoLibraryId'] ?? null;
        $videoGuid = $payload['VideoGuid'] ?? null;
        $statusCode = $payload['Status'] ?? null;

        if ($libraryId === null || $videoGuid === null || $statusCode === null) {
            throw new BadRequestHttpException('Incomplete webhook payload');
        }

        $library = BunnyMate::getInstance()->getStream()->getLibraryById($libraryId);
        if (!$library) {
            // Not a library this install knows about. Nothing to sync, but not an error either.
            Craft::info("Ignoring webhook for unconfigured library \"$libraryId\"", __METHOD__);
            return $this->asRaw('OK');
        }

        if (!$this->_verifySignature($library, $rawBody)) {
            Craft::warning("Rejected webhook for video \"$videoGuid\": invalid signature", __METHOD__);
            throw new ForbiddenHttpException('Invalid webhook signature');
        }

        $status = VideoStatus::tryFrom((int)$statusCode);
        if ($status === null) {
            Craft::warning("Ignoring webhook for video \"$videoGuid\": unknown status \"$statusCode\"", __METHOD__);
            return $this->asRaw('OK');
        }

        BunnyMate::getInstance()->getVideos()->applyWebhookStatus($videoGuid, $status);

        return $this->asRaw('OK');
    }

    // Private Methods
    // =========================================================================

    /**
     * Verifies a webhook payload's signature.
     *
     * Bunny signs the exact raw body with HMAC-SHA256, keyed on the library's read-only API
     * key. The body must be hashed as received: re-serializing the decoded JSON changes the
     * bytes and the signature will never match.
     *
     * @param VideoLibrary $library
     * @param string $rawBody
     * @return bool
     */
    private function _verifySignature(VideoLibrary $library, string $rawBody): bool
    {
        $headers = Craft::$app->getRequest()->getHeaders();
        $signature = $headers->get(self::SIGNATURE_HEADER);

        if (empty($signature)) {
            return false;
        }

        $version = $headers->get(self::VERSION_HEADER);
        if ($version !== null && $version !== 'v1') {
            Craft::warning("Unsupported webhook signature version \"$version\"", __METHOD__);
            return false;
        }

        $algorithm = $headers->get(self::ALGORITHM_HEADER);
        if ($algorithm !== null && strtolower($algorithm) !== 'hmac-sha256') {
            Craft::warning("Unsupported webhook signature algorithm \"$algorithm\"", __METHOD__);
            return false;
        }

        if (empty($library->readOnlyApiKey)) {
            Craft::warning("Cannot verify webhook for library \"$library->handle\": no read-only API key configured", __METHOD__);
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $library->readOnlyApiKey);

        return hash_equals($expected, $signature);
    }

}

<?php

namespace vaersaagod\bunnymate\assetpreviews;

use Craft;
use craft\base\AssetPreviewHandler;
use craft\helpers\Html;

use vaersaagod\bunnymate\BunnyMate;

use yii\base\NotSupportedException;

/**
 * Previews a Bunny Stream video using Bunny's own player.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class BunnyVideoPreview extends AssetPreviewHandler
{

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws NotSupportedException if the asset has no playable Bunny video
     */
    public function getPreviewHtml(array $variables = []): string
    {
        $video = BunnyMate::getInstance()->getVideos()->getVideoForAsset($this->asset);

        if (!$video || !$video->getIsReady()) {
            throw new NotSupportedException('Preview not supported.');
        }

        return Html::tag('div',
            Html::tag('iframe', '', [
                'src' => $video->getEmbedUrl(),
                'loading' => 'lazy',
                'title' => $this->asset->title,
                'allow' => 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen',
                'allowfullscreen' => true,
                'style' => 'position: absolute; inset: 0; width: 100%; height: 100%; border: 0;',
            ]),
            [
                'class' => 'bunnymate-video-preview',
                'style' => sprintf(
                    'position: relative; width: 100%%; aspect-ratio: %s; margin-inline: auto;',
                    $this->_aspectRatio($video->getWidth(), $video->getHeight()),
                ),
            ]
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a CSS aspect ratio for the video, falling back to 16/9 when its dimensions
     * aren't known yet.
     *
     * @param int|null $width
     * @param int|null $height
     * @return string
     */
    private function _aspectRatio(?int $width, ?int $height): string
    {
        if (!$width || !$height) {
            return '16 / 9';
        }
        return "$width / $height";
    }

}

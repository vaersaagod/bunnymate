<?php

namespace vaersaagod\bunnymate\records;

use craft\db\ActiveRecord;
use craft\records\Asset;

use vaersaagod\bunnymate\db\Table;

use yii\db\ActiveQueryInterface;

/**
 * Bunny Stream video record.
 *
 * @property int $id
 * @property int|null $assetId
 * @property string $library
 * @property string $videoGuid
 * @property int $status
 * @property string|null $metadata
 *
 * @author Værsågod
 * @since 2.1.0
 */
class Video extends ActiveRecord
{

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::VIDEOS;
    }

    // Public Methods
    // =========================================================================

    /**
     * Returns the video's asset.
     *
     * @return ActiveQueryInterface
     */
    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'assetId']);
    }

}

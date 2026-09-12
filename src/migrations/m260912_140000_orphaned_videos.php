<?php

namespace vaersaagod\bunnymate\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;

use vaersaagod\bunnymate\db\Table;

/**
 * Keeps a video's ID around when its asset is purged, so the video can be cleaned up.
 *
 * The foreign key used to cascade, which meant Craft's garbage collection took the row with
 * the asset and left the video stranded on Bunny with nothing left pointing at it.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class m260912_140000_orphaned_videos extends Migration
{

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->dropForeignKeyIfExists(Table::VIDEOS, ['assetId']);
        $this->alterColumn(Table::VIDEOS, 'assetId', $this->integer());
        $this->addForeignKey(null, Table::VIDEOS, ['assetId'], CraftTable::ASSETS, ['id'], 'SET NULL', null);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Rows whose asset is already gone can't satisfy a not-null cascade
        $this->delete(Table::VIDEOS, ['assetId' => null]);

        $this->dropForeignKeyIfExists(Table::VIDEOS, ['assetId']);
        $this->alterColumn(Table::VIDEOS, 'assetId', $this->integer()->notNull());
        $this->addForeignKey(null, Table::VIDEOS, ['assetId'], CraftTable::ASSETS, ['id'], 'CASCADE', null);

        return true;
    }

}
